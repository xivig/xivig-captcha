# Xivig CAPTCHA (`xivig/captcha`) — Complete Integration Manual

> **Universal Anti-Bot & Anti-Abuse Security Engine for Modern PHP Frameworks**  
> Protects forms, APIs, and authentication endpoints using client-side Web Worker Proof-of-Work (PoW), behavioral dynamics, interactive slider puzzles, and accessible audio synthesis.

---

## 📖 Documentation Structure & Directory

The complete documentation suite has been organized into modular, deep-dive guides inside the [`docs/`](docs/) directory:

| Section | Module | Key Topics Covered |
|:---|:---|:---|
| **01. Architecture** | [01-getting-started.md](docs/01-getting-started.md) | Security boundaries, requirements, challenge lifecycle, and execution flow. |
| **02. Configuration** | [02-configuration.md](docs/02-configuration.md) | `.env` variables, `AegisConfig` options, PoW difficulty tuning, and storage drivers. |
| **03. Challenge Modes** | [03-challenge-modes.md](docs/03-challenge-modes.md) | Mode A: Invisible PoW, Mode B: Interactive Slider (GD/SVG), Mode C: Audio WAV tones. |
| **04. Frameworks** | [04-framework-integrations/](docs/04-framework-integrations/) | Detailed framework-specific integration guides: |
| &nbsp;&nbsp;↳ **Laravel** | [laravel.md](docs/04-framework-integrations/laravel.md) | Laravel 10/11/12/13, ServiceProvider, `@aegisWidget`, Rule, Middleware, Livewire, Inertia. |
| &nbsp;&nbsp;↳ **Symfony** | [symfony.md](docs/04-framework-integrations/symfony.md) | Symfony 6/7, Service configuration, Challenge Controller, Form types, Twig templates. |
| &nbsp;&nbsp;↳ **WordPress** | [wordpress.md](docs/04-framework-integrations/wordpress.md) | Complete plugin, Admin Settings, wp-login, Registration, Comments, WooCommerce. |
| &nbsp;&nbsp;↳ **PSR-15** | [psr15-frameworks.md](docs/04-framework-integrations/psr15-frameworks.md) | Slim 4, Mezzio, Laminas, CakePHP with PSR-17 factory injection. |
| &nbsp;&nbsp;↳ **CI4 / Yii / Flight** | [codeigniter-yii-flight.md](docs/04-framework-integrations/codeigniter-yii-flight.md) | CodeIgniter 4 Filters, Yii 2/3 ActionFilters, Flight PHP route hooks. |
| &nbsp;&nbsp;↳ **Vanilla PHP** | [native-php.md](docs/04-framework-integrations/native-php.md) | 2-line drop-in setup for procedural scripts and custom CMS architectures. |
| **05. Frontend & SPAs** | [05-frontend-and-spas.md](docs/05-frontend-and-spas.md) | `aegis.js` SDK, `aegis-worker.js`, React, Vue 3, Svelte, WCAG 2.1 AA accessibility. |
| **06. Security & Storage** | [06-security-and-storage.md](docs/06-security-and-storage.md) | HKDF AES-256-GCM tokens, Redis `SET NX EX`, durable file rate limiting, adaptive difficulty. |
| **07. Deployment** | [07-production-deployment.md](docs/07-production-deployment.md) | Shared Hosting (cPanel), VPS (Nginx + PHP-FPM), Kubernetes, and Cloudflare CDN proxy. |
| **08. Packagist** | [08-publishing-to-packagist.md](docs/08-publishing-to-packagist.md) | Step-by-step instructions to push to GitHub, register on Packagist.org, and set up auto-update webhooks. |

---

## 1. Quick Installation

```bash
composer require xivig/captcha
```

Generate a secure encryption key:
```bash
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
```
Set `AEGIS_SECRET_KEY` in your environment.

---

## 2. Universal Quickstarts

### 2.1 Laravel (v10 / v11 / v12 / v13)
```php
// In your Controller
$request->validate([
    'email' => 'required|email',
    'password' => 'required',
    'aegis_payload' => ['required', new \Aegis\Captcha\Adapters\Laravel\Rules\AegisHumanRule()],
]);
```

In Blade:
```blade
<form method="POST" action="/login">
    @csrf
    @aegisWidget
    <button type="submit">Sign In</button>
</form>
@aegisScript
```

### 2.2 WordPress & WooCommerce
1. Copy `wordpress/xivig-captcha` to `wp-content/plugins/xivig-captcha`.
2. Activate via **WordPress Admin > Plugins**.
3. Configure protected forms (Login, Registration, Comments, WooCommerce Checkout) under **Settings > Xivig Captcha**.

### 2.3 PSR-15 (Slim 4, Mezzio, Symfony)
```php
use Aegis\Captcha\Adapters\Psr15\AegisPsr15Middleware;

$app->add(new AegisPsr15Middleware(
    aegis: $aegis,
    protectedRoutes: ['/api/register', '/login']
));
```

### 2.4 Native / Vanilla PHP
```php
require_once __DIR__ . '/vendor/autoload.php';
use Aegis\Captcha\Adapters\Native\NativeAegis;

NativeAegis::setup('32-character-secret-key-string!!');
NativeAegis::handleChallengeEndpoint(); // call before HTML output

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = NativeAegis::verifyPost();
    if (!$result->isHuman) {
        http_response_code(403);
        die("Security verification failed.");
    }
}
```

---

## 3. Defense In-Depth & Security Guarantees

1. **Proof of Work is an Economic Anti-Abuse Friction Barrier**: Passing a challenge proves bounded computational effort was expended. It does not establish human identity. Always maintain CSRF tokens, rate limiting, and input validation.
2. **Zero Plaintext Answer Leaks**: Slider puzzle cutout pieces and backgrounds are rendered server-side; coordinates are sealed in HMAC tokens and never sent in JSON or DOM attributes. Audio challenges synthesize WAV streams without leaking expected digits.
3. **Atomic Replay Defense**: Nonces are consumed atomically via Redis `SET NX EX` or kernel-locked filesystem storage (`flock(LOCK_EX)`).
4. **PHP-FPM Safe Rate Limiting**: The built-in `RateLimiter` features durable multi-process file locking (`POLICY_DURABLE_FILE`) to solve PHP-FPM isolated worker memory limitations when Redis is offline.

---

## 4. Verification & Testing

Run the enterprise automated test suite:

```bash
php tests/run_tests.php
```

All 16 tests pass with 0 failures across cryptography, replay defense, rate limiting, visual slider step-ups, and audio challenge synthesis.
