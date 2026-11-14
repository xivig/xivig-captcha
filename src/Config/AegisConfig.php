<?php

declare(strict_types=1);

namespace Aegis\Captcha\Config;

use InvalidArgumentException;

/**
 * Immutable configuration object for the Aegis Anti-Bot Framework.
 */
final class AegisConfig
{
    public const MODE_INVISIBLE = 'invisible';
    public const MODE_INTERACTIVE = 'interactive';
    public const MODE_HYBRID = 'hybrid';

    public function __construct(
        public readonly string $secretKey,
        public readonly string $mode = self::MODE_HYBRID,
        public readonly int $powDifficulty = 4,
        public readonly int $ttlSeconds = 180,
        public readonly float $riskThreshold = 0.65,
        public readonly bool $bindIp = true,
        public readonly bool $bindUserAgent = true,
        public readonly bool $blockDatacenterIps = false,
        public readonly array $ipWhitelist = [],
        public readonly string $challengeEndpoint = '/aegis/challenge',
        public readonly string $payloadField = 'aegis_payload'
    ) {
        if (strlen($this->secretKey) < 32) {
            throw new InvalidArgumentException(
                "Aegis secret key must be at least 32 characters long. Received " . strlen($this->secretKey) . " characters."
            );
        }

        if (!in_array($this->mode, [self::MODE_INVISIBLE, self::MODE_INTERACTIVE, self::MODE_HYBRID], true)) {
            throw new InvalidArgumentException('Invalid Aegis mode.');
        }

        if ($this->ttlSeconds < 30 || $this->ttlSeconds > 900) {
            throw new InvalidArgumentException('Challenge TTL must be between 30 and 900 seconds.');
        }

        if ($this->powDifficulty < 1 || $this->powDifficulty > 5) {
            throw new InvalidArgumentException(
                "Proof-of-work difficulty must be between 1 and 5. Recommended: 3 to 4; test on low-end mobile devices."
            );
        }

        if ($this->payloadField === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_\[\]-]*$/', $this->payloadField)) {
            throw new InvalidArgumentException('Invalid payload field name.');
        }

        if ($this->challengeEndpoint === '' || $this->challengeEndpoint[0] !== '/' || str_starts_with($this->challengeEndpoint, '//') || str_contains($this->challengeEndpoint, '?') || str_contains($this->challengeEndpoint, '#') || str_contains($this->challengeEndpoint, '\\')) {
            throw new InvalidArgumentException('Challenge endpoint must be an absolute local path.');
        }

        if ($this->riskThreshold < 0.0 || $this->riskThreshold > 1.0) {
            throw new InvalidArgumentException("Risk threshold must be between 0.0 and 1.0.");
        }
    }

    /**
     * Create an instance from array configurations.
     */
    public static function fromArray(array $options): self
    {
        return new self(
            secretKey: $options['secret_key'] ?? throw new InvalidArgumentException("Missing 'secret_key' in Aegis configuration."),
            mode: $options['mode'] ?? self::MODE_HYBRID,
            powDifficulty: (int)($options['pow_difficulty'] ?? 4),
            ttlSeconds: (int)($options['ttl_seconds'] ?? 180),
            riskThreshold: (float)($options['risk_threshold'] ?? 0.65),
            bindIp: (bool)($options['bind_ip'] ?? true),
            bindUserAgent: (bool)($options['bind_user_agent'] ?? true),
            blockDatacenterIps: (bool)($options['block_datacenter_ips'] ?? false),
            ipWhitelist: (array)($options['ip_whitelist'] ?? []),
            challengeEndpoint: (string)($options['challenge_endpoint'] ?? '/aegis/challenge'),
            payloadField: (string)($options['payload_field'] ?? 'aegis_payload')
        );
    }
}
