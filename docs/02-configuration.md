# 02. Configuration Reference

Xivig CAPTCHA is configured through an immutable value object (`AegisConfig`) or via published framework configuration files (`config/aegis.php`).

---

## 1. Complete Configuration Schema

```php
use Aegis\Captcha\Config\AegisConfig;

$config = new AegisConfig(
    secretKey: '32-character-random-secret-key-string!!',
    mode: AegisConfig::MODE_INVISIBLE,            // 'invisible' or 'interactive'
    powDifficulty: 3,                             // 1 (lightest) to 5 (highest friction)
    ttlSeconds: 180,                              // Challenge validity window in seconds
    riskThreshold: 0.65,                          // Acceptance threshold (0.00 to 1.00)
    bindIp: true,                                 // Bind token to client IP subnet (/24 IPv4, /64 IPv6)
    bindUserAgent: true,                          // Bind token to browser User-Agent hash
    challengeEndpoint: '/aegis/challenge',        // Path to handle challenge issuance
    payloadField: 'aegis_payload'                 // Form input field name for client payload
);
```

---

## 2. Environment Variables (.env)

When running inside Laravel or applications utilizing `.env` configuration loaders:

| Environment Variable | Type | Default | Description |
|:---|:---|:---|:---|
| `AEGIS_SECRET_KEY` | `string` | *(Required)* | 32+ character master key for AES-256-GCM and HKDF derivation. |
| `AEGIS_MODE` | `string` | `invisible` | Default challenge mode: `invisible` (PoW) or `interactive` (slider). |
| `AEGIS_POW_DIFFICULTY` | `int` | `3` | Base difficulty for PoW computation (Range: 1–5). |
| `AEGIS_TTL` | `int` | `180` | Challenge token expiration time in seconds. |
| `AEGIS_RISK_THRESHOLD` | `float` | `0.65` | Minimum score required for submission acceptance. |
| `AEGIS_BIND_IP` | `bool` | `true` | Enforces client IP subnet validation. |
| `AEGIS_BIND_USER_AGENT` | `bool` | `true` | Enforces browser User-Agent validation. |
| `AEGIS_CHALLENGE_ENDPOINT` | `string` | `/aegis/challenge` | Route path for serving challenge JSON payloads. |
| `AEGIS_PAYLOAD_FIELD` | `string` | `aegis_payload` | POST field name containing client verification payload. |

---

## 3. Proof of Work Difficulty Tuning

The `powDifficulty` determines the number of leading zero bits required on the client's SHA-256 computation:

| Difficulty | Average Iterations | Client Solve Time (Modern PC) | Client Solve Time (Mobile Device) | Recommended Use Case |
|:---:|:---:|:---:|:---:|:---|
| **1** | ~16 | < 1 ms | < 2 ms | High-traffic public search, read-only forms |
| **2** | ~256 | ~2 ms | ~5 ms | Contact forms, newsletter subscriptions |
| **3** *(Default)* | ~4,096 | ~15 ms | ~35 ms | Standard login, registration, password resets |
| **4** | ~65,536 | ~80 ms | ~200 ms | High-friction APIs, e-commerce checkout |
| **5** | ~1,048,576 | ~350 ms | ~1,200 ms | Heavy brute-force mitigation, account recovery |

> [!TIP]
> The **Adaptive Difficulty Engine** (`src/Engine/AdaptiveDifficulty.php`) will automatically scale difficulty from level 3 up to 4 or 5 during sudden request bursts from suspicious IP addresses, keeping the base difficulty low for legitimate users.

---

## 4. Replay Storage Configuration

The engine accepts any storage adapter implementing `NonceStorageInterface`:

```php
use Aegis\Captcha\Aegis;
use Aegis\Captcha\Storage\RedisNonceStorage;
use Aegis\Captcha\Storage\FileNonceStorage;
use Aegis\Captcha\Storage\Psr16NonceStorage;

// A. Redis (Recommended for multi-server production & Kubernetes)
$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);
$storage = new RedisNonceStorage($redis);

// B. File Storage (Single server VPS or shared hosting with kernel locking)
$storage = new FileNonceStorage(sys_get_temp_dir() . '/aegis_nonces');

// C. PSR-16 Cache Adapter (Requires atomic add() support)
$storage = new Psr16NonceStorage($psr16Cache);

$aegis = new Aegis($config, $storage);
```
