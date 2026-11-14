<?php

declare(strict_types=1);

namespace Aegis\Captcha\Tests;

use Aegis\Captcha\Config\AegisConfig;
use Aegis\Captcha\Crypto\TokenEngine;
use Aegis\Captcha\Storage\MemoryNonceStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TokenEngineTest extends TestCase
{
    private AegisConfig $config;
    private MemoryNonceStorage $storage;
    private TokenEngine $engine;

    protected function setUp(): void
    {
        $this->config = new AegisConfig(
            secretKey: '12345678901234567890123456789012', // 32 chars
            ttlSeconds: 60
        );
        $this->storage = new MemoryNonceStorage();
        $this->engine = new TokenEngine($this->config, $this->storage);
    }

    public function testIssueAndVerifyToken(): void
    {
        $ip = '192.168.1.100';
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';

        $challenge = $this->engine->issueChallengeToken($ip, $ua, 4);
        $this->assertNotEmpty($challenge['token']);
        $this->assertEquals(4, $challenge['difficulty']);

        $verified = $this->engine->verifyAndConsumeToken($challenge['token'], $ip, $ua);
        $this->assertEquals($challenge['seed'], $verified['s']);
    }

    public function testPreventsReplayAttack(): void
    {
        $ip = '192.168.1.100';
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';

        $challenge = $this->engine->issueChallengeToken($ip, $ua);

        // First verification should succeed
        $this->engine->verifyAndConsumeToken($challenge['token'], $ip, $ua);

        // Second verification with the exact same token MUST fail
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('replay attack detected');
        $this->engine->verifyAndConsumeToken($challenge['token'], $ip, $ua);
    }

    public function testRejectsTamperedToken(): void
    {
        $ip = '192.168.1.100';
        $ua = 'Mozilla/5.0';

        $challenge = $this->engine->issueChallengeToken($ip, $ua);
        $tampered = substr_replace($challenge['token'], 'X', 15, 1);

        $this->expectException(RuntimeException::class);
        $this->engine->verifyAndConsumeToken($tampered, $ip, $ua);
    }
}
