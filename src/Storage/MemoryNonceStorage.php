<?php

declare(strict_types=1);

namespace Aegis\Captcha\Storage;

/**
 * In-memory storage for unit tests and single-request scopes.
 */
final class MemoryNonceStorage implements NonceStorageInterface
{
    private array $nonces = [];

    public function claimNonce(string $nonce, int $ttlSeconds): bool
    {
        $now = time();
        if (isset($this->nonces[$nonce]) && $this->nonces[$nonce] > $now) {
            return false;
        }

        $this->nonces[$nonce] = $now + $ttlSeconds;
        return true;
    }

    public function hasNonce(string $nonce): bool
    {
        return isset($this->nonces[$nonce]) && $this->nonces[$nonce] > time();
    }

    public function clear(): void
    {
        $this->nonces = [];
    }
}
