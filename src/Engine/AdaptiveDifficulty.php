<?php

declare(strict_types=1);

namespace Aegis\Captcha\Engine;

use Aegis\Captcha\Security\RateLimiter;

/**
 * Adaptive Difficulty Engine.
 * Dynamically scales Proof-of-Work friction based on client request velocity.
 */
final class AdaptiveDifficulty
{
    private static array $ipRequestCounts = [];

    /**
     * Computes the adaptive challenge difficulty for a given client IP.
     *
     * @param string $clientIp Client IP address
     * @param int $baseDifficulty Base configuration difficulty (1 to 5, default 3)
     * @param int $maxDifficulty Maximum ceiling (default 5)
     */
    public static function calculate(
        string $clientIp,
        int $baseDifficulty = 3,
        int $maxDifficulty = 5
    ): int {
        $subnet = self::hashIpSubnet($clientIp);
        $now = time();

        if (!isset(self::$ipRequestCounts[$subnet])) {
            self::$ipRequestCounts[$subnet] = [];
        }

        // Keep timestamps from the last 60 seconds
        self::$ipRequestCounts[$subnet] = array_filter(
            self::$ipRequestCounts[$subnet],
            static fn(int $ts) => $ts > ($now - 60)
        );

        $recentCount = count(self::$ipRequestCounts[$subnet]);
        self::$ipRequestCounts[$subnet][] = $now;

        $computedDifficulty = $baseDifficulty;

        if ($recentCount >= 10) {
            $computedDifficulty += 2;
        } elseif ($recentCount >= 4) {
            $computedDifficulty += 1;
        }

        return max(1, min($maxDifficulty, $computedDifficulty));
    }

    private static function hashIpSubnet(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0";
        }
        return $ip;
    }

    public static function reset(): void
    {
        self::$ipRequestCounts = [];
    }
}
