<?php

declare(strict_types=1);

namespace Aegis\Captcha\Tests;

use Aegis\Captcha\Aegis;
use Aegis\Captcha\Config\AegisConfig;
use Aegis\Captcha\Crypto\ProofOfWork;
use Aegis\Captcha\Storage\MemoryNonceStorage;
use PHPUnit\Framework\TestCase;

final class AegisIntegrationTest extends TestCase
{
    private Aegis $aegis;

    protected function setUp(): void
    {
        $config = new AegisConfig(
            secretKey: 'ultra-secret-key-32-chars-long!!',
            powDifficulty: 3
        );
        $storage = new MemoryNonceStorage();
        $this->aegis = new Aegis($config, $storage);
    }

    public function testEndToEndVerificationFlow(): void
    {
        $ip = '10.0.0.5';
        $ua = 'Mozilla/5.0';

        // 1. Server issues challenge
        $challenge = $this->aegis->createChallenge($ip, $ua);
        $this->assertArrayHasKey('token', $challenge);
        $this->assertArrayHasKey('seed', $challenge);

        // 2. Client solves PoW
        $nonce = ProofOfWork::solve($challenge['seed'], $challenge['difficulty']);

        // 3. Client packs payload
        $clientPayload = base64_encode(json_encode([
            'token' => $challenge['token'],
            'powNonce' => $nonce,
            'telemetry' => [
                'webdriver' => false,
                'isAutomated' => false,
                'webglVendor' => 'Apple Inc.',
                'webglRenderer' => 'Apple M2 Pro',
                'canvasFingerprint' => 'valid-canvas-hash-test',
                'screenWidth' => 1728,
                'screenHeight' => 1117,
                'durationMs' => 3200,
                'mouseEvents' => [
                    ['x' => 10, 'y' => 20, 't' => 50],
                    ['x' => 45, 'y' => 60, 't' => 200],
                    ['x' => 90, 'y' => 110, 't' => 600],
                    ['x' => 140, 'y' => 150, 't' => 1200],
                    ['x' => 200, 'y' => 210, 't' => 2000],
                ]
            ]
        ]));

        // 4. Server verifies submission
        $response = $this->aegis->verify($clientPayload, $ip, $ua);

        $this->assertTrue($response->isHuman);
        $this->assertGreaterThanOrEqual(0.70, $response->score);
    }
}
