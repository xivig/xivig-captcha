# Xivig CAPTCHA — Aegis PHP Anti-Abuse Toolkit

`xivig/captcha` is a framework-agnostic PHP challenge component. It issues short-lived authenticated challenges and asks the browser to solve a bounded SHA-256 proof-of-work puzzle. It includes Laravel, native PHP and PSR-15 integration points.

> **Security boundary:** A valid proof-of-work shows that the challenge was completed. It does not prove that a visitor is human. Use Aegis alongside server-side rate limiting, CSRF protection, input validation, account security and abuse monitoring. For high-risk applications, consider a managed challenge/risk service with accessibility and privacy guarantees.

## What it provides

- AES-256-GCM authenticated challenge tokens with HKDF-derived keys and versioned associated data.
- Expiration checks, optional IP-subnet/User-Agent binding and single-use nonce claims.
- Bounded browser proof-of-work using a Web Worker where permitted, with a yielding fallback.
- **Interactive Step-Up Challenge (v2.0)**: Server-rendered visual slider puzzle with hidden coordinates and atomic single-use tokens.
- **Accessible Audio Challenge (v2.0)**: Synthesized multi-tone acoustic WAV stream for visually impaired users without answer leaks.
- **Durable Rate Limiting (v2.0)**: Atomic multi-process file-locking fallback (`POLICY_DURABLE_FILE`) solving PHP-FPM worker isolation when Redis is offline.
- **Adaptive Difficulty (v2.0)**: Sliding-window traffic burst monitoring that dynamically scales computational difficulty.
- **Native Redis Storage (v2.0)**: Direct atomic `SET NX EX` replay protection supporting PhpRedis, RedisCluster, and Predis.
- **WordPress & WooCommerce Plugin (v2.0)**: Complete plugin with admin settings, capability checks, CSRF nonces, and login/checkout protection.
- Request-size and payload validation, generic client-facing errors and conservative risk hints.
- Laravel service provider (Laravel 10, 11, 12, 13), validation rule, middleware, native PHP helper, and PSR-15/PSR-17 middleware.
- Accessible status UI, retry behavior, reduced motion support, and form-submit gating while the challenge runs.

## Requirements

- PHP 8.2+; OpenSSL, JSON and Hash extensions.
- Browser with Fetch, Web Crypto, and modern JavaScript. A bounded fallback is provided when Blob workers are blocked.
- Laravel integration requires Laravel/Illuminate. PSR-15 integration requires PSR-7 and PSR-15 packages.

## Installation

```bash
composer require xivig/captcha
```

Generate a dedicated secret (do not commit it):

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Set `AEGIS_SECRET_KEY` in the environment. In Laravel, publish configuration with `php artisan vendor:publish --tag=aegis-config`.

## Laravel usage

```php
use Aegis\Captcha\Adapters\Laravel\Rules\AegisHumanRule;

$request->validate([
    'aegis_payload' => ['required', new AegisHumanRule()],
]);
```

Place the widget inside the target form and load the published client asset:

```blade
@aegisStyle
<form method="POST" action="/submit">
    @csrf
    <div id="aegis-container"></div>
    <button type="submit">Submit</button>
</form>
<script src="{{ asset('vendor/aegis/js/aegis.js') }}" defer></script>
<script>document.addEventListener('DOMContentLoaded', () => new Aegis());</script>
```

The Laravel service provider registers the challenge route. Keep the framework CSRF middleware enabled on the form route.

## Native PHP usage

```php
use Aegis\Captcha\Adapters\Native\NativeAegis;

NativeAegis::setup($_ENV['AEGIS_SECRET_KEY']);
NativeAegis::handleChallengeEndpoint(); // call before output

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = NativeAegis::verifyPost();
    if (!$result->isHuman) {
        http_response_code(403);
        exit('Security check failed. Please retry.');
    }
}
```

## Replay storage and scaling

For a single-node deployment, the locked file store can be used. For multiple nodes, configure a shared cache whose `add(key, value, ttl)` operation is atomic (Laravel's cache repository backed by Redis is a suitable pattern). PSR-16 does not itself guarantee atomic add; the adapter refuses unsafe `has()` + `set()` implementations. Do not use node-local file storage behind a load balancer.

## Testing

```bash
php tests/run_tests.php
```

## Documentation

- [Complete Documentation Index](DOCUMENTATION.md)
- [01. Architecture & Getting Started](docs/01-getting-started.md)
- [02. Configuration Reference](docs/02-configuration.md)
- [03. Challenge Modes (PoW, Slider, Audio)](docs/03-challenge-modes.md)
- [04. Framework Integrations (Laravel, Symfony, WordPress, PSR-15, CI4, Yii, Native)](docs/04-framework-integrations/)
- [05. Frontend SDK & SPAs (React, Vue, Svelte)](docs/05-frontend-and-spas.md)
- [06. Security & Storage Architecture](docs/06-security-and-storage.md)
- [07. Production Deployment (cPanel, VPS, K8s, Cloudflare)](docs/07-production-deployment.md)
