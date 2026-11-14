<?php

declare(strict_types=1);

namespace Aegis\Captcha\Challenge;

use Aegis\Captcha\Storage\FileNonceStorage;
use Aegis\Captcha\Storage\NonceStorageInterface;

/**
 * Accessibility-First Auditory Challenge Engine.
 * Never exposes the plain answer in generation output.
 * Enforces atomic single-use nonce consumption against replay attacks.
 */
final class AudioChallenge
{
    public function __construct(
        private readonly string $secretKey,
        private readonly ?NonceStorageInterface $nonceStorage = null
    ) {
    }

    /**
     * Generates a numeric audio challenge.
     * SECURITY: The expected digits are NEVER returned in plaintext.
     *
     * @return array{token: string, audio_wav: string}
     */
    public function generate(int $length = 4): array
    {
        $length = max(3, min(6, $length));
        $digits = '';
        for ($i = 0; $i < $length; $i++) {
            $digits .= (string)random_int(0, 9);
        }

        $nonce = bin2hex(random_bytes(16));
        $timestamp = time();
        $sig = hash_hmac('sha256', "{$digits}:{$nonce}:{$timestamp}", $this->secretKey);

        $token = base64_encode(json_encode([
            'd' => $digits,
            'n' => $nonce,
            'ts' => $timestamp,
            'sig' => $sig
        ], JSON_THROW_ON_ERROR));

        $wavData = $this->synthesizePcmWav($digits);

        // Plaintext digits are strictly omitted!
        return [
            'token' => $token,
            'audio_wav' => 'data:audio/wav;base64,' . base64_encode($wavData)
        ];
    }

    /**
     * Verifies the user's submitted audio answer.
     * Atomically claims the challenge nonce to prevent replay attacks.
     */
    public function verify(string $token, string $submittedDigits, int $maxAgeSeconds = 180): bool
    {
        try {
            $raw = base64_decode($token, true);
            if ($raw === false) {
                return false;
            }

            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!isset($data['d'], $data['n'], $data['ts'], $data['sig'])
                || !is_string($data['d']) || !is_string($data['n']) || !is_int($data['ts'])) {
                return false;
            }

            // 1. Verify Expiration
            if ((time() - (int)$data['ts']) > $maxAgeSeconds) {
                return false;
            }

            // 2. Verify Cryptographic HMAC Signature
            $expectedSig = hash_hmac('sha256', "{$data['d']}:{$data['n']}:{$data['ts']}", $this->secretKey);
            if (!hash_equals($expectedSig, (string)$data['sig'])) {
                return false;
            }

            // 3. Atomically Claim Nonce (Replay Attack Prevention)
            $storage = $this->nonceStorage ?? new FileNonceStorage();
            if (!$storage->claimNonce($data['n'], $maxAgeSeconds)) {
                return false; // Replay attempt rejected
            }

            // 4. Constant-Time Digits Comparison
            return hash_equals((string)$data['d'], trim($submittedDigits));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Generates an accessible, distinct multi-tone acoustic WAV stream for the digits.
     */
    private function synthesizePcmWav(string $digits): string
    {
        $sampleRate = 8000;
        $pcm = '';

        // Base frequencies for digits 0-9 (Hz)
        $freqs = [
            '0' => 440, '1' => 494, '2' => 523, '3' => 587, '4' => 659,
            '5' => 698, '6' => 784, '7' => 880, '8' => 988, '9' => 1046
        ];

        foreach (str_split($digits) as $char) {
            $freq = $freqs[$char] ?? 440;
            $duration = 0.35; // 350ms tone
            $numSamples = (int)($sampleRate * $duration);

            for ($i = 0; $i < $numSamples; $i++) {
                $t = $i / $sampleRate;
                $sample = (int)(sin(2 * M_PI * $freq * $t) * 16000);
                $pcm .= pack('v', $sample);
            }

            // 150ms pause
            $pauseSamples = (int)($sampleRate * 0.15);
            for ($i = 0; $i < $pauseSamples; $i++) {
                $pcm .= pack('v', 0);
            }
        }

        // Construct 44-byte WAV header
        $dataSize = strlen($pcm);
        $header = 'RIFF' . pack('V', 36 + $dataSize) . 'WAVEfmt '
            . pack('V', 16)         // SubChunk1Size (16 for PCM)
            . pack('v', 1)          // AudioFormat (1 = PCM)
            . pack('v', 1)          // NumChannels (1 = Mono)
            . pack('V', $sampleRate)// SampleRate
            . pack('V', $sampleRate * 2) // ByteRate
            . pack('v', 2)          // BlockAlign (2 bytes per sample)
            . pack('v', 16)         // BitsPerSample (16-bit)
            . 'data' . pack('V', $dataSize);

        return $header . $pcm;
    }
}
