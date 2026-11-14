<?php

declare(strict_types=1);

namespace Aegis\Captcha\Adapters\Native;

use Aegis\Captcha\Aegis;
use Aegis\Captcha\Config\AegisConfig;
use Aegis\Captcha\Http\AegisResponse;

/**
 * Convenience drop-in helper for Vanilla PHP, WordPress, and custom scripts.
 */
final class NativeAegis
{
    private static ?Aegis $aegis = null;

    public static function setup(string $secretKey, array $options = []): Aegis
    {
        $options['secret_key'] = $secretKey;
        $config = AegisConfig::fromArray($options);
        self::$aegis = Aegis::init($config);
        return self::$aegis;
    }

    /**
     * Handles challenge generation if request matches the challenge route.
     * Call this at the very top of your routing or entrypoint script.
     */
    public static function handleChallengeEndpoint(): void
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $aegis = self::getAegis();

        if ($uri === $aegis->getConfig()->challengeEndpoint && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            echo json_encode($aegis->createChallenge($ip, $ua), JSON_THROW_ON_ERROR);
            exit;
        }
    }

    /**
     * Verifies the POST submission in Native PHP.
     */
    public static function verifyPost(): AegisResponse
    {
        $aegis = self::getAegis();
        $field = $aegis->getConfig()->payloadField;
        $payload = (string)($_POST[$field] ?? '');

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        return $aegis->verify($payload, $ip, $ua);
    }

    public static function renderWidget(): void
    {
        echo self::getAegis()->renderWidget();
    }

    public static function renderScript(): void
    {
        echo self::getAegis()->renderScript();
    }

    private static function getAegis(): Aegis
    {
        if (self::$aegis === null) {
            self::$aegis = Aegis::getInstance();
        }
        return self::$aegis;
    }
}
