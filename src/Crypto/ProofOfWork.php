<?php

declare(strict_types=1);

namespace Aegis\Captcha\Crypto;

/**
 * Bounded SHA-256 proof-of-work verification. This is computational friction, not bot-proofing.
 */
final class ProofOfWork
{
    /**
     * Verifies that the client solved the cryptographic challenge.
     * Complexity is O(1) on server (~0.002 milliseconds).
     */
    public static function verify(string $seed, string|int $nonce, int $difficulty): bool
    {
        if ($difficulty < 1 || $difficulty > 5 || !preg_match('/^(0|[1-9][0-9]{0,15})$/', (string)$nonce)) {
            return false;
        }

        $input = "{$seed}:{$nonce}";
        $hash = hash('sha256', $input);

        $targetPrefix = str_repeat('0', $difficulty);
        return str_starts_with($hash, $targetPrefix);
    }

    /**
     * Helper to solve the challenge on PHP side (primarily for testing and benchmarks).
     */
    public static function solve(string $seed, int $difficulty, int $maxIterations = 5_000_000): string|int|null
    {
        if ($difficulty < 1 || $difficulty > 5 || $maxIterations < 1) {
            return null;
        }
        $targetPrefix = str_repeat('0', $difficulty);
        for ($nonce = 0; $nonce < $maxIterations; $nonce++) {
            $hash = hash('sha256', "{$seed}:{$nonce}");
            if (str_starts_with($hash, $targetPrefix)) {
                return $nonce;
            }
        }
        return null;
    }
}
