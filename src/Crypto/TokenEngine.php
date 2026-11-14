<?php

declare(strict_types=1);

namespace Aegis\Captcha\Crypto;

use Aegis\Captcha\Config\AegisConfig;
use Aegis\Captcha\Storage\NonceStorageInterface;
use RuntimeException;
use InvalidArgumentException;

/**
 * Authenticated challenge-token generator and validator.
 * Employs AES-256-GCM with hardware-accelerated OpenSSL primitives.
 */
final class TokenEngine
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const IV_LENGTH = 12;
    private const AAD = 'XIVIG-AEGIS-TOKEN-V1';

    public function __construct(
        private readonly AegisConfig $config,
        private readonly NonceStorageInterface $nonceStorage
    ) {
    }

    /**
     * Issues an encrypted, time-decaying, client-bound challenge token.
     */
    public function issueChallengeToken(
        string $clientIp,
        string $userAgent,
        ?int $customDifficulty = null
    ): array {
        $difficulty = $customDifficulty ?? $this->config->powDifficulty;
        if ($difficulty < 1 || $difficulty > 5) {
            throw new InvalidArgumentException('Challenge difficulty must be between 1 and 5.');
        }
        $nonce = bin2hex(random_bytes(16));
        $timestamp = time();
        $salt = bin2hex(random_bytes(8));

        // Generate dynamic seed for client-side Proof-of-Work
        $challengeSeed = hash('sha256', "{$nonce}:{$timestamp}:{$salt}");

        $ipSignature = $this->config->bindIp ? $this->hashIpSubnet($clientIp) : '';
        $uaSignature = $this->config->bindUserAgent ? hash('sha256', $userAgent) : '';

        $payload = [
            'n' => $nonce,
            'ts' => $timestamp,
            'd' => $difficulty,
            's' => $challengeSeed,
            'ip' => $ipSignature,
            'ua' => $uaSignature
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $key = hash_hkdf('sha256', $this->config->secretKey, 32, 'xivig-aegis-token-v1');

        $ciphertext = openssl_encrypt(
            $json,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD,
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException("Aegis OpenSSL encryption error: " . openssl_error_string());
        }

        // Pack: IV (12) + TAG (16) + CIPHERTEXT
        $packed = $iv . $tag . $ciphertext;
        $token = self::base64UrlEncode($packed);

        return [
            'token' => $token,
            'seed' => $challengeSeed,
            'difficulty' => $difficulty,
            'expires_at' => $timestamp + $this->config->ttlSeconds
        ];
    }

    /**
     * Decrypts, verifies authenticity, checks client binding, and claims the nonce against replays.
     */
    public function verifyAndConsumeToken(
        string $token,
        string $clientIp,
        string $userAgent
    ): array {
        if ($token === '' || strlen($token) > 4096 || !preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            throw new InvalidArgumentException('Malformed challenge token.');
        }
        $raw = self::base64UrlDecode($token);
        if ($raw === false) {
            throw new InvalidArgumentException("Malformed Aegis challenge token encoding.");
        }

        $minLen = self::IV_LENGTH + self::TAG_LENGTH + 1;
        if (strlen($raw) < $minLen) {
            throw new InvalidArgumentException("Aegis challenge token payload is truncated or corrupted.");
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);

        $key = hash_hkdf('sha256', $this->config->secretKey, 32, 'xivig-aegis-token-v1');

        $decrypted = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::AAD
        );

        if ($decrypted === false) {
            throw new RuntimeException("Aegis cryptographic verification failed: invalid token signature or tampered ciphertext.");
        }

        $payload = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !isset($payload['n'], $payload['ts'], $payload['d'], $payload['s'])
            || !is_string($payload['n']) || !preg_match('/^[a-f0-9]{32}$/', $payload['n'])
            || !is_int($payload['ts']) || !is_int($payload['d']) || $payload['d'] < 1 || $payload['d'] > 5
            || !is_string($payload['s']) || !preg_match('/^[a-f0-9]{64}$/', $payload['s'])) {
            throw new InvalidArgumentException('Invalid challenge token claims.');
        }

        // 1. Check Token TTL
        $age = time() - $payload['ts'];
        if ($age < -5) {
            throw new RuntimeException("Clock skew anomaly: token issued in the future.");
        }
        if ($age > $this->config->ttlSeconds) {
            throw new RuntimeException("Aegis challenge token has expired (age: {$age}s, max: {$this->config->ttlSeconds}s).");
        }

        // 2. Validate IP Subnet Binding
        if ($this->config->bindIp && !empty($payload['ip'])) {
            $currentIpSig = $this->hashIpSubnet($clientIp);
            if (!hash_equals($payload['ip'], $currentIpSig)) {
                throw new RuntimeException("Aegis client binding violation: IP address mismatch.");
            }
        }

        // 3. Validate User-Agent Binding
        if ($this->config->bindUserAgent && !empty($payload['ua'])) {
            $currentUaSig = hash('sha256', $userAgent);
            if (!hash_equals($payload['ua'], $currentUaSig)) {
                throw new RuntimeException("Aegis client binding violation: User-Agent header altered.");
            }
        }

        // 4. Atomic Anti-Replay Nonce Claim
        $nonce = (string)$payload['n'];
        if (!$this->nonceStorage->claimNonce($nonce, $this->config->ttlSeconds)) {
            throw new RuntimeException("Aegis replay attack detected: challenge token has already been consumed.");
        }

        return $payload;
    }

    /**
     * Subnet masking to accommodate cellular/carrier IP shifting while blocking cross-network proxy relays.
     */
    private function hashIpSubnet(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // Mask IPv4 to /24 (e.g., 192.168.1.0)
            $parts = explode('.', $ip);
            $masked = "{$parts[0]}.{$parts[1]}.{$parts[2]}.0";
            return hash('sha256', $masked . $this->config->secretKey);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Mask IPv6 to /64
            $binary = inet_pton($ip);
            if ($binary !== false) {
                $maskedBinary = substr($binary, 0, 8) . str_repeat("\0", 8);
                return hash('sha256', $maskedBinary . $this->config->secretKey);
            }
        }

        return hash('sha256', $ip . $this->config->secretKey);
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string|false
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    }
}
