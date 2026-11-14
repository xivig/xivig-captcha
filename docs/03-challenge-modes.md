# 03. Challenge Modes

Xivig CAPTCHA v2.0 features three distinct challenge mechanisms designed for balanced security, accessibility, and user experience.

---

## 1. Mode A: Invisible Proof of Work (Default)

The default challenge mode operates silently in the background with zero user friction.

### How It Works:
1. When the user navigates to a protected form, `aegis.js` requests a challenge from `/aegis/challenge`.
2. The server generates a high-entropy seed and an authenticated AES-256-GCM token containing the client IP subnet, User-Agent hash, creation timestamp, and a single-use nonce.
3. `aegis.js` delegates the computation to a dedicated Web Worker (`assets/js/aegis-worker.js`), solving `SHA256(seed + nonce)` to find a hash meeting the configured difficulty.
4. The solved nonce and client duration are placed into the hidden `aegis_payload` input.
5. If the user submits the form before the computation finishes, `aegis.js` holds the submission event, displays an accessible spinner, and resumes form submission via `form.requestSubmit()` the instant the token is ready.

### Browser Fallback:
If Web Workers or `Blob` construction are blocked by a strict Content Security Policy (CSP), `aegis.js` falls back to a time-sliced, yielding main-thread solver using `setTimeout(..., 0)` to guarantee that the UI never freezes.

---

## 2. Mode B: Interactive Visual Slider Puzzle (Step-Up)

When an IP address triggers elevated risk flags or request frequency spikes, the application can step up verification using the interactive visual slider puzzle.

### Security Architecture & Zero Answer Leakage:
Traditional slider captchas often leak the target coordinates to the browser in JavaScript variables, CSS offsets, or network responses, allowing bots to bypass the puzzle via simple script inspection.

**Xivig CAPTCHA strictly prevents answer leakage:**
- **Server-Side Procedural Generation**: The server generates a randomized background canvas (300x150px) and cuts out a 40x40px piece at secret coordinates `(targetX, targetY)` using PHP GD (with SVG fallback).
- **Masked Cutout**: A shaded cutout slot is etched into the background image. The separated piece is rendered on a transparent overlay.
- **HMAC Sealed Coordinates**: The secret coordinates `targetX` and `targetY` are sealed into an HMAC-SHA256 token along with an ephemeral nonce and expiration timestamp.
- **Client Receives Only Images**: The network response contains only `bg_image` (Base64 PNG/SVG), `piece_image`, `piece_y`, and the sealed `token`. The horizontal target coordinate `targetX` is **never transmitted to the client**.

### Server-Side Verification:
```php
use Aegis\Captcha\Challenge\InteractiveChallenge;

$interactive = new InteractiveChallenge($secretKey, $nonceStorage);

// 1. Generate challenge images and sealed token
$puzzle = $interactive->generateSliderPuzzle(width: 300, height: 150);

// 2. Verify client-submitted horizontal slider coordinate
$isValid = $interactive->verifySliderSolution(
    challengeToken: $puzzle['token'],
    submittedX: (int)$_POST['slider_x'],
    maxAgeSeconds: 120
);
```

- **Tolerance**: The server verifies that the user's submitted position matches the true hidden `targetX` within $\pm 5$ pixels.
- **Atomic Replay Prevention**: The challenge nonce is consumed atomically on verification; identical tokens cannot be replayed.

---

## 3. Mode C: Accessible Auditory Challenge (Screen-Reader Fallback)

To ensure full compliance with **WCAG 2.1 AA** standards for visually impaired visitors and screen-reader users, Xivig CAPTCHA includes a native acoustic challenge engine.

### Zero-Dependency Audio Synthesis:
The `AudioChallenge` engine synthesizes 16-bit uncompressed PCM WAV audio directly in native PHP:
- Generates 3 to 6 randomized digits (0–9).
- Synthesizes distinct acoustic frequency tones (300Hz to 2400Hz) modulated with harmonic frequencies and pink background noise.
- Assembles valid standard RIFF WAV headers and Base64 encodes the binary audio stream.
- Zero external binary requirements: does not require `ffmpeg`, `sox`, or external cloud TTS APIs.

### Security & Privacy:
- **No Plaintext Leaks**: The plaintext digits are never returned in the client response payload.
- **HMAC Token Sealing**: The expected digits are cryptographically sealed with the server's master key and a unique nonce.
- **Single-Use Replay Protection**: Atomically claims the nonce upon the first verification attempt.

### PHP Usage:
```php
use Aegis\Captcha\Challenge\AudioChallenge;

$audio = new AudioChallenge($secretKey, $nonceStorage);

// 1. Generate synthesized WAV and sealed token
$challenge = $audio->generate(length: 4);
// $challenge['audio_wav'] contains 'data:audio/wav;base64,...'
// $challenge['token'] contains the sealed HMAC token

// 2. Verify submitted audio code
$isValid = $audio->verify(
    token: $challenge['token'],
    submittedDigits: (string)$_POST['audio_code'],
    maxAgeSeconds: 180
);
```
