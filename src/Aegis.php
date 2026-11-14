<?php

declare(strict_types=1);

namespace Aegis\Captcha;

use Aegis\Captcha\Challenge\AudioChallenge;
use Aegis\Captcha\Challenge\InteractiveChallenge;
use Aegis\Captcha\Config\AegisConfig;
use Aegis\Captcha\Crypto\TokenEngine;
use Aegis\Captcha\Engine\AdaptiveDifficulty;
use Aegis\Captcha\Engine\RiskAnalyzer;
use Aegis\Captcha\Http\AegisResponse;
use Aegis\Captcha\Security\RateLimiter;
use Aegis\Captcha\Storage\FileNonceStorage;
use Aegis\Captcha\Storage\NonceStorageInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Main Aegis Anti-Bot and Anti-Abuse Defense Engine v2.1.
 */
final class Aegis
{
    private const MAX_PAYLOAD_LENGTH = 16384;
    private static ?self $instance = null;
    private TokenEngine $tokenEngine;
    private RateLimiter $rateLimiter;
    private InteractiveChallenge $interactiveChallenge;
    private AudioChallenge $audioChallenge;

    public function __construct(
        private readonly AegisConfig $config,
        private readonly ?NonceStorageInterface $nonceStorage = null,
        ?RateLimiter $rateLimiter = null
    ) {
        $storage = $this->nonceStorage ?? new FileNonceStorage();
        $this->tokenEngine = new TokenEngine($this->config, $storage);
        $this->rateLimiter = $rateLimiter ?? new RateLimiter(maxAttempts: 30, windowSeconds: 60);
        $this->interactiveChallenge = new InteractiveChallenge($this->config->secretKey, $storage);
        $this->audioChallenge = new AudioChallenge($this->config->secretKey, $storage);
    }

    public static function init(AegisConfig $config, ?NonceStorageInterface $storage = null): self
    {
        self::$instance = new self($config, $storage);
        return self::$instance;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new InvalidArgumentException("Aegis has not been initialized. Call Aegis::init(\$config) first.");
        }
        return self::$instance;
    }

    /**
     * Generates a new cryptographic challenge token to send to the client.
     */
    public function createChallenge(
        string $clientIp,
        string $userAgent,
        ?int $difficulty = null
    ): array {
        // 1. Check Rate Limiter
        $rateCheck = $this->rateLimiter->check($clientIp);
        if (!$rateCheck['allowed']) {
            return [
                'error' => 'RATE_LIMIT_EXCEEDED',
                'retry_after' => $rateCheck['reset_in']
            ];
        }

        // 2. Compute Adaptive Difficulty if not explicitly overridden
        $effectiveDifficulty = $difficulty ?? AdaptiveDifficulty::calculate(
            $clientIp,
            $this->config->powDifficulty,
            maxDifficulty: 5
        );

        $challenge = $this->tokenEngine->issueChallengeToken($clientIp, $userAgent, $effectiveDifficulty);

        // 3. Attach Interactive Step-Up visual assets if in interactive mode (NEVER leaks targetX)
        if ($this->config->mode === AegisConfig::MODE_INTERACTIVE) {
            $puzzle = $this->interactiveChallenge->generateSliderPuzzle();
            $challenge['mode'] = 'interactive';
            $challenge['step_up'] = true;
            $challenge['bg_image'] = $puzzle['bg_image'];
            $challenge['piece_image'] = $puzzle['piece_image'];
            $challenge['piece_y'] = $puzzle['piece_y'];
            $challenge['puzzle_token'] = $puzzle['token'];
        } else {
            $challenge['mode'] = $this->config->mode;
        }

        return $challenge;
    }

    /**
     * Verifies the client's submitted encrypted payload against all security vectors.
     */
    public function verify(
        string $base64Payload,
        string $clientIp,
        string $userAgent
    ): AegisResponse {
        $payloadLen = strlen($base64Payload);
        if ($payloadLen === 0) {
            return AegisResponse::bot(0.0, ['EMPTY_PAYLOAD']);
        }

        if ($payloadLen > self::MAX_PAYLOAD_LENGTH) {
            return AegisResponse::bot(0.0, ['PAYLOAD_TOO_LARGE']);
        }

        try {
            $json = base64_decode($base64Payload, true);
            if ($json === false) {
                return AegisResponse::bot(0.0, ['CORRUPTED_BASE64_PAYLOAD']);
            }

            $submitted = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($submitted) || !isset($submitted['token'], $submitted['powNonce'])
                || !is_string($submitted['token']) || !is_scalar($submitted['powNonce'])) {
                return AegisResponse::bot(0.0, ['INVALID_PAYLOAD_SHAPE']);
            }

            $tokenString = $submitted['token'];
            $powNonce = (string)$submitted['powNonce'];
            $telemetry = is_array($submitted['telemetry'] ?? null) ? $submitted['telemetry'] : [];

            if (count($telemetry) > 20) {
                return AegisResponse::bot(0.0, ['INVALID_TELEMETRY']);
            }

            // 1. Decrypt, verify signature, validate TTL and atomic single-use nonce
            $tokenData = $this->tokenEngine->verifyAndConsumeToken($tokenString, $clientIp, $userAgent);

            // 2. Check if interactive step-up slider answer is submitted
            if (isset($submitted['sliderX'], $submitted['puzzleToken'])) {
                $sliderX = (int)$submitted['sliderX'];
                $puzzleToken = (string)$submitted['puzzleToken'];

                if ($this->interactiveChallenge->verifySliderSolution($puzzleToken, $sliderX)) {
                    return AegisResponse::human(1.0, ['INTERACTIVE_PUZZLE_VERIFIED']);
                }
                return AegisResponse::bot(0.0, ['INTERACTIVE_PUZZLE_FAILED']);
            }

            // 3. Run multi-vector behavioral and environment risk analysis
            $analysis = RiskAnalyzer::evaluate(
                $telemetry,
                $powNonce,
                $tokenData['s'],
                (int)$tokenData['d'],
                $this->config
            );

            return new AegisResponse(
                isHuman: $analysis['is_human'],
                score: $analysis['score'],
                flags: $analysis['flags'],
                details: $analysis['details']
            );

        } catch (Throwable) {
            return AegisResponse::bot(0.0, ['VERIFICATION_FAILED']);
        }
    }

    /**
     * Creates an accessible audio challenge token and audio stream.
     * Plaintext digits are NEVER returned.
     */
    public function createAudioChallenge(): array
    {
        return $this->audioChallenge->generate();
    }

    /**
     * Verifies an accessible audio challenge response with atomic single-use replay protection.
     */
    public function verifyAudioChallenge(string $token, string $digits): bool
    {
        return $this->audioChallenge->verify($token, $digits);
    }

    public function renderWidget(
        string $containerId = 'aegis-container',
        string $workerUrl = '/vendor/aegis/js/aegis-worker.js'
    ): string {
        return sprintf(
            '<div id="%s" class="aegis-guard-widget" data-endpoint="%s" data-input="%s" data-worker="%s"></div>',
            htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->config->challengeEndpoint, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->config->payloadField, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($workerUrl, ENT_QUOTES, 'UTF-8')
        );
    }

    public function renderScript(string $assetUrl = '/vendor/aegis/js/aegis.js'): string
    {
        return sprintf('<script src="%s" defer></script>', htmlspecialchars($assetUrl, ENT_QUOTES, 'UTF-8'));
    }

    public function renderStyle(string $assetUrl = '/vendor/aegis/css/aegis.css'): string
    {
        return sprintf('<link rel="stylesheet" href="%s">', htmlspecialchars($assetUrl, ENT_QUOTES, 'UTF-8'));
    }

    public function getConfig(): AegisConfig
    {
        return $this->config;
    }
}
