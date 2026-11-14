<?php

declare(strict_types=1);

namespace Aegis\Captcha\Storage;

/**
 * Contract for anti-replay token storage.
 */
interface NonceStorageInterface
{
    /**
     * Attempts to claim a nonce.
     * Returns true if the nonce was newly stored (valid).
     * Returns false if the nonce has already been used (replay attack).
     */
    public function claimNonce(string $nonce, int $ttlSeconds): bool;

    /**
     * Checks if a nonce exists without claiming it.
     */
    public function hasNonce(string $nonce): bool;
}
