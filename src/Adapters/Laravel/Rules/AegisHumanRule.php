<?php

declare(strict_types=1);

namespace Aegis\Captcha\Adapters\Laravel\Rules;

use Aegis\Captcha\Aegis;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Modern Laravel Validation Rule for Aegis verification.
 * Usage: $request->validate(['aegis_payload' => ['required', new AegisHumanRule()]]);
 */
class AegisHumanRule implements ValidationRule
{
    public function __construct(
        private readonly ?Aegis $aegis = null,
        private readonly ?string $customMessage = null
    ) {
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $aegis = $this->aegis ?? app('aegis.captcha');
        if (!is_string($value)) {
            $fail($this->customMessage ?? 'Security verification failed.');
            return;
        }

        $clientIp = (string)(request()->ip() ?? '127.0.0.1');
        $userAgent = (string)(request()->userAgent() ?? 'Unknown');

        $response = $aegis->verify((string)$value, $clientIp, $userAgent);

        if (!$response->isHuman) {
            $msg = $this->customMessage ?? 'Security verification failed: automated activity suspected.';
            $fail($msg);
        }
    }
}
