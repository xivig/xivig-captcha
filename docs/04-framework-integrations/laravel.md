# Framework Integration: Laravel (v10 / v11 / v12 / v13)

The `xivig/captcha` package provides seamless, native integration with modern Laravel applications.

---

## 1. Automatic Package Discovery

Laravel automatically discovers the service provider and facade. If you need to register them manually:

In `config/app.php` (Laravel 10) or `bootstrap/providers.php` (Laravel 11+):
```php
'providers' => [
    Aegis\Captcha\Adapters\Laravel\AegisServiceProvider::class,
],
'aliases' => [
    'Aegis' => Aegis\Captcha\Adapters\Laravel\AegisFacade::class,
],
```

---

## 2. Publish Configuration & Assets

Publish the configuration file and JavaScript/CSS assets using Artisan:

```bash
php artisan vendor:publish --tag=aegis-config
php artisan vendor:publish --tag=aegis-assets
```

This creates:
- `config/aegis.php`: Main package settings
- `public/vendor/aegis/js/aegis.js`: Client SDK
- `public/vendor/aegis/js/aegis-worker.js`: Static CSP Web Worker
- `public/vendor/aegis/css/aegis.css`: Widget styles

---

## 3. Protecting Routes via Form Request Validation

Use the `AegisHumanRule` validation rule in your controllers or FormRequest classes:

```php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Aegis\Captcha\Adapters\Laravel\Rules\AegisHumanRule;

class LoginController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'aegis_payload' => ['required', 'string', new AegisHumanRule()],
        ], [
            'aegis_payload.required' => 'Security challenge verification is required.',
        ]);

        // Authentication logic continues...
    }
}
```

---

## 4. Protecting Routes via Route Middleware

The package auto-registers the route middleware alias `'aegis'` pointing to `AegisProtectMiddleware`.

### Laravel 11 / 12 / 13 (`bootstrap/app.php`):
```php
use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function ($middleware) {
        $middleware->alias([
            'aegis' => \Aegis\Captcha\Adapters\Laravel\Middleware\AegisProtectMiddleware::class,
        ]);
    })
    ->create();
```

### Route Definition:
```php
use App\Http\Controllers\ContactController;

Route::post('/contact/submit', [ContactController::class, 'submit'])
    ->middleware(['web', 'aegis']);
```

---

## 5. Blade Templates & Directives

Use the custom Blade directives to embed the widget and its corresponding styles:

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - My Application</title>
    @aegisStyle
</head>
<body>
    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>

        <!-- Render Captcha Container -->
        @aegisWidget

        @error('aegis_payload')
            <div class="alert alert-danger">{{ $message }}</div>
        @enderror

        <button type="submit">Sign In</button>
    </form>

    <!-- Load Client Scripts -->
    @aegisScript
</body>
</html>
```

---

## 6. Livewire & Inertia.js Support

### Livewire v3:
Listen for the solved payload event on your Livewire component:
```html
<div wire:ignore id="aegis-container"></div>
<input type="hidden" wire:model="aegisPayload" id="aegis_payload">

<script>
    document.addEventListener('livewire:initialized', () => {
        new Aegis({
            onSuccess: (payload) => {
                @this.set('aegisPayload', payload);
            }
        });
    });
</script>
```

### Inertia.js (Vue 3 / React):
Mount the client instance in your component's `onMounted` hook and pass the solved token in the form submit data:
```vue
<script setup>
import { onMounted, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    email: '',
    password: '',
    aegis_payload: '',
});

onMounted(() => {
    new window.Aegis({
        endpoint: '/aegis/challenge',
        onSuccess: (token) => {
            form.aegis_payload = token;
        }
    });
});
</script>
```
