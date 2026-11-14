<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/AegisConfig.php';
require_once __DIR__ . '/../../src/Storage/NonceStorageInterface.php';
require_once __DIR__ . '/../../src/Storage/FileNonceStorage.php';
require_once __DIR__ . '/../../src/Crypto/ProofOfWork.php';
require_once __DIR__ . '/../../src/Crypto/TokenEngine.php';
require_once __DIR__ . '/../../src/Engine/EnvironmentDetector.php';
require_once __DIR__ . '/../../src/Engine/TrajectoryAnalyzer.php';
require_once __DIR__ . '/../../src/Engine/RiskAnalyzer.php';
require_once __DIR__ . '/../../src/Http/AegisResponse.php';
require_once __DIR__ . '/../../src/Aegis.php';
require_once __DIR__ . '/../../src/Adapters/Native/NativeAegis.php';

use Aegis\Captcha\Adapters\Native\NativeAegis;

// 1. Initialize Aegis with a 32+ character secret key
$aegis = NativeAegis::setup('your-super-secret-production-key-32-chars!!', [
    'pow_difficulty' => 4,
    'risk_threshold' => 0.65
]);

// 2. Intercept challenge endpoint requests (fetch('/aegis/challenge'))
NativeAegis::handleChallengeEndpoint();

// 3. Handle Form POST
$message = '';
$isSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = NativeAegis::verifyPost();

    if ($result->isHuman) {
        $message = "Verification successful! Welcome human (Risk Score: {$result->score})";
        $isSuccess = true;
    } else {
        $message = "Bot detected! Submission blocked. Flags: " . implode(', ', $result->flags);
        $isSuccess = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>AegisGuard Native PHP Demo</title>
    <link rel="stylesheet" href="../../assets/css/aegis.css">
    <style>
        body { font-family: sans-serif; background: #0f172a; color: #f8fafc; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 32px; border-radius: 12px; width: 380px; box-shadow: 0 10px 25px rgba(0,0,0,0.4); border: 1px solid #334155; }
        input[type="text"], input[type="email"] { width: 100%; padding: 10px; margin: 8px 0 16px 0; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: #fff; box-sizing: border-box; }
        button { width: 100%; padding: 12px; border: none; border-radius: 6px; background: #3b82f6; color: #fff; font-weight: bold; cursor: pointer; transition: background 0.2s; }
        button:hover { background: #2563eb; }
        .alert { padding: 12px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
        .alert-success { background: #065f46; color: #a7f3d0; }
        .alert-error { background: #991b1b; color: #fecaca; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Protected Login</h2>
        <?php if (!empty($message)): ?>
            <div class="alert <?= $isSuccess ? 'alert-success' : 'alert-error' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <label>Email Address</label>
            <input type="email" name="email" required placeholder="user@example.com">

            <label>Password</label>
            <input type="password" name="password" required placeholder="••••••••">

            <!-- Aegis Protection Widget -->
            <?php NativeAegis::renderWidget(); ?>

            <button type="submit">Sign In</button>
        </form>
    </div>

    <!-- Client SDK -->
    <script src="../../assets/js/aegis.js"></script>
    <script>
        new Aegis({ endpoint: '?aegis=challenge' });
    </script>
</body>
</html>
