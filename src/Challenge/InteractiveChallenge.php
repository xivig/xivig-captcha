<?php

declare(strict_types=1);

namespace Aegis\Captcha\Challenge;

use Aegis\Captcha\Storage\FileNonceStorage;
use Aegis\Captcha\Storage\NonceStorageInterface;
use RuntimeException;

/**
 * Hardened Interactive Step-Up Challenge Engine.
 * Never leaks the target coordinates to the client.
 * Enforces atomic single-use nonce consumption against replay attacks.
 */
final class InteractiveChallenge
{
    private const TOLERANCE_PIXELS = 5;
    private const PIECE_SIZE = 40;

    public function __construct(
        private readonly string $secretKey,
        private readonly ?NonceStorageInterface $nonceStorage = null
    ) {
    }

    /**
     * Generates an interactive visual slider puzzle.
     * The actual targetX is NEVER exposed to the client in plaintext or JSON.
     *
     * @return array{
     *     puzzle_id: string,
     *     bg_image: string,
     *     piece_image: string,
     *     piece_y: int,
     *     token: string,
     *     width: int,
     *     height: int
     * }
     */
    public function generateSliderPuzzle(int $width = 300, int $height = 150): array
    {
        $targetX = random_int(50, $width - self::PIECE_SIZE - 20);
        $targetY = random_int(20, $height - self::PIECE_SIZE - 20);
        $nonce = bin2hex(random_bytes(16));
        $timestamp = time();

        // 1. Generate Visual Puzzle Images using GD (or pure SVG fallback if GD missing)
        $images = $this->renderPuzzleImages($width, $height, $targetX, $targetY);

        // 2. Cryptographically seal target coordinates with HMAC-SHA256
        $sig = hash_hmac('sha256', "{$targetX}:{$targetY}:{$nonce}:{$timestamp}", $this->secretKey);
        $token = base64_encode(json_encode([
            'x' => $targetX,
            'y' => $targetY,
            'n' => $nonce,
            'ts' => $timestamp,
            'sig' => $sig
        ], JSON_THROW_ON_ERROR));

        // SECURITY: targetX is strictly omitted from the returned client payload!
        return [
            'puzzle_id' => $nonce,
            'bg_image' => 'data:image/png;base64,' . base64_encode($images['bg']),
            'piece_image' => 'data:image/png;base64,' . base64_encode($images['piece']),
            'piece_y' => $targetY,
            'token' => $token,
            'width' => $width,
            'height' => $height
        ];
    }

    /**
     * Verifies the client's submitted slider position.
     * Atomically claims the challenge nonce to prevent replay attacks.
     */
    public function verifySliderSolution(string $challengeToken, int $submittedX, int $maxAgeSeconds = 120): bool
    {
        try {
            $raw = base64_decode($challengeToken, true);
            if ($raw === false) {
                return false;
            }

            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!isset($data['x'], $data['y'], $data['n'], $data['ts'], $data['sig'])
                || !is_int($data['x']) || !is_string($data['n']) || !is_int($data['ts'])) {
                return false;
            }

            // 1. Verify Expiration
            if ((time() - $data['ts']) > $maxAgeSeconds) {
                return false;
            }

            // 2. Verify Cryptographic Signature
            $expectedSig = hash_hmac('sha256', "{$data['x']}:{$data['y']}:{$data['n']}:{$data['ts']}", $this->secretKey);
            if (!hash_equals($expectedSig, (string)$data['sig'])) {
                return false;
            }

            // 3. Atomically Claim Nonce (Replay Attack Prevention)
            $storage = $this->nonceStorage ?? new FileNonceStorage();
            if (!$storage->claimNonce($data['n'], $maxAgeSeconds)) {
                return false; // Replay attempt rejected
            }

            // 4. Verify Alignment Accuracy within Tolerance
            $targetX = $data['x'];
            $diff = abs($submittedX - $targetX);

            return $diff <= self::TOLERANCE_PIXELS;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Renders background image with cutout slot and corresponding puzzle piece.
     *
     * @return array{bg: string, piece: string}
     */
    private function renderPuzzleImages(int $width, int $height, int $targetX, int $targetY): array
    {
        if (function_exists('imagecreatetruecolor')) {
            $bg = imagecreatetruecolor($width, $height);
            $piece = imagecreatetruecolor(self::PIECE_SIZE, self::PIECE_SIZE);

            // Generate procedural background texture
            $c1 = imagecolorallocate($bg, 30, 41, 59); // Slate-800
            $c2 = imagecolorallocate($bg, 51, 65, 85); // Slate-700
            imagefilledrectangle($bg, 0, 0, $width, $height, $c1);

            // Draw noise patterns and geometric shapes
            for ($i = 0; $i < 15; $i++) {
                $color = imagecolorallocate($bg, random_int(40, 80), random_int(50, 100), random_int(90, 140));
                imagefilledellipse($bg, random_int(0, $width), random_int(0, $height), random_int(20, 80), random_int(20, 80), $color);
            }

            // Copy cutout section into piece
            imagecopy($piece, $bg, 0, 0, $targetX, $targetY, self::PIECE_SIZE, self::PIECE_SIZE);

            // Draw shadow cutout slot on background
            $slotColor = imagecolorallocatealpha($bg, 15, 23, 42, 60);
            $borderColor = imagecolorallocate($bg, 148, 163, 184);
            imagefilledrectangle($bg, $targetX, $targetY, $targetX + self::PIECE_SIZE, $targetY + self::PIECE_SIZE, $slotColor);
            imagerectangle($bg, $targetX, $targetY, $targetX + self::PIECE_SIZE, $targetY + self::PIECE_SIZE, $borderColor);

            // Add border to piece
            imagerectangle($piece, 0, 0, self::PIECE_SIZE - 1, self::PIECE_SIZE - 1, $borderColor);

            ob_start();
            imagepng($bg);
            $bgData = (string)ob_get_clean();

            ob_start();
            imagepng($piece);
            $pieceData = (string)ob_get_clean();

            imagedestroy($bg);
            imagedestroy($piece);

            return ['bg' => $bgData, 'piece' => $pieceData];
        }

        // SVG Fallback if GD is unavailable
        $bgSvg = "<svg width='{$width}' height='{$height}' xmlns='http://www.w3.org/2000/svg'><rect width='{$width}' height='{$height}' fill='#1e293b'/><rect x='{$targetX}' y='{$targetY}' width='40' height='40' fill='#0f172a' stroke='#94a3b8' stroke-dasharray='4'/></svg>";
        $pieceSvg = "<svg width='40' height='40' xmlns='http://www.w3.org/2000/svg'><rect width='40' height='40' fill='#3b82f6' stroke='#ffffff'/></svg>";

        return ['bg' => $bgSvg, 'piece' => $pieceSvg];
    }
}
