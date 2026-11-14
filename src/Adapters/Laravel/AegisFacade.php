<?php

declare(strict_types=1);

namespace Aegis\Captcha\Adapters\Laravel;

use Illuminate\Support\Facades\Facade;

/** @method static array createChallenge(string $clientIp, string $userAgent, ?int $difficulty = null)
 *  @method static \Aegis\Captcha\Http\AegisResponse verify(string $base64Payload, string $clientIp, string $userAgent)
 *  @method static string renderWidget(string $containerId = 'aegis-container')
 *  @method static string renderScript(string $assetUrl = '/vendor/aegis/js/aegis.js')
 *  @method static string renderStyle(string $assetUrl = '/vendor/aegis/css/aegis.css')
 */
final class AegisFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'aegis.captcha';
    }
}
