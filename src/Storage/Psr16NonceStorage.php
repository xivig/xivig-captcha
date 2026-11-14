<?php

declare(strict_types=1);

namespace Aegis\Captcha\Storage;

use RuntimeException;

/**
 * Atomic cache adapter for cache repositories that expose add(key, value, ttl).
 * PSR-16 alone does not define an atomic add operation; plain has()+set() is unsafe.
 */
final class Psr16NonceStorage implements NonceStorageInterface
{
    public function __construct(
        private readonly object $cache,
        private readonly string $prefix = 'aegis_nonce:'
    ) {
        if (!method_exists($this->cache, 'add')) {
            throw new RuntimeException('Aegis requires an atomic cache add() operation. PSR-16 alone is insufficient.');
        }
    }

    public function claimNonce(string $nonce, int $ttlSeconds): bool
    {
        $key = $this->prefix . hash('sha256', $nonce);
        return $this->cache->add($key, 1, max(1, $ttlSeconds)) === true;
    }

    public function hasNonce(string $nonce): bool
    {
        $key = $this->prefix . hash('sha256', $nonce);
        return method_exists($this->cache, 'has') && $this->cache->has($key);
    }
}
