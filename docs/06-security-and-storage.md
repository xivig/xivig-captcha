# 06. Security & Storage Architecture

Xivig CAPTCHA is engineered around zero-trust verification, cryptographic isolation, and resilient distributed storage.

---

## 1. Cryptographic Token Architecture

Every challenge issued by the server is sealed in an **AES-256-GCM authenticated ciphertext**:

```text
[ Master Secret (32+ bytes) ]
           │
           ▼
[ HKDF-SHA256 Derivation ]
   Info: "XIVIG-AEGIS-KEY-DERIVATION-V1"
   Salt: 32 bytes cryptographically secure pseudo-random bytes
           │
           ▼
[ AES-256-GCM Encryption ]
   Ciphertext: { seed, client_ip_subnet, user_agent_hash, nonce, expires_at }
   AAD: "XIVIG-AEGIS-TOKEN-V1"
   Tag: 16 bytes GCM authentication tag
```

- **Key Isolation**: The encryption key is derived dynamically via HKDF with package-specific context, preventing key reuse across different components.
- **Authenticated Additional Data (AAD)**: Hardened with versioned AAD (`XIVIG-AEGIS-TOKEN-V1`), ensuring ciphertexts cannot be spliced or reused in differing contexts.
- **Client Binding**: Tokens are cryptographically bound to the client's `/24` IPv4 or `/64` IPv6 subnet and SHA-256 User-Agent signature.

---

## 2. Replay Protection & Atomic Storage

To prevent replay attacks, every token contains a high-entropy 128-bit cryptographic nonce that must be claimed single-use.

### Primary: Native Redis `SET NX EX` (`RedisNonceStorage.php`)
```php
$redis->set("aegis:nonce:{$nonceHash}", "1", ['NX', 'EX' => $ttlSeconds]);
```
- Executes atomically in a single network round-trip.
- Compatible with standalone PhpRedis, RedisCluster, and Predis.
- Zero race condition windows: two simultaneous requests submitting the exact same token will result in one success and one immediate rejection.

### Fallback: Kernel-Locked Filesystem Storage (`FileNonceStorage.php`)
For shared hosting or single-server VPS environments without Redis:
- Uses a dedicated lock file (`.aegis.lock`) with exclusive blocking file locking (`flock(LOCK_EX)`).
- Serializes token claims and garbage collection.
- Cross-platform portability: On Windows NTFS where standard directory write permissions fail, an automatic write-probe validates private directory accessibility.

---

## 3. Multi-Process Rate Limiting (`RateLimiter.php`)

To prevent attackers from flooding the `/aegis/challenge` endpoint:
- **Distributed Redis Lua Script**: Implements an atomic sliding-window log using `ZREMRANGEBYSCORE` and `ZCARD`.
- **Durable File Locking (`POLICY_DURABLE_FILE`)**: Under PHP-FPM, Apache mod_php, or multi-process pools, simple in-memory arrays are isolated per worker process. The durable file policy synchronizes sliding-window request timestamps using atomic file locking across all worker processes.
- **Fail-Closed Policy (`POLICY_FAIL_CLOSED`)**: Option to reject requests during complete cache/storage failure on high-security endpoints.

---

## 4. Adaptive Difficulty Engine (`AdaptiveDifficulty.php`)

```text
Requests / Minute (Per Subnet)       Assigned PoW Difficulty      Expected Solve Time
─────────────────────────────────────────────────────────────────────────────────────
1 - 3 requests / min                 Difficulty 3 (Default)       ~15 ms
4 - 9 requests / min                 Difficulty 4 (Elevated)      ~80 ms
10+ requests / min (Spike)           Difficulty 5 (Maximum)       ~350 ms
```

The server calculates a sliding-window moving average of request frequency per IP subnet. When automated scraping or credential stuffing attacks trigger burst loads, difficulty automatically steps up to impose computational costs on attackers while keeping legitimate traffic fast.

---

## 5. Denial-of-Service Defense & Error Disclosure

- **Payload Size Ceiling**: Incoming payloads are strictly capped at 16,384 bytes (16KB) before Base64 decoding, preventing memory exhaustion attacks via inflated JSON strings.
- **Max JSON Nesting**: Constrained to depth 8 to prevent recursive object parsing attacks.
- **Sanitized Error Disclosure**: Internal exceptions, stack traces, and decryption error details are never returned in HTTP responses. Client responses only receive standardized generic error flags:
  - `VERIFICATION_FAILED`
  - `PAYLOAD_TOO_LARGE`
  - `MALFORMED_PAYLOAD`
