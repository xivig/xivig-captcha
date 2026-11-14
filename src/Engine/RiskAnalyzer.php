<?php

declare(strict_types=1);

namespace Aegis\Captcha\Engine;

use Aegis\Captcha\Config\AegisConfig;
use Aegis\Captcha\Crypto\ProofOfWork;

/**
 * Conservative risk scoring. A valid challenge proves work was performed,
 * not that a person solved it. Browser telemetry is always attacker-controlled.
 */
final class RiskAnalyzer
{
    public static function evaluate(
        array $telemetry,
        string|int $powNonce,
        string $expectedSeed,
        int $expectedDifficulty,
        AegisConfig $config
    ): array {
        if (!ProofOfWork::verify($expectedSeed, $powNonce, $expectedDifficulty)) {
            return [
                'is_human' => false,
                'score' => 0.0,
                'flags' => ['INVALID_PROOF_OF_WORK'],
                'pow_valid' => false,
                'details' => [],
            ];
        }

        $env = EnvironmentDetector::analyzeEnvironment($telemetry);
        $score = max(0.0, min(1.0, 0.85 - $env['score_penalty']));
        $flags = array_merge(['PROOF_OF_WORK_VERIFIED'], $env['flags']);

        // Timing is weak, spoofable evidence. Only flag extreme values; never demand pointer input.
        $duration = filter_var($telemetry['durationMs'] ?? null, FILTER_VALIDATE_INT);
        if ($duration !== false && $duration !== null && ($duration < 100 || $duration > 900000)) {
            $flags[] = 'UNUSUAL_CLIENT_TIMING';
            $score = max(0.0, $score - 0.05);
        }

        $score = round($score, 2);
        return [
            'is_human' => $score >= $config->riskThreshold,
            'score' => $score,
            'flags' => $flags,
            'pow_valid' => true,
            'details' => [
                'threshold_used' => $config->riskThreshold,
                'note' => 'Score is a risk heuristic, not proof of human identity.',
            ],
        ];
    }
}
