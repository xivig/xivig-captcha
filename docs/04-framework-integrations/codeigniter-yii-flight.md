# Framework Integration: CodeIgniter 4, Yii 2/3 & Flight PHP

---

## 1. CodeIgniter 4

In CodeIgniter 4, use an HTTP Filter to intercept protected routes.

### Step 1: Create Filter (`app/Filters/AegisFilter.php`)
```php
namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Aegis\Captcha\Aegis;
use Aegis\Captcha\Config\AegisConfig;

class AegisFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $config = new AegisConfig(secretKey: env('AEGIS_SECRET_KEY'));
        $aegis = new Aegis($config);

        // Handle Challenge API
        if ($request->getPath() === 'aegis/challenge') {
            $ip = $request->getIPAddress();
            $ua = (string)$request->getUserAgent();
            $challenge = $aegis->createChallenge($ip, $ua);

            return service('response')
                ->setHeader('Cache-Control', 'no-store, private')
                ->setJSON($challenge);
        }

        // Validate POST requests
        if ($request->getMethod() === 'post') {
            $payload = (string)$request->getPost('aegis_payload');
            $ip = $request->getIPAddress();
            $ua = (string)$request->getUserAgent();

            $result = $aegis->verify($payload, $ip, $ua);
            if (!$result->isHuman) {
                return service('response')
                    ->setStatusCode(403)
                    ->setJSON(['error' => 'Security challenge failed: automated activity suspected.']);
            }
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
```

### Step 2: Register Filter (`app/Config/Filters.php`)
```php
public array $aliases = [
    'aegis' => \App\Filters\AegisFilter::class,
];
```

---

## 2. Yii 2 & Yii 3

In Yii 2 / 3, attach an `ActionFilter` behavior to your controllers.

```php
namespace app\components;

use yii\base\ActionFilter;
use yii\web\ForbiddenHttpException;
use Aegis\Captcha\Aegis;
use Aegis\Captcha\Config\AegisConfig;

class AegisActionFilter extends ActionFilter
{
    public array $protectedActions = ['login', 'signup', 'contact'];

    public function beforeAction($action)
    {
        if (in_array($action->id, $this->protectedActions, true) && \Yii::$app->request->isPost) {
            $config = new AegisConfig(secretKey: \Yii::$app->params['aegisSecretKey']);
            $aegis = new Aegis($config);

            $payload = (string)\Yii::$app->request->post('aegis_payload', '');
            $ip = \Yii::$app->request->userIP ?? '127.0.0.1';
            $ua = \Yii::$app->request->userAgent ?? 'Unknown';

            $result = $aegis->verify($payload, $ip, $ua);

            if (!$result->isHuman) {
                throw new ForbiddenHttpException('Security check failed: automated activity detected.');
            }
        }

        return parent::beforeAction($action);
    }
}
```

---

## 3. Flight PHP

In Flight PHP, register hooks using `Flight::before()`:

```php
require 'vendor/autoload.php';

use Aegis\Captcha\Aegis;
use Aegis\Captcha\Config\AegisConfig;

$config = new AegisConfig(secretKey: 'flight-php-secret-key-32-chars-long!!');
$aegis = new Aegis($config);

// Challenge route
Flight::route('GET /aegis/challenge', function() use ($aegis) {
    $ip = Flight::request()->ip;
    $ua = Flight::request()->user_agent;
    Flight::response()->header('Cache-Control', 'no-store, private');
    Flight::json($aegis->createChallenge($ip, $ua));
});

// Protect POST endpoints
Flight::before('start', function(&$params, &$output) use ($aegis) {
    $req = Flight::request();
    if ($req->method === 'POST' && in_array($req->url, ['/login', '/submit'])) {
        $payload = (string)$req->data->aegis_payload;
        $result = $aegis->verify($payload, $req->ip, $req->user_agent);

        if (!$result->isHuman) {
            Flight::halt(403, json_encode(['error' => 'Verification failed.']));
        }
    }
});

Flight::start();
```
