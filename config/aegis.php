<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Aegis Secret Key
    |--------------------------------------------------------------------------
    |
    | Used to derive the AES-256-GCM token encryption key.
    | Must be at least 32 characters long. In Laravel, it defaults to APP_KEY.
    |
    */
    'secret_key' => env('AEGIS_SECRET_KEY', env('APP_KEY')),

    /*
    |--------------------------------------------------------------------------
    | Protection Mode
    |--------------------------------------------------------------------------
    |
    | Options:
    | Reserved for a future challenge presentation strategy. The current client
    | always runs the invisible proof-of-work flow and displays a status badge.
    |
    */
    'mode' => env('AEGIS_MODE', 'hybrid'),

    /*
    |--------------------------------------------------------------------------
    | Proof of Work Difficulty
    |--------------------------------------------------------------------------
    |
    | Number of leading hexadecimal zeros required in the SHA-256 proof.
    | Supported range: 1–5. Runtime varies substantially by device and browser.
    |
    */
    'pow_difficulty' => (int)env('AEGIS_POW_DIFFICULTY', 4),

    /*
    |--------------------------------------------------------------------------
    | Challenge TTL
    |--------------------------------------------------------------------------
    |
    | Lifetime of challenge tokens in seconds before expiring.
    |
    */
    'ttl_seconds' => (int)env('AEGIS_TTL_SECONDS', 180),

    /*
    |--------------------------------------------------------------------------
    | Risk Score Acceptance Threshold
    |--------------------------------------------------------------------------
    |
    | Heuristic acceptance threshold, not a probability of being human.
    | Recommended starting point: 0.65; tune using production telemetry.
    |
    */
    'risk_threshold' => (float)env('AEGIS_RISK_THRESHOLD', 0.65),

    /*
    |--------------------------------------------------------------------------
    | Client Binding Protections
    |--------------------------------------------------------------------------
    |
    | Optionally binds the challenge token to a client subnet and User-Agent. This can affect mobile users behind changing networks.
    |
    */
    'bind_ip' => (bool)env('AEGIS_BIND_IP', true),
    'bind_user_agent' => (bool)env('AEGIS_BIND_USER_AGENT', true),

    /*
    |--------------------------------------------------------------------------
    | Endpoints and Fields
    |--------------------------------------------------------------------------
    */
    'challenge_endpoint' => '/aegis/challenge',
    'payload_field' => 'aegis_payload',
];
