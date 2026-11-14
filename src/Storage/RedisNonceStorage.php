<?php

declare(strict_types=1);

namespace Aegis\Captcha\Storage;

use RuntimeException;
use InvalidArgumentException;

/**
 * High-performance, native Redis single-use replay protection.
 * Executes atomic `SET key 1 NX EX ttl` directly on Redis / RedisCluster / Predis.
 */
final class RedisNonceStorage implements NonceStorageInterface
{
    /**
     * @param object $client Must be an instance of \Redis, \RedisCluster, or \Predis\ClientInterface
     */
    public function __construct(
        private readonly object $client,
        private readonly string $prefix = 'aegis:nonce:'
    ) {
        $isValidClient = $this->client instanceof \Redis
            || $this->client instanceof \RedisCluster
            || (is_object($this->client) && is_subclass_of($this->client, 'Predis\ClientInterface'))
            || method_exists($this->client, 'set');

        if (!$isValidClient) {
            throw new InvalidArgumentException(
                'RedisNonceStorage requires an instance of \Redis, \RedisCluster, or \Predis\ClientInterface.'
            );
        }
    }

    /**
     * Atomically claims a single-use nonce via Redis SET NX EX.
     */
    public function claimNonce(string $nonce, int $ttlSeconds): bool
    {
        if ($nonce === '' || $ttlSeconds < 1) {
            return false;
        }

        $key = $this->prefix . hash('sha256', $nonce);
        $ttl = max(1, $ttlSeconds);

        try {
            // 1. PhpRedis / RedisCluster native SET with options array ['NX', 'EX' => $ttl]
            if ($this->client instanceof \Redis || $this->client instanceof \RedisCluster) {
                $result = $this->client->set($key, '1', ['NX', 'EX' => $ttl]);
                return $result === true || $result === 'OK';
            }

            // 2. Generic client: try options array first
            try {
                $result = $this->client->set($key, '1', ['NX', 'EX' => $ttl]);
                if ($result === true || $result === 'OK') {
                    return true;
                }
                if ($result === false || $result === null) {
                    return false;
                }
            } catch (\TypeError|\ArgumentCountError) {
                // Predis positional args: set(key, val, 'EX', ttl, 'NX')
                $result = $this->client->set($key, '1', 'EX', $ttl, 'NX');
                return $result === true || $result === 'OK' || (is_object($result) && method_exists($result, 'getPayload') && $result->getPayload() === 'OK');
            }

            return false;
        } catch (\Throwable $e) {
            throw new RuntimeException("Redis nonce storage error: " . $e->getMessage(), 0, $e);
        }
    }

    public function hasNonce(string $nonce): bool
    {
        if ($nonce === '') {
            return false;
        }

        $key = $this->prefix . hash('sha256', $nonce);

        try {
            if (method_exists($this->client, 'exists')) {
                return (bool)$this->client->exists($key);
            }
            if (method_exists($this->client, 'get')) {
                return $this->client->get($key) !== false && $this->client->get($key) !== null;
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }
}
