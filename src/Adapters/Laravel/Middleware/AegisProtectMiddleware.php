<?php

declare(strict_types=1);

namespace Aegis\Captcha\Adapters\Laravel\Middleware;

use Aegis\Captcha\Aegis;
use Closure;

/**
 * Route protection middleware for Laravel.
 */
class AegisProtectMiddleware
{
    public function __construct(
        private readonly Aegis $aegis
    ) {
    }

    public function handle(mixed $request, Closure $next): mixed
    {
        $payloadField = $this->aegis->getConfig()->payloadField;
        $payloadValue = $request->input($payloadField, '');
        $payload = is_string($payloadValue) ? $payloadValue : '';

        $clientIp = (string)($request->ip() ?? '127.0.0.1');
        $userAgent = (string)($request->userAgent() ?? 'Unknown');

        $response = $this->aegis->verify($payload, $clientIp, $userAgent);

        if (!$response->isHuman) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Automated bot activity detected by AegisGuard.',
                    'flags' => $response->flags
                ], 403)->header('Cache-Control', 'no-store, private');
            }

            return back()->withErrors([
                $payloadField => 'Security challenge failed: automated or AI bot behavior detected.'
            ])->withInput();
        }

        return $next($request);
    }
}
