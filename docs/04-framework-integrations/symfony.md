# Framework Integration: Symfony (v6 / v7)

Integrate Xivig CAPTCHA cleanly into Symfony using a dedicated Controller, Service configuration, or Form Type.

---

## 1. Register Services (`config/services.yaml`)

Define the Aegis engine service in `config/services.yaml`:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    Aegis\Captcha\Config\AegisConfig:
        arguments:
            $secretKey: '%env(AEGIS_SECRET_KEY)%'
            $powDifficulty: 3
            $challengeEndpoint: '/aegis/challenge'

    Aegis\Captcha\Storage\RedisNonceStorage:
        arguments:
            $redis: '@snc_redis.default' # or native \Redis instance

    Aegis\Captcha\Aegis:
        arguments:
            $config: '@Aegis\Captcha\Config\AegisConfig'
            $storage: '@Aegis\Captcha\Storage\RedisNonceStorage'
```

---

## 2. Challenge Endpoint Controller

Create a controller to serve challenge requests:

```php
namespace App\Controller;

use Aegis\Captcha\Aegis;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class CaptchaController extends AbstractController
{
    #[Route('/aegis/challenge', name: 'aegis_challenge', methods: ['GET'])]
    public function challenge(Request $request, Aegis $aegis): JsonResponse
    {
        $clientIp = $request->getClientIp() ?? '127.0.0.1';
        $userAgent = $request->headers->get('User-Agent', 'Unknown');

        $challenge = $aegis->createChallenge($clientIp, $userAgent);

        $response = new JsonResponse($challenge);
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }
}
```

---

## 3. Controller Form Verification

In your form handling action:

```php
namespace App\Controller;

use Aegis\Captcha\Aegis;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AuthController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request, Aegis $aegis): Response
    {
        if ($request->isMethod('POST')) {
            $payload = (string)$request->request->get('aegis_payload', '');
            $clientIp = $request->getClientIp() ?? '127.0.0.1';
            $userAgent = $request->headers->get('User-Agent', 'Unknown');

            $result = $aegis->verify($payload, $clientIp, $userAgent);

            if (!$result->isHuman) {
                $this->addFlash('error', 'Anti-bot verification failed. Please try again.');
                return $this->redirectToRoute('app_login');
            }

            // Authentication logic continues...
        }

        return $this->render('auth/login.html.twig');
    }
}
```

---

## 4. Twig Template

```twig
{# templates/auth/login.html.twig #}
{% extends 'base.html.twig' %}

{% block stylesheets %}
    {{ parent() }}
    <link rel="stylesheet" href="{{ asset('vendor/aegis/css/aegis.css') }}">
{% endblock %}

{% block body %}
<div class="login-box">
    <h2>Secure Login</h2>
    
    {% for message in app.flashes('error') %}
        <div class="alert alert-danger">{{ message }}</div>
    {% endfor %}

    <form method="POST" action="{{ path('app_login') }}">
        <input type="hidden" name="_csrf_token" value="{{ csrf_token('authenticate') }}">

        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="_username" required>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="_password" required>
        </div>

        <!-- Captcha Container -->
        <div id="aegis-container"></div>

        <button type="submit" class="btn btn-primary">Log In</button>
    </form>
</div>
{% endblock %}

{% block javascripts %}
    {{ parent() }}
    <script src="{{ asset('vendor/aegis/js/aegis.js') }}" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            new Aegis({
                endpoint: '{{ path('aegis_challenge') }}'
            });
        });
    </script>
{% endblock %}
```
