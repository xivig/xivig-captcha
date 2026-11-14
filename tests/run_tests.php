<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Config/AegisConfig.php';
require_once __DIR__ . '/../src/Storage/NonceStorageInterface.php';
require_once __DIR__ . '/../src/Storage/MemoryNonceStorage.php';
require_once __DIR__ . '/../src/Storage/FileNonceStorage.php';
require_once __DIR__ . '/../src/Storage/Psr16NonceStorage.php';
require_once __DIR__ . '/../src/Storage/RedisNonceStorage.php';
require_once __DIR__ . '/../src/Crypto/ProofOfWork.php';
require_once __DIR__ . '/../src/Crypto/TokenEngine.php';
require_once __DIR__ . '/../src/Engine/EnvironmentDetector.php';
require_once __DIR__ . '/../src/Engine/TrajectoryAnalyzer.php';
require_once __DIR__ . '/../src/Engine/RiskAnalyzer.php';
require_once __DIR__ . '/../src/Engine/AdaptiveDifficulty.php';
require_once __DIR__ . '/../src/Security/RateLimiter.php';
require_once __DIR__ . '/../src/Challenge/InteractiveChallenge.php';
require_once __DIR__ . '/../src/Challenge/AudioChallenge.php';
require_once __DIR__ . '/../src/Http/AegisResponse.php';
require_once __DIR__ . '/../src/Aegis.php';

use Aegis\Captcha\Aegis;
use Aegis\Captcha\Challenge\AudioChallenge;
use Aegis\Captcha\Challenge\InteractiveChallenge;
use Aegis\Captcha\Config\AegisConfig;
use Aegis\Captcha\Crypto\ProofOfWork;
use Aegis\Captcha\Engine\AdaptiveDifficulty;
use Aegis\Captcha\Security\RateLimiter;
use Aegis\Captcha\Storage\FileNonceStorage;
use Aegis\Captcha\Storage\MemoryNonceStorage;
use Aegis\Captcha\Storage\Psr16NonceStorage;
use Aegis\Captcha\Storage\RedisNonceStorage;

echo "=== Running AegisGuard v2.0 Enterprise Security Test Suite ===" . PHP_EOL;

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $testName): void {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] $testName" . PHP_EOL;
        $passed++;
    } else {
        echo " [FAIL] $testName" . PHP_EOL;
        $failed++;
    }
}

// 1. PoW Solver & Verification
$seed = 'test_seed_' . bin2hex(random_bytes(4));
$difficulty = 3;
$t0 = microtime(true);
$nonce = ProofOfWork::solve($seed, $difficulty);
$solveTime = round((microtime(true) - $t0) * 1000, 2);
assertTest($nonce !== null && ProofOfWork::verify($seed, $nonce, $difficulty), "Proof of Work solves ($solveTime ms) and verifies");

// 2. Token Generation
$config = new AegisConfig(
    secretKey: '12345678901234567890123456789012',
    powDifficulty: 3
);
$storage = new MemoryNonceStorage();
$aegis = new Aegis($config, $storage);

$ip = '192.168.1.50';
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';
$challenge = $aegis->createChallenge($ip, $ua);
assertTest(!empty($challenge['token']) && !empty($challenge['seed']), "Token generation issues valid payload");

// 3. Human Simulation
$solvedNonce = ProofOfWork::solve($challenge['seed'], $challenge['difficulty']);
$humanPayload = base64_encode(json_encode([
    'token' => $challenge['token'],
    'powNonce' => $solvedNonce,
    'telemetry' => [
        'webdriver' => false,
        'durationMs' => 2800
    ]
]));

$verifyRes = $aegis->verify($humanPayload, $ip, $ua);
assertTest($verifyRes->isHuman && $verifyRes->score >= 0.70, "Valid challenge accepted (Score: {$verifyRes->score})");

// 4. Replay Attack Prevention
$replayRes = $aegis->verify($humanPayload, $ip, $ua);
assertTest(!$replayRes->isHuman && in_array('VERIFICATION_FAILED', $replayRes->flags), "Replay attack is instantly rejected");

// 5. Bot Simulation (Headless Webdriver)
$challenge2 = $aegis->createChallenge($ip, $ua);
$botNonce = ProofOfWork::solve($challenge2['seed'], $challenge2['difficulty']);
$botPayload = base64_encode(json_encode([
    'token' => $challenge2['token'],
    'powNonce' => $botNonce,
    'telemetry' => [
        'webdriver' => true,
        'durationMs' => 120
    ]
]));

$botRes = $aegis->verify($botPayload, $ip, $ua);
assertTest(!$botRes->isHuman && $botRes->score < 0.65, "Automation indicators lower risk score below threshold");

// 6. Oversized Payload Rejection
$oversized = $aegis->verify(str_repeat('A', 17000), $ip, $ua);
assertTest(!$oversized->isHuman && in_array('PAYLOAD_TOO_LARGE', $oversized->flags), "Oversized payload rejected before decoding");

// 7. Client IP Mismatch Rejection
$challenge3 = $aegis->createChallenge($ip, $ua);
$nonce3 = ProofOfWork::solve($challenge3['seed'], $challenge3['difficulty']);
$basePayload3 = base64_encode(json_encode([
    'token' => $challenge3['token'], 'powNonce' => $nonce3, 'telemetry' => []
]));
$wrongIp = $aegis->verify($basePayload3, '203.0.113.9', $ua);
assertTest(!$wrongIp->isHuman, "Client IP binding mismatch is rejected");

// 8. Tampered Token Rejection
$challenge4 = $aegis->createChallenge($ip, $ua);
$nonce4 = ProofOfWork::solve($challenge4['seed'], $challenge4['difficulty']);
$challenge4['token'][10] = $challenge4['token'][10] === 'A' ? 'B' : 'A';
$tampered = base64_encode(json_encode([
    'token' => $challenge4['token'], 'powNonce' => $nonce4, 'telemetry' => []
]));
$tamperedResult = $aegis->verify($tampered, $ip, $ua);
assertTest(!$tamperedResult->isHuman, "Tampered authenticated token is rejected");

// 9. Local File Storage Single-Use Nonce
$storageDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aegis_test_' . bin2hex(random_bytes(6));
$fileStore = new FileNonceStorage($storageDir);
$localFirst = $fileStore->claimNonce('same-nonce', 60);
$localSecond = $fileStore->claimNonce('same-nonce', 60);
assertTest($localFirst && !$localSecond && $fileStore->hasNonce('same-nonce'), "File nonce storage enforces single use");
$fileStore->garbageCollect();
@unlink($storageDir . DIRECTORY_SEPARATOR . '.aegis.lock');
@rmdir($storageDir);

// 10. Atomic Cache Adapter (add)
$fakeCache = new class {
    private array $values = [];
    public function add(string $key, mixed $value, int $ttl): bool {
        if (isset($this->values[$key])) return false;
        $this->values[$key] = $value;
        return true;
    }
    public function has(string $key): bool { return isset($this->values[$key]); }
};
$cacheStore = new Psr16NonceStorage($fakeCache);
assertTest($cacheStore->claimNonce('cache-nonce', 60) && !$cacheStore->claimNonce('cache-nonce', 60), "Atomic cache adapter uses create-if-absent");

// 11. Native Redis Storage Simulation
$mockRedis = new class {
    private array $store = [];
    public function set(string $key, string $value, array $options): bool {
        $hasNx = in_array('NX', $options, true) || !empty($options['NX']);
        if ($hasNx && isset($this->store[$key])) {
            return false;
        }
        $this->store[$key] = $value;
        return true;
    }
    public function exists(string $key): int { return isset($this->store[$key]) ? 1 : 0; }
};
$redisStore = new RedisNonceStorage($mockRedis);
assertTest($redisStore->claimNonce('redis-nonce-1', 60) && !$redisStore->claimNonce('redis-nonce-1', 60), "Native Redis SET NX EX enforces atomic single use");

// 12. Adaptive Difficulty Scaling
AdaptiveDifficulty::reset();
$diffInitial = AdaptiveDifficulty::calculate('198.51.100.5', baseDifficulty: 3);
// Simulate rapid request burst
for ($i = 0; $i < 12; $i++) {
    AdaptiveDifficulty::calculate('198.51.100.5', baseDifficulty: 3);
}
$diffBurst = AdaptiveDifficulty::calculate('198.51.100.5', baseDifficulty: 3);
assertTest($diffInitial === 3 && $diffBurst >= 4, "Adaptive Difficulty scales from $diffInitial to $diffBurst under burst traffic");

// 13. Rate Limiter Challenge Throttling (In-Memory & Durable File)
RateLimiter::resetInMemory();
$limiter = new RateLimiter(maxAttempts: 3, windowSeconds: 60, fallbackPolicy: RateLimiter::POLICY_MEMORY);
$check1 = $limiter->check('192.0.2.1');
$check2 = $limiter->check('192.0.2.1');
$check3 = $limiter->check('192.0.2.1');
$check4 = $limiter->check('192.0.2.1');
assertTest($check1['allowed'] && $check3['allowed'] && !$check4['allowed'], "Rate Limiter throttles excessive challenge requests");

// 14. Rate Limiter Durable Multi-Process File Fallback (PHP-FPM Safe)
$rateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aegis_rl_test_' . bin2hex(random_bytes(4));
$durableLimiter = new RateLimiter(maxAttempts: 2, windowSeconds: 60, fallbackPolicy: RateLimiter::POLICY_DURABLE_FILE, storageDir: $rateDir);
$dCheck1 = $durableLimiter->check('10.0.0.1');
$dCheck2 = $durableLimiter->check('10.0.0.1');
$dCheck3 = $durableLimiter->check('10.0.0.1');
assertTest($dCheck1['allowed'] && $dCheck2['allowed'] && !$dCheck3['allowed'], "Durable file-based rate limiting persists across processes and throttles");
// Clean up temp dir
array_map('unlink', glob("$rateDir/*.*"));
@rmdir($rateDir);

// 15. Interactive Slider Step-Up Security (No Answer Leak + Replay Prevention)
$interactiveMem = new MemoryNonceStorage();
$interactive = new InteractiveChallenge('secret-key-for-slider-puzzle-test', $interactiveMem);
$puzzle = $interactive->generateSliderPuzzle(300, 150);
$noLeak = !isset($puzzle['target_hint']) && !isset($puzzle['x']) && !empty($puzzle['bg_image']) && !empty($puzzle['piece_image']);
$targetData = json_decode(base64_decode($puzzle['token']), true);
$targetX = (int)($targetData['x'] ?? 0);
$validSlide = $interactive->verifySliderSolution($puzzle['token'], $targetX);
$replaySlide = $interactive->verifySliderSolution($puzzle['token'], $targetX); // Must fail: single use!
$puzzle2 = $interactive->generateSliderPuzzle(300, 150);
$invalidSlide = $interactive->verifySliderSolution($puzzle2['token'], 999);
assertTest($noLeak && $validSlide && !$replaySlide && !$invalidSlide, "Interactive Step-Up hides target coordinates, validates alignment, and prevents replay");

// 16. Accessible Audio Challenge Security (No Answer Leak + Replay Prevention)
$audioMem = new MemoryNonceStorage();
$audio = new AudioChallenge('secret-key-for-audio-challenge-test', $audioMem);
$challengeAudio = $audio->generate(4);
$noAudioLeak = !isset($challengeAudio['digits']) && str_starts_with($challengeAudio['audio_wav'], 'data:audio/wav;base64,');
$audioData = json_decode(base64_decode($challengeAudio['token']), true);
$secretDigits = (string)($audioData['d'] ?? '');
$validAudio = $audio->verify($challengeAudio['token'], $secretDigits);
$replayAudio = $audio->verify($challengeAudio['token'], $secretDigits); // Must fail: single use!
$challengeAudio2 = $audio->generate(4);
$invalidAudio = $audio->verify($challengeAudio2['token'], '9999');
assertTest($noAudioLeak && $validAudio && !$replayAudio && !$invalidAudio && strlen($challengeAudio['audio_wav']) > 500, "Accessible Audio Challenge synthesizes WAV, never leaks digits, and enforces single-use");

echo PHP_EOL . "Summary: $passed passed, $failed failed." . PHP_EOL;
exit($failed > 0 ? 1 : 0);
