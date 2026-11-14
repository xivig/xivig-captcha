<?php

declare(strict_types=1);

namespace Aegis\Captcha\Tests;

use Aegis\Captcha\Config\AegisConfig;
use Aegis\Captcha\Crypto\ProofOfWork;
use Aegis\Captcha\Engine\RiskAnalyzer;
use PHPUnit\Framework\TestCase;

final class RiskAnalyzerTest extends TestCase
{
    private AegisConfig $config;

    protected function setUp(): void
    {
        $this->config = new AegisConfig(
            secretKey: '12345678901234567890123456789012'
        );
    }

    public function testAuthenticHumanInteractionScoresHigh(): void
    {
        $seed = 'sample-seed-abc-123';
        $difficulty = 3;
        $nonce = ProofOfWork::solve($seed, $difficulty);

        $humanTelemetry = [
            'webdriver' => false,
            'isAutomated' => false,
            'webglVendor' => 'Google Inc. (NVIDIA)',
            'webglRenderer' => 'ANGLE (NVIDIA GeForce RTX 3070)',
            'canvasFingerprint' => 'canvas-data-valid-hash-sample-342',
            'screenWidth' => 1920,
            'screenHeight' => 1080,
            'durationMs' => 2400,
            'mouseEvents' => [
                ['x' => 100, 'y' => 150, 't' => 100],
                ['x' => 112, 'y' => 155, 't' => 250],
                ['x' => 135, 'y' => 170, 't' => 450],
                ['x' => 160, 'y' => 195, 't' => 700],
                ['x' => 210, 'y' => 240, 't' => 1100],
                ['x' => 280, 'y' => 290, 't' => 1500],
            ]
        ];

        $result = RiskAnalyzer::evaluate($humanTelemetry, $nonce, $seed, $difficulty, $this->config);

        $this->assertTrue($result['is_human']);
        $this->assertGreaterThanOrEqual(0.70, $result['score']);
        $this->assertContains('PROOF_OF_WORK_VERIFIED', $result['flags']);
    }

    public function testHeadlessBotWithWebdriverIsBlocked(): void
    {
        $seed = 'sample-seed-abc-123';
        $difficulty = 3;
        $nonce = ProofOfWork::solve($seed, $difficulty);

        $botTelemetry = [
            'webdriver' => true,
            'isAutomated' => true,
            'webglVendor' => 'Google SwiftShader',
            'webglRenderer' => 'SwiftShader Software Renderer',
            'screenWidth' => 800,
            'screenHeight' => 600,
            'durationMs' => 150, // Sub-human speed
            'mouseEvents' => []
        ];

        $result = RiskAnalyzer::evaluate($botTelemetry, $nonce, $seed, $difficulty, $this->config);

        $this->assertFalse($result['is_human']);
        $this->assertLessThan(0.65, $result['score']);
        $this->assertContains('WEBDRIVER_SIGNAL', $result['flags']);
    }
}
