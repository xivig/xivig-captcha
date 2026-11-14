<?php

declare(strict_types=1);

namespace Aegis\Captcha\Tests;

use Aegis\Captcha\Crypto\ProofOfWork;
use PHPUnit\Framework\TestCase;

final class ProofOfWorkTest extends TestCase
{
    public function testSolveAndVerifyProofOfWork(): void
    {
        $seed = 'test-challenge-seed-12345';
        $difficulty = 3;

        $nonce = ProofOfWork::solve($seed, $difficulty);
        $this->assertNotNull($nonce);

        $isValid = ProofOfWork::verify($seed, $nonce, $difficulty);
        $this->assertTrue($isValid);
    }

    public function testFailsOnInvalidNonce(): void
    {
        $seed = 'test-challenge-seed-12345';
        $invalidNonce = 'fake-nonce-99999999';

        $isValid = ProofOfWork::verify($seed, $invalidNonce, 4);
        $this->assertFalse($isValid);
    }
}
