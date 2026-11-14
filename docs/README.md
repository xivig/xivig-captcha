# Xivig CAPTCHA (`xivig/captcha`) — Documentation Hub

Welcome to the official documentation for **Xivig CAPTCHA (AegisGuard v2.0)**, an enterprise-grade, privacy-first, framework-agnostic anti-bot and anti-abuse security engine for PHP.

---

## Documentation Navigation

| Document | Topic | Description |
|:---|:---|:---|
| **[01. Getting Started](01-getting-started.md)** | Core Architecture | Quickstart, installation, challenge lifecycle, and security boundaries. |
| **[02. Configuration Reference](02-configuration.md)** | Configuration | Complete `.env` variables, `aegis.php` options, and tuning parameters. |
| **[03. Challenge Modes](03-challenge-modes.md)** | Challenge Types | Invisible PoW, Interactive Slider Step-Up, and Accessible Audio Challenge. |
| **[04. Framework Integrations](04-framework-integrations/)** | Backend Integrations | Detailed integration guides for all major PHP frameworks. |
| &nbsp;&nbsp;↳ [Laravel Guide](04-framework-integrations/laravel.md) | Laravel 10 / 11 / 12 / 13 | Service Provider, Blade directives, Validation Rule, Middleware, Livewire. |
| &nbsp;&nbsp;↳ [Symfony Guide](04-framework-integrations/symfony.md) | Symfony 6 / 7 | Controllers, Form Types, Event Subscribers, Twig templates. |
| &nbsp;&nbsp;↳ [WordPress & WooCommerce](04-framework-integrations/wordpress.md) | WordPress Plugin | Full plugin, Admin UI, Login, Registration, Comments, WooCommerce checkout. |
| &nbsp;&nbsp;↳ [PSR-15 Frameworks](04-framework-integrations/psr15-frameworks.md) | Slim 4, Mezzio, Laminas | Standard PSR-15 middleware with PSR-17 factory dependency injection. |
| &nbsp;&nbsp;↳ [CodeIgniter, Yii & Flight](04-framework-integrations/codeigniter-yii-flight.md) | CI4, Yii 2/3, Flight | Filters, hooks, and request lifecycle integration. |
| &nbsp;&nbsp;↳ [Native / Vanilla PHP](04-framework-integrations/native-php.md) | Vanilla PHP / Custom CMS | 2-line drop-in implementation without external dependencies. |
| **[05. Frontend & SPAs](05-frontend-and-spas.md)** | Client-Side SDK | Web Worker lifecycle, React, Vue 3, Svelte, Inertia.js, and WCAG accessibility. |
| **[06. Security & Storage Architecture](06-security-and-storage.md)** | Defense In-Depth | Native Redis `SET NX EX`, durable file rate limiting, and adaptive difficulty. |
| **[07. Production Deployment](07-production-deployment.md)** | Infrastructure | Shared Hosting (cPanel), VPS (Nginx + PHP-FPM), Kubernetes, and Cloudflare. |
| **[08. Publishing to Packagist](08-publishing-to-packagist.md)** | Package Distribution | Step-by-step guide to push to GitHub, register on Packagist, and configure webhooks. |

---

## Architectural Principles

1. **Lightweight & Invisible by Default**: Over 95% of legitimate human visitors complete the bounded SHA-256 micro-puzzle invisibly via background Web Worker in < 20ms without any friction or puzzle interactions.
2. **Zero Plaintext Answer Leaks**: Interactive sliders render background and cutout puzzle pieces exclusively on the server (GD / SVG) with HMAC-SHA256 seals. Audio challenges synthesize 16-bit uncompressed PCM WAV tones. Answers are never sent to the client.
3. **Atomic Single-Use Replay Defense**: Every challenge token (PoW, Slider, Audio) binds a unique nonce that is consumed atomically using Redis `SET key 1 NX EX ttl` or kernel-locked filesystem storage (`flock(LOCK_EX)`).
4. **Resilient Rate Limiting**: The sliding-window rate limiter features multi-process file locking fallback (`POLICY_DURABLE_FILE`) to solve PHP-FPM worker isolation when Redis is offline.
5. **Privacy First**: Zero invasive canvas, WebGL, or audio fingerprinting. Complies with GDPR, CCPA, and modern privacy regulations.
