<?php

declare(strict_types=1);

namespace Aegis\Captcha\Adapters\Psr15;

use Aegis\Captcha\Aegis;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Standard PSR-15 Middleware for Symfony, Slim, Mezzio, CakePHP, etc.
 */
class AegisPsr15Middleware implements MiddlewareInterface
{
    /**
     * @param list<string> $protectedRoutes List of path prefixes to protect (e.g. ['/api/register', '/contact'])
     */
    public function __construct(
        private readonly Aegis $aegis,
        private readonly array $protectedRoutes = [],
        private readonly ?ResponseInterface $forbiddenResponse = null,
        private readonly ?ResponseFactoryInterface $responseFactory = null,
        private readonly ?StreamFactoryInterface $streamFactory = null
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // 1. Intercept Challenge Endpoint
        if ($path === $this->aegis->getConfig()->challengeEndpoint && $request->getMethod() === 'GET') {
            $clientIp = $this->extractClientIp($request);
            $userAgent = $request->getHeaderLine('User-Agent');
            $challenge = $this->aegis->createChallenge($clientIp, $userAgent);

            $response = $this->createJsonResponse(200, $challenge);
            return $response;
        }

        // 2. Check if route is protected
        if (!in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true) && $this->isRouteProtected($path)) {
            $parsedBody = (array)($request->getParsedBody() ?? []);
            $payloadField = $this->aegis->getConfig()->payloadField;
            $payloadValue = $parsedBody[$payloadField] ?? '';
            $payload = is_string($payloadValue) ? $payloadValue : '';

            $clientIp = $this->extractClientIp($request);
            $userAgent = $request->getHeaderLine('User-Agent');

            $result = $this->aegis->verify($payload, $clientIp, $userAgent);

            if (!$result->isHuman) {
                if ($this->forbiddenResponse !== null) {
                    return $this->forbiddenResponse;
                }
                return $this->createJsonResponse(403, [
                    'success' => false,
                    'error' => 'AegisGuard security check failed: automated activity suspected.',
                    'flags' => $result->flags
                ]);
            }
        }

        return $handler->handle($request);
    }

    private function isRouteProtected(string $path): bool
    {
        if (empty($this->protectedRoutes)) {
            return true;
        }
        foreach ($this->protectedRoutes as $route) {
            if (!is_string($route) || $route === '' || $route[0] !== '/') {
                continue;
            }
            if ($path === $route || str_starts_with($path, rtrim($route, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    private function extractClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        return (string)($serverParams['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    private function createJsonResponse(int $status, array $data): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);

        // 1. Injected PSR-17 Response Factory
        if ($this->responseFactory !== null) {
            $response = $this->responseFactory->createResponse($status)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store, private');

            if ($this->streamFactory !== null) {
                return $response->withBody($this->streamFactory->createStream($json));
            }

            $response->getBody()->write($json);
            return $response;
        }

        // 2. Discover standard PSR-17 factories if installed
        $discoveredFactory = null;
        if (class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)) {
            $discoveredFactory = new \Nyholm\Psr7\Factory\Psr17Factory();
        } elseif (class_exists(\GuzzleHttp\Psr7\HttpFactory::class)) {
            $discoveredFactory = new \GuzzleHttp\Psr7\HttpFactory();
        } elseif (class_exists(\Laminas\Diactoros\ResponseFactory::class)) {
            $respFactory = new \Laminas\Diactoros\ResponseFactory();
            $streamFactory = class_exists(\Laminas\Diactoros\StreamFactory::class) ? new \Laminas\Diactoros\StreamFactory() : null;
            $response = $respFactory->createResponse($status)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store, private');
            if ($streamFactory !== null) {
                return $response->withBody($streamFactory->createStream($json));
            }
            $response->getBody()->write($json);
            return $response;
        }

        if ($discoveredFactory !== null) {
            return $discoveredFactory->createResponse($status)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'no-store, private')
                ->withBody($discoveredFactory->createStream($json));
        }

        // 3. Fallback direct Nyholm response constructor
        if (class_exists(\Nyholm\Psr7\Response::class)) {
            return new \Nyholm\Psr7\Response(
                $status,
                ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store, private'],
                $json
            );
        }

        throw new \RuntimeException("A PSR-17 ResponseFactoryInterface (and optional StreamFactoryInterface) must be provided to AegisPsr15Middleware when running without Nyholm, Guzzle, or Laminas PSR-7 libraries.");
    }
}
