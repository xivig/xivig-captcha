# Xivig Captcha v2.0 - Production Deployment & Hardening Guide

This document provides step-by-step production recipes for hosting environments ranging from shared hosting (cPanel/Apache) to multi-node Kubernetes clusters and VPS deployments.

---

## 1. Hosting Architecture Matrix

| Environment | Recommended Storage | Recommended Difficulty | Reverse Proxy Setup |
| :--- | :--- | :---: | :--- |
| **Shared Hosting** (cPanel / LiteSpeed) | `FileNonceStorage` | `3` | Default `REMOTE_ADDR` |
| **VPS / Single Server** (Nginx + PHP-FPM) | `RedisNonceStorage` (Local Redis) | `3` or `4` | Configure Nginx `set_real_ip_from` |
| **Cloud Cluster** (AWS / DigitalOcean / K8s) | `RedisNonceStorage` (Redis Cluster) | Adaptive (`3` - `5`) | Forward `CF-Connecting-IP` / `X-Forwarded-For` |

---

## 2. Shared Hosting (cPanel / Apache / LiteSpeed)

### A. Nonce Storage Configuration
Shared hosting cannot run Redis daemons. Use `FileNonceStorage` placed in a private directory above `public_html`:

```php
use Aegis\Captcha\Aegis;
use Aegis\Captcha\Config\AegisConfig;
use Aegis\Captcha\Storage\FileNonceStorage;

$storageDir = dirname(__DIR__) . '/storage/xivig-nonces';
$storage = new FileNonceStorage($storageDir);

$config = new AegisConfig(
    secretKey: getenv('AEGIS_SECRET_KEY'),
    powDifficulty: 3
);

$aegis = new Aegis($config, $storage);
```

### B. Apache `.htaccess` Protection
Ensure the nonce storage directory is never accessible over HTTP:

```apache
# Deny access to nonce storage folder if inside webroot
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
```

---

## 3. VPS / Dedicated Server (Ubuntu 22.04 / 24.04 + Nginx + Redis)

### A. Install and Configure Redis
```bash
sudo apt update && sudo apt install -y redis-server php8.3-redis
sudo systemctl enable redis-server
sudo systemctl start redis-server
```

In `/etc/redis/redis.conf`:
```text
maxmemory 256mb
maxmemory-policy allkeys-lru
```

### B. Nginx Reverse Proxy & Real IP Configuration
In `/etc/nginx/conf.d/real_ip.conf`:
```nginx
# Cloudflare IP Ranges
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 103.21.244.0/22;
set_real_ip_from 103.22.200.0/22;
set_real_ip_from 103.31.4.0/22;
set_real_ip_from 141.101.64.0/18;
set_real_ip_from 108.162.192.0/18;
set_real_ip_from 190.93.240.0/20;
set_real_ip_from 188.114.96.0/20;
set_real_ip_from 197.234.240.0/22;
set_real_ip_from 198.41.128.0/17;
set_real_ip_from 162.158.0.0/15;
set_real_ip_from 104.16.0.0/13;
set_real_ip_from 104.24.0.0/14;
set_real_ip_from 172.64.0.0/13;
set_real_ip_from 131.0.72.0/22;

real_ip_header CF-Connecting-IP;
```

### C. Nginx Rate Limiting for Challenge Endpoint
Prevent scrapers from flooding challenge generation:
```nginx
limit_req_zone $binary_remote_addr zone=aegis_challenges:10m rate=15r/s;

location = /aegis/challenge {
    limit_req zone=aegis_challenges burst=20 nodelay;
    try_files $uri /index.php?$query_string;
}
```

---

## 4. Cloud Infrastructure & Kubernetes (Docker / Multi-Node)

### A. Redis Cluster Replay Storage
In distributed container clusters, all application pods must share the same Redis cluster to prevent cross-pod token replays:

```php
use Aegis\Captcha\Storage\RedisNonceStorage;

$redis = new \RedisCluster(null, [
    'redis-cluster-0.redis-service:6379',
    'redis-cluster-1.redis-service:6379',
    'redis-cluster-2.redis-service:6379'
]);

$storage = new RedisNonceStorage($redis);
```

### B. Environment Variables (12-Factor App)
```env
AEGIS_SECRET_KEY=9f83c18b76df912ba4d8234ef018274391823746aef81923
AEGIS_MODE=hybrid
AEGIS_POW_DIFFICULTY=4
AEGIS_TTL_SECONDS=180
AEGIS_RISK_THRESHOLD=0.65
REDIS_HOST=redis.internal
REDIS_PORT=6379
```

---

## 5. Content Security Policy (CSP) Directives

Aegis v2.0 includes a standalone external Web Worker (`aegis-worker.js`), ensuring full compliance with strict enterprise CSP rules.

Add the following directives to your CSP header:

```http
Content-Security-Policy: default-src 'self'; script-src 'self'; worker-src 'self'; style-src 'self' 'unsafe-inline';
```

*(No `unsafe-eval` or `blob:` is required when using the standalone `aegis-worker.js`)*.
