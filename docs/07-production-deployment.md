# 07. Production Deployment Guide

---

## 1. Shared Hosting (cPanel, Apache, LiteSpeed)

For shared hosting accounts without Redis access:
- Use **`FileNonceStorage`** pointing to a private, non-public directory (e.g., outside `public_html/`).
- The directory is locked with `flock(LOCK_EX)` on `.aegis.lock` to prevent concurrent race conditions.

### `config/aegis.php`:
```php
return [
    'secret_key' => env('AEGIS_SECRET_KEY'),
    'mode' => 'invisible',
    'pow_difficulty' => 3,
    'storage_dir' => dirname(__DIR__) . '/storage/aegis_nonces',
];
```

Ensure the storage directory has `0700` permissions and is excluded from web server document roots via `.htaccess`:
```apache
# storage/aegis_nonces/.htaccess
Require all denied
```

---

## 2. Dedicated VPS (Ubuntu + Nginx + PHP-FPM + Redis)

For production VPS hosting:

### 1. Enable Redis Extension:
```bash
sudo apt update && sudo apt install -y php8.3-redis redis-server
sudo systemctl enable --now redis-server
```

### 2. Configure Nginx Caching & Security Headers:
```nginx
# Do not cache challenge issuance responses
location = /aegis/challenge {
    expires -1;
    add_header Cache-Control "no-store, no-cache, must-revalidate, private" always;
    try_files $uri /index.php?$query_string;
}

# Cache static Web Worker and CSS assets
location ^~ /vendor/aegis/ {
    expires 30d;
    add_header Cache-Control "public, immutable";
}
```

### 3. Content Security Policy (CSP):
If running strict CSP headers, allow the static worker:
```text
Content-Security-Policy: default-src 'self'; script-src 'self'; worker-src 'self';
```

---

## 3. Cloud Containers & Kubernetes

For multi-pod Docker and Kubernetes clusters:
- Use **`RedisNonceStorage`** connected to an external managed Redis cluster (AWS ElastiCache, Google Cloud Memorystore, or Redis Sentinel).
- Pods are fully stateless; token nonces are claimed atomically across all pods simultaneously.

### Environment Configuration:
```yaml
env:
  - name: AEGIS_SECRET_KEY
    valueFrom:
      secretKeyRef:
        name: app-secrets
        key: aegis-secret-key
  - name: REDIS_HOST
    value: "redis-cluster.internal"
  - name: REDIS_PORT
    value: "6379"
```

---

## 4. Cloudflare CDN & Reverse Proxy Real-IP Configuration

When running behind Cloudflare or a reverse proxy (e.g. AWS ALB, Traefik, HAProxy), the application must resolve the real visitor IP address rather than the proxy's IP.

### Laravel (`bootstrap/app.php`):
```php
->withMiddleware(function ($middleware) {
    $middleware->trustProxies(at: [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
    ]);
})
```

### Nginx Real-IP Module:
```nginx
set_real_ip_from 103.21.244.0/22;
set_real_ip_from 173.245.48.0/20;
# ... (all Cloudflare CIDRs)
real_ip_header CF-Connecting-IP;
```

---

## 5. Production Security Verification Checklist

- [x] `AEGIS_SECRET_KEY` is at least 32 high-entropy random characters and kept outside version control.
- [x] Challenge endpoint `/aegis/challenge` sets `Cache-Control: no-store, private`.
- [x] Multi-node clusters use `RedisNonceStorage` (never node-local file storage behind load balancers).
- [x] Trusted proxy CIDRs are configured so `bind_ip` hashes actual client IPs.
- [x] Form submission endpoints maintain independent CSRF protection alongside Aegis.
- [x] PoW difficulty is tuned appropriately for mobile and low-powered visitors (Difficulty 3 recommended).
- [x] Standalone test harness (`php tests/run_tests.php`) executes cleanly with 0 failures.
