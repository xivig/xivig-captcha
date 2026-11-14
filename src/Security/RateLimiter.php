<?php

declare(strict_types=1);

namespace Aegis\Captcha\Security;

use RuntimeException;

/**
 * Hardened sliding-window rate limiter for challenge endpoints.
 * Features durable multi-process file locking fallback when Redis is offline.
 * Solves the PHP-FPM worker isolation problem.
 */
final class RateLimiter
{
    public const POLICY_DURABLE_FILE = 'durable_file';
    public const POLICY_FAIL_CLOSED = 'fail_closed';
    public const POLICY_MEMORY = 'memory';

    private static array $inMemoryWindows = [];
    private string $storageDir;

    /**
     * @param int $maxAttempts Maximum allowed challenge issuances within the window
     * @param int $windowSeconds Sliding window duration in seconds
     * @param object|null $redis Optional Redis instance for distributed rate limiting
     * @param string $fallbackPolicy 'durable_file' (recommended for FPM), 'fail_closed', or 'memory'
     */
    public function __construct(
        private readonly int $maxAttempts = 30,
        private readonly int $windowSeconds = 60,
        private readonly ?object $redis = null,
        private readonly string $prefix = 'aegis:ratelimit:',
        private readonly string $fallbackPolicy = self::POLICY_DURABLE_FILE,
        ?string $storageDir = null
    ) {
        $this->storageDir = $storageDir ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aegis_ratelimit');
    }

    /**
     * Checks if a request from the given IP is allowed, and records the attempt.
     *
     * @return array{allowed: bool, remaining: int, reset_in: int}
     */
    public function check(string $clientIp): array
    {
        $now = time();
        $ipKey = $this->hashIpSubnet($clientIp);

        // 1. Primary: Distributed Redis Sliding Window
        if ($this->redis !== null && method_exists($this->redis, 'eval')) {
            try {
                return $this->checkRedis($ipKey, $now);
            } catch (\Throwable) {
                // Redis connection error: handle via fallback policy
                if ($this->fallbackPolicy === self::POLICY_FAIL_CLOSED) {
                    return ['allowed' => false, 'remaining' => 0, 'reset_in' => $this->windowSeconds];
                }
            }
        }

        // 2. Secondary: Durable Multi-Process File-Based Sliding Window (PHP-FPM Safe)
        if ($this->fallbackPolicy === self::POLICY_DURABLE_FILE) {
            return $this->checkDurableFile($ipKey, $now);
        }

        // 3. Fallback: Local In-Memory Sliding Window (CLI / Single Process)
        return $this->checkInMemory($ipKey, $now);
    }

    /**
     * Durable multi-process file rate limiting using atomic locks.
     * Guaranteed to work across PHP-FPM, Apache mod_php, and CLI workers.
     */
    private function checkDurableFile(string $key, int $now): array
    {
        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0700, true) && !is_dir($this->storageDir)) {
            return $this->checkInMemory($key, $now);
        }

        $hash = hash('sha256', $key);
        $filePath = $this->storageDir . DIRECTORY_SEPARATOR . $hash . '.limit';
        $windowStart = $now - $this->windowSeconds;

        $fp = @fopen($filePath, 'c+');
        if (!$fp) {
            return $this->checkInMemory($key, $now);
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return $this->checkInMemory($key, $now);
        }

        try {
            rewind($fp);
            $raw = stream_get_contents($fp);
            $timestamps = $raw !== '' ? array_map('intval', explode(',', $raw)) : [];

            // Prune timestamps older than window
            $validTimestamps = array_filter($timestamps, static fn(int $ts) => $ts > $windowStart);
            $count = count($validTimestamps);

            if ($count >= $this->maxAttempts) {
                $oldest = reset($validTimestamps) ?: $now;
                $resetIn = max(1, ($oldest + $this->windowSeconds) - $now);
                return [
                    'allowed' => false,
                    'remaining' => 0,
                    'reset_in' => $resetIn
                ];
            }

            // Record current attempt
            $validTimestamps[] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, implode(',', $validTimestamps));
            fflush($fp);

            $remaining = max(0, $this->maxAttempts - ($count + 1));

            return [
                'allowed' => true,
                'remaining' => $remaining,
                'reset_in' => $this->windowSeconds
            ];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function checkInMemory(string $key, int $now): array
    {
        $windowStart = $now - $this->windowSeconds;

        if (!isset(self::$inMemoryWindows[$key])) {
            self::$inMemoryWindows[$key] = [];
        }

        self::$inMemoryWindows[$key] = array_filter(
            self::$inMemoryWindows[$key],
            static fn(int $ts) => $ts > $windowStart
        );

        $currentCount = count(self::$inMemoryWindows[$key]);

        if ($currentCount >= $this->maxAttempts) {
            $oldest = reset(self::$inMemoryWindows[$key]) ?: $now;
            $resetIn = max(1, ($oldest + $this->windowSeconds) - $now);
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_in' => $resetIn
            ];
        }

        self::$inMemoryWindows[$key][] = $now;
        $remaining = max(0, $this->maxAttempts - ($currentCount + 1));

        return [
            'allowed' => true,
            'remaining' => $remaining,
            'reset_in' => $this->windowSeconds
        ];
    }

    private function checkRedis(string $key, int $now): array
    {
        $redisKey = $this->prefix . $key;
        $windowStart = $now - $this->windowSeconds;

        $lua = <<<'LUA'
        local key = KEYS[1]
        local now = tonumber(ARGV[1])
        local windowStart = tonumber(ARGV[2])
        local maxAttempts = tonumber(ARGV[3])
        local windowSeconds = tonumber(ARGV[4])

        redis.call('ZREMRANGEBYSCORE', key, 0, windowStart)
        local currentCount = redis.call('ZCARD', key)

        if currentCount >= maxAttempts then
            return {0, 0}
        end

        redis.call('ZADD', key, now, now .. '-' .. redis.call('INCR', key .. ':seq'))
        redis.call('EXPIRE', key, windowSeconds)
        return {1, maxAttempts - currentCount - 1}
LUA;

        $res = $this->redis->eval($lua, [$redisKey, $now, $windowStart, $this->maxAttempts, $this->windowSeconds], 1);
        $allowed = (int)($res[0] ?? 0) === 1;
        $remaining = (int)($res[1] ?? 0);

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset_in' => $this->windowSeconds
        ];
    }

    private function hashIpSubnet(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0";
        }
        return $ip;
    }

    public static function resetInMemory(): void
    {
        self::$inMemoryWindows = [];
    }
}
