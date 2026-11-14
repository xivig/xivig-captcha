<?php

declare(strict_types=1);

namespace Aegis\Captcha\Engine;

/**
 * @deprecated Client-side trajectories are attacker-controlled and are not used by the production risk scorer.
 * Retained temporarily for backwards compatibility; do not use as proof of humanity.
 */
final class TrajectoryAnalyzer
{
    /**
     * Evaluates cursor / touch movement points.
     *
     * @param list<array{x: int|float, y: int|float, t: int}> $points
     * @return array{score_bonus: float, score_penalty: float, entropy: float, flags: list<string>}
     */
    public static function analyze(array $points): array
    {
        $count = count($points);
        if ($count < 3) {
            return [
                'score_bonus' => 0.0,
                'score_penalty' => 0.20,
                'entropy' => 0.0,
                'flags' => ['INSUFFICIENT_INTERACTION_TELEMETRY']
            ];
        }

        $angles = [];
        $speeds = [];
        $flags = [];
        $straightLineCount = 0;

        for ($i = 1; $i < $count; $i++) {
            $dx = $points[$i]['x'] - $points[$i - 1]['x'];
            $dy = $points[$i]['y'] - $points[$i - 1]['y'];
            $dt = max(1, $points[$i]['t'] - $points[$i - 1]['t']);

            $distance = sqrt(($dx * $dx) + ($dy * $dy));
            $speed = $distance / $dt;
            $speeds[] = $speed;

            if ($i >= 2) {
                $prevDx = $points[$i - 1]['x'] - $points[$i - 2]['x'];
                $prevDy = $points[$i - 1]['y'] - $points[$i - 2]['y'];

                // Calculate turning angle
                $angle1 = atan2((float)$prevDy, (float)$prevDx);
                $angle2 = atan2((float)$dy, (float)$dx);
                $diff = abs($angle2 - $angle1);
                if ($diff > M_PI) {
                    $diff = (2 * M_PI) - $diff;
                }
                $angles[] = round($diff, 2);

                // Check for strictly straight line transitions (collinear points)
                if (abs($diff) < 0.001) {
                    $straightLineCount++;
                }
            }
        }

        // 1. Angular Entropy (Variety of curve changes)
        $uniqueAngles = count(array_unique($angles));
        $entropy = count($angles) > 0 ? ($uniqueAngles / count($angles)) : 0.0;

        $penalty = 0.0;
        $bonus = 0.0;

        // Check if movement was artificially linear (typical of basic bots or lerp interpolations)
        if (count($angles) > 5 && ($straightLineCount / count($angles)) > 0.85) {
            $penalty += 0.50;
            $flags[] = 'SYNTHETIC_COLLINEAR_TRAJECTORY';
        }

        // Natural human micro-jitter and curved movements
        if ($entropy >= 0.25) {
            $bonus += 0.20;
            $flags[] = 'HUMAN_NEUROMUSCULAR_JITTER_CONFIRMED';
        } elseif ($entropy < 0.10 && count($angles) > 5) {
            $penalty += 0.35;
            $flags[] = 'LOW_ENTROPY_TRAJECTORY';
        }

        // 2. Velocity Variance (Humans speed up and slow down; scripts have constant velocity)
        $speedVariance = self::calculateVariance($speeds);
        if ($speedVariance < 0.0001 && count($speeds) > 4) {
            $penalty += 0.40;
            $flags[] = 'ARTIFICIAL_CONSTANT_VELOCITY';
        }

        return [
            'score_bonus' => min(0.30, $bonus),
            'score_penalty' => min(0.80, $penalty),
            'entropy' => round($entropy, 3),
            'flags' => $flags
        ];
    }

    private static function calculateVariance(array $values): float
    {
        $n = count($values);
        if ($n <= 1) return 0.0;
        $mean = array_sum($values) / $n;
        $sumSq = 0.0;
        foreach ($values as $v) {
            $sumSq += ($v - $mean) ** 2;
        }
        return $sumSq / ($n - 1);
    }
}
