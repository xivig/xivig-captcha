# Framework Integration: Native / Vanilla PHP

For legacy systems, procedural PHP scripts, or custom CMS platforms without a modern framework, Xivig CAPTCHA provides the `NativeAegis` static helper.

---

## 1. Minimal 2-Line Integration

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Aegis\Captcha\Adapters\Native\NativeAegis;

// 1. Setup the secret key (At least 32 characters)
NativeAegis::setup('your-secret-32-character-key-here!!');

// 2. Intercept and respond to background challenge requests (call before any HTML output)
NativeAegis::handleChallengeEndpoint();

// 3. Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = NativeAegis::verifyPost();
    if (!$result->isHuman) {
        http_response_code(403);
        die("Submission blocked: Automated activity suspected.");
    }

    echo "Form validated successfully!";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Native PHP Form</title>
    <link rel="stylesheet" href="/assets/css/aegis.css">
</head>
<body>
    <form method="POST">
        <input type="text" name="comment" placeholder="Leave a comment" required>

        <!-- Widget container -->
        <?php NativeAegis::renderWidget(); ?>

        <button type="submit">Post Comment</button>
    </form>

    <!-- Client script loader -->
    <?php NativeAegis::renderScript('/assets/js/aegis.js'); ?>
</body>
</html>
```

---

## 2. Advanced Native Integration (OOP)

For object-oriented architectures:

```php
<?php
use Aegis\Captcha\Aegis;
use Aegis\Captcha\Config\AegisConfig;
use Aegis\Captcha\Storage\FileNonceStorage;

$config = new AegisConfig(
    secretKey: getenv('SECRET_KEY') ?: 'secret-key-at-least-32-characters-long!!',
    powDifficulty: 3
);

$storage = new FileNonceStorage('/var/run/aegis-nonces');
$aegis = new Aegis($config, $storage);

// Handle manual API check
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

if (isset($_GET['action']) && $_GET['action'] === 'challenge') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, private');
    echo json_encode($aegis->createChallenge($clientIp, $userAgent));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = (string)($_POST['aegis_payload'] ?? '');
    $res = $aegis->verify($payload, $clientIp, $userAgent);

    if (!$res->isHuman) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Verification failed']);
        exit;
    }
}
```
