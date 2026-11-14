# 01. Getting Started with Xivig CAPTCHA

## 1. Introduction & Security Boundary

**Xivig CAPTCHA (`xivig/captcha`)** is an enterprise-grade challenge and anti-abuse toolkit for PHP applications. It combines cryptographic Proof of Work (PoW), optional interactive visual step-ups, accessible audio challenges, and behavioral kinetics to defend applications against automated credential stuffing, form spam, scraping, and brute-force abuse.

> [!IMPORTANT]
> **Understanding the Security Boundary**:
> Passing a Proof of Work or step-up challenge demonstrates that the client expended computational or physical effort to solve a specific, time-bounded puzzle. It **does not** prove human identity or guarantee that a request is benign. Xivig CAPTCHA is designed to be deployed as part of a defense-in-depth security strategy alongside server-side rate limiting, CSRF protection, input validation, and account authorization controls.

---

## 2. Requirements

- **PHP Version**: PHP 8.2 or higher
- **Required Extensions**:
  - `ext-openssl` (AES-256-GCM token encryption and HMAC-SHA256 signing)
  - `ext-json` (JSON payload handling)
  - `ext-hash` (HKDF key derivation and SHA-256 computation)
- **Optional Extensions**:
  - `ext-gd` (For high-resolution PNG puzzle rendering in interactive step-up mode; SVG fallback is included automatically if GD is unavailable)
  - `ext-redis` (Recommended for multi-server, high-traffic distributed replay protection)

---

## 3. Installation

Install the package via Composer:

```bash
composer require xivig/captcha
```

Generate a secure 32-character encryption secret:

```bash
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
```

Store this key in your environment variables (`AEGIS_SECRET_KEY`) or framework configuration. **Never commit the secret key to version control.**

---

## 4. Challenge Lifecycle & Execution Flow

```text
[ Browser / Client ]                                   [ PHP Application Server ]
         │                                                        │
         │  1. GET /aegis/challenge                               │
         │ ─────────────────────────────────────────────────────> │
         │                                                        │ 2. Issues Challenge:
         │                                                        │    - AES-256-GCM token with AAD
         │                                                        │    - Bounded PoW seed + difficulty
         │  3. Returns JSON { token, seed, difficulty }           │    - Rate limit & burst evaluation
         │ <───────────────────────────────────────────────────── │
         │                                                        │
         │ 4. Solves Challenge:                                   │
         │    - Bounded Web Worker computes SHA-256               │
         │    - If blocked: falls back to yielding main thread    │
         │    - Injects hidden 'aegis_payload' into form          │
         │                                                        │
         │  5. POST /form-submission (with aegis_payload)         │
         │ ─────────────────────────────────────────────────────> │
         │                                                        │ 6. Zero-Trust Verification:
         │                                                        │    - Payload size ceiling (< 16KB)
         │                                                        │    - AES-256-GCM decryption
         │                                                        │    - IP Subnet & User-Agent match
         │                                                        │    - Atomic Nonce Claim (SET NX EX)
         │                                                        │    - SHA-256 Proof of Work match
         │  7. HTTP 200 OK or 403 Forbidden                       │    - Risk score calculation (>= 0.65)
         │ <───────────────────────────────────────────────────── │
```

---

## 5. Quickstart Example (Vanilla PHP)

Protect any PHP script with just a few lines of code:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Aegis\Captcha\Adapters\Native\NativeAegis;

// 1. Initialize engine (Secret key must be at least 32 characters)
NativeAegis::setup('your-secure-32-character-secret-key-here!!');

// 2. Intercept background challenge requests automatically
NativeAegis::handleChallengeEndpoint();

// 3. Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = NativeAegis::verifyPost();
    if (!$result->isHuman) {
        http_response_code(403);
        die("Security verification failed. Reason flags: " . implode(', ', $result->flags));
    }

    echo "Form submitted successfully by a verified visitor!";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Protected Form Example</title>
    <link rel="stylesheet" href="/vendor/aegis/css/aegis.css">
</head>
<body>
    <form method="POST">
        <label for="name">Name:</label>
        <input type="text" id="name" name="name" required>

        <!-- Captcha Widget Container -->
        <?php NativeAegis::renderWidget(); ?>

        <button type="submit">Submit</button>
    </form>

    <?php NativeAegis::renderScript(); ?>
</body>
</html>
```
