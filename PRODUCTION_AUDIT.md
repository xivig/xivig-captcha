# Xivig CAPTCHA / Aegis — Production Audit

**Audit date:** 2026-11-15  
**Scope:** Uploaded archive source, PHP adapters, browser SDK, configuration, documentation and included tests.

## Executive assessment

The original archive was a useful prototype, but it was not safe to describe as production-ready. The most serious issue was that the PSR-16 nonce adapter used a non-atomic `has()` then `set()` sequence, allowing concurrent replay. The Laravel provider also did not extend Laravel's service-provider base class, and its publish methods were stubs. The browser implementation had unbounded proof-of-work and gathered unnecessary fingerprinting data. Risk scoring treated spoofable client telemetry as strong evidence of humanity.

This revision hardens the core and adapters, reduces privacy-invasive telemetry, and corrects documentation. It is a **production-oriented hardening pass, not a security certification**. It still needs integration tests in the target Laravel/PSR-15 applications, load tests against the actual cache backend, browser/device testing, and an independent security review.

## Changes made

### Cryptography and request validation
- Derives the AES-256-GCM key using HKDF with a package-specific context.
- Adds versioned authenticated associated data to AES-GCM.
- Validates challenge difficulty, token encoding, token claims, timestamp types, nonce format and seed format.
- Limits encoded verification payload size and JSON nesting depth; validates the payload shape and telemetry container.
- Replaces client-visible exception details with a generic verification failure flag.
- Uses SHA-256 for User-Agent binding instead of relying on a less universal hash algorithm.
- Rejects malformed challenge endpoint paths and invalid payload field names.

### Replay protection and storage
- Replaces unsafe cache `has()` + `set()` with atomic `add()` and refuses cache implementations that cannot provide it.
- Namespaces Laravel cache keys by a secret-derived prefix and hashes nonce keys.
- Reworks local file storage around a private directory and a shared lock that serializes claims and garbage collection, preventing the prior unlink/claim inode race.
- Documents that generic PSR-16 does not guarantee atomic create-if-absent and that node-local files are unsuitable for multi-node deployments.

### Laravel and adapters
- Converts the Laravel provider into a real `Illuminate\Support\ServiceProvider`.
- Uses inherited configuration merging and publication APIs.
- Removes the insecure hard-coded fallback secret.
- Uses the container for rule resolution rather than relying on an uninitialized global singleton.
- Makes the validation rule implement Laravel's `ValidationRule` contract.
- Implements a real Laravel Facade.
- Adds style asset rendering and a Blade directive.
- Adds no-store behavior to challenge responses and hardens payload type handling.
- Tightens PSR-15 path-prefix matching and protects unsafe HTTP methods rather than POST only.

### Browser SDK and privacy
- Removes canvas and WebGL fingerprint collection and pointer trajectory collection.
- Uses only minimal client-side risk hints; these remain explicitly untrusted.
- Caps proof-of-work difficulty at 5 and work at a finite iteration budget.
- Adds timeout/abort handling and worker cleanup, with a yielding fallback.
- Prevents form submission before the hidden challenge payload is ready, then resumes submission after success.
- Adds retry, live status announcements, keyboard focus styling and reduced-motion support.
- Avoids inserting server/error text with `innerHTML`.

### Documentation and tests
- Removes claims that the package is AI-proof, that client telemetry is biometric proof, or that fixed PoW timings are guaranteed.
- Documents the actual security boundary and unimplemented compatibility settings.
- Adds production deployment guidance for rate limiting, CSRF, trusted proxies, atomic shared storage and low-end devices.
- Updates the standalone test harness and PHPUnit expectations.

## Validation performed

| Check | Result |
|---|---|
| PHP syntax lint | 26 PHP files passed |
| JavaScript syntax check (`node --check`) | Passed |
| Standalone test harness | 10 passed, 0 failed |
| End-to-end challenge issuance and verification | Passed |
| Replay rejection | Passed |
| Token tampering rejection | Passed |
| IP binding mismatch rejection | Passed |
| Oversized payload rejection | Passed |
| Local file nonce single-use | Passed |
| Atomic cache adapter single-use | Passed with a test double exposing atomic `add()` |

PHPUnit itself and Composer validation were not run because those executables/dependencies were not available in the audit environment. Laravel and PSR-15 integration were syntax-checked but not booted against real framework installations.

## Remaining production gates

1. **This is not a visual CAPTCHA.** The current widget is an invisible proof-of-work check with a status badge. `mode`, `block_datacenter_ips`, and `ip_whitelist` remain reserved/compatibility settings; they do not implement an interactive puzzle, ASN intelligence or whitelist enforcement.
2. **Do not use as the only anti-bot layer.** Proof of Work can be solved by scripts and bypassed by determined attackers. Add rate limiting at the challenge and protected endpoints, CSRF, account-level throttles, monitoring and application-specific fraud controls.
3. **Validate real distributed storage.** Test the actual Redis/cache driver's atomic `add()` semantics under concurrent requests and across nodes. Provide a custom `NonceStorageInterface` if your cache does not expose atomic add.
4. **Test browser compatibility.** Validate Web Crypto, worker CSP behavior, timeout behavior, form resubmission, Safari/iOS and low-end Android devices. Offer a non-JavaScript or managed accessible alternative.
5. **Validate proxy configuration.** IP binding depends on the framework's trusted-proxy configuration. Never trust arbitrary forwarded headers.
6. **Review operational behavior.** Load-test challenge issuance, cache contention, file-store cleanup, rate limits, false positives and challenge completion time.
7. **Independent review.** Have the final release reviewed for threat model, concurrency, deployment configuration, privacy notice and applicable legal requirements.

## Release recommendation

Use this revision as a hardened release candidate for staging. Do not market it as “AI-resistant”, “bot-proof”, or a replacement for a managed CAPTCHA/risk platform. Promote to production only after the remaining gates above are completed in the actual hosting environment.
