# Framework Integration: PSR-15 (Slim 4, Mezzio, Laminas, CakePHP)

The `AegisPsr15Middleware` adapter implements the standard `Psr\Http\Server\MiddlewareInterface`. It supports any framework adhering to PSR-7, PSR-15, and PSR-17 standards.

---

## 1. Middleware Architecture & PSR-17 Dependency Injection

The middleware constructor accepts optional PSR-17 `ResponseFactoryInterface` and `StreamFactoryInterface` instances. If omitted, it automatically discovers installed PSR-17 packages (Nyholm, Guzzle, or Laminas).

```php
use Aegis\Captcha\Adapters\Psr15\AegisPsr15Middleware;
use Aegis\Captcha\Aegis;

$middleware = new AegisPsr15Middleware(
    aegis: $aegis,
    protectedRoutes: ['/api/login', '/checkout', '/contact'], // Path prefixes to protect
    forbiddenResponse: null,                                   // Optional custom 403 Response
    responseFactory: $container->get(\Psr\Http\Message\ResponseFactoryInterface::class),
    streamFactory: $container->get(\Psr\Http\Message\StreamFactoryInterface::class)
);
```

---

## 2. Slim Framework 4 Example

```php
use Slim\Factory\AppFactory;
use Aegis\Captcha\Aegis;
use Aegis\Captcha\Config\AegisConfig;
use Aegis\Captcha\Adapters\Psr15\AegisPsr15Middleware;

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();

// 1. Initialize Aegis
$config = new AegisConfig(
    secretKey: getenv('AEGIS_SECRET_KEY') ?: 'secret-key-at-least-32-chars-long!!',
    challengeEndpoint: '/aegis/challenge'
);
$aegis = new Aegis($config);

// 2. Register Global or Route-Specific Middleware
$aegisMiddleware = new AegisPsr15Middleware(
    aegis: $aegis,
    protectedRoutes: ['/login', '/register', '/api/submit']
);
$app->add($aegisMiddleware);

// 3. Define Routes
$app->post('/login', function ($request, $response) {
    // If request reached here, Aegis verification passed!
    $data = (array)$request->getParsedBody();
    $response->getBody()->write(json_encode(['status' => 'authenticated']));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
```

---

## 3. Laminas Mezzio Example

In `config/pipeline.php`:

```php
use Aegis\Captcha\Adapters\Psr15\AegisPsr15Middleware;
use Mezzio\Application;
use Mezzio\Handler\NotFoundHandler;

return function (Application $app): void {
    $app->pipe(\Mezzio\Router\Middleware\RouteMiddleware::class);
    
    // Pipe Aegis security middleware before dispatching handlers
    $app->pipe(AegisPsr15Middleware::class);
    
    $app->pipe(\Mezzio\Router\Middleware\DispatchMiddleware::class);
    $app->pipe(NotFoundHandler::class);
};
```

---

## 4. CakePHP (v4 / v5) Example

In `src/Application.php`:

```php
namespace App;

use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use Aegis\Captcha\Aegis;
use Aegis\Captcha\Config\AegisConfig;
use Aegis\Captcha\Adapters\Psr15\AegisPsr15Middleware;

class Application extends BaseApplication
{
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $config = new AegisConfig(secretKey: env('AEGIS_SECRET_KEY'));
        $aegis = new Aegis($config);

        $middlewareQueue->add(new AegisPsr15Middleware(
            aegis: $aegis,
            protectedRoutes: ['/users/login', '/contact-us']
        ));

        return $middlewareQueue;
    }
}
```
