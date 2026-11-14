<?php

declare(strict_types=1);

namespace Aegis\Captcha\Adapters\Laravel;

use Aegis\Captcha\Adapters\Laravel\Middleware\AegisProtectMiddleware;
use Aegis\Captcha\Aegis;
use Aegis\Captcha\Config\AegisConfig;
use Aegis\Captcha\Storage\FileNonceStorage;
use Aegis\Captcha\Storage\Psr16NonceStorage;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class AegisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../../config/aegis.php', 'aegis');

        $this->app->singleton('aegis.captcha', function ($app): Aegis {
            $options = $app['config']->get('aegis', []);
            $secret = $options['secret_key'] ?? $app['config']->get('app.key');
            if (!is_string($secret) || $secret === '') {
                throw new RuntimeException('Set AEGIS_SECRET_KEY or configure a valid Laravel APP_KEY.');
            }
            if (str_starts_with($secret, 'base64:')) {
                $decoded = base64_decode(substr($secret, 7), true);
                if ($decoded === false) {
                    throw new RuntimeException('Laravel APP_KEY has an invalid base64 encoding.');
                }
                $secret = $decoded;
            }
            $options['secret_key'] = $secret;
            $config = AegisConfig::fromArray($options);

            $cache = $app->bound('cache.store') ? $app->make('cache.store') : null;
            $storage = $cache
                ? new Psr16NonceStorage($cache, 'aegis:' . hash('sha256', $secret) . ':')
                : new FileNonceStorage(storage_path('framework/cache/aegis-nonces'));
            return new Aegis($config, $storage);
        });

        $this->app->alias('aegis.captcha', Aegis::class);
    }

    public function boot(): void
    {
        // 1. Publishable Configuration & Assets
        $this->publishes([
            __DIR__ . '/../../../config/aegis.php' => config_path('aegis.php'),
        ], 'aegis-config');
        $this->publishes([
            __DIR__ . '/../../../assets' => public_path('vendor/aegis'),
        ], 'aegis-assets');

        // 2. Automatic Middleware Alias Registration (Laravel 10, 11, 12, 13)
        if ($this->app->bound('router')) {
            $this->app->make('router')->aliasMiddleware('aegis', AegisProtectMiddleware::class);
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        // 3. Challenge Endpoint
        \Illuminate\Support\Facades\Route::get(config('aegis.challenge_endpoint', '/aegis/challenge'), function () {
            $challenge = app('aegis.captcha')->createChallenge(
                request()->ip() ?? '', request()->userAgent() ?? ''
            );
            return response()->json($challenge)->header('Cache-Control', 'no-store, private');
        })->middleware('web')->name('aegis.challenge');

        // 4. Static Asset Routes (Direct fallback if assets not published)
        \Illuminate\Support\Facades\Route::get('/vendor/aegis/js/aegis-worker.js', function () {
            return response()->file(__DIR__ . '/../../../assets/js/aegis-worker.js', [
                'Content-Type' => 'application/javascript',
                'Cache-Control' => 'public, max-age=86400'
            ]);
        });

        // 5. Blade Directives
        \Illuminate\Support\Facades\Blade::directive('aegisWidget', function ($expression) {
            return "<?php echo app('aegis.captcha')->renderWidget($expression); ?>";
        });
        \Illuminate\Support\Facades\Blade::directive('aegisScript', function ($expression) {
            return "<?php echo app('aegis.captcha')->renderScript($expression); ?>";
        });
        \Illuminate\Support\Facades\Blade::directive('aegisStyle', function ($expression) {
            return "<?php echo app('aegis.captcha')->renderStyle($expression); ?>";
        });
    }
}
