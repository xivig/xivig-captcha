<?php

declare(strict_types=1);

namespace Aegis\Captcha\Engine;

/**
 * Conservative analysis of client-reported automation hints.
 * All telemetry is untrusted and must never be treated as proof of humanity.
 */
final class EnvironmentDetector
{
    /** @return array{score_penalty: float, flags: list<string>} */
    public static function analyzeEnvironment(array $telemetry): array
    {
        $penalty = 0.0;
        $flags = [];

        // These values are client-controlled. They are risk hints, not attestations.
        if (($telemetry['webdriver'] ?? false) === true) {
            $penalty += 0.22;
            $flags[] = 'WEBDRIVER_SIGNAL';
        }
        if (($telemetry['automationArtifacts'] ?? false) === true || ($telemetry['isAutomated'] ?? false) === true) {
            $penalty += 0.22;
            $flags[] = 'AUTOMATION_ARTIFACT_SIGNAL';
        }

        return ['score_penalty' => min(0.70, $penalty), 'flags' => $flags];
    }

    /** No built-in IP intelligence is shipped; integrate a maintained ASN feed externally. */
    public static function isDatacenterIp(string $ip): bool
    {
        return false;
    }
}
