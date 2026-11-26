<?php

// Identity service runtime policy. All values are deployment-tunable; no
// credentials, keys or secrets are stored in this file.
return [

    /*
    |--------------------------------------------------------------------------
    | Issuer and audiences
    |--------------------------------------------------------------------------
    */

    'issuer' => env('IDENTITY_ISSUER', rtrim((string) env('APP_URL', 'http://localhost:8000'), '/')),

    'token' => [
        'audience' => env('IDENTITY_TOKEN_AUDIENCE', 'ulaxys-platform'),
        'access_ttl_seconds' => (int) env('IDENTITY_ACCESS_TOKEN_TTL', 600),
        'refresh_ttl_minutes' => (int) env('IDENTITY_REFRESH_TOKEN_TTL', 720),
        'clock_skew_seconds' => (int) env('IDENTITY_CLOCK_SKEW_SECONDS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sessions
    |--------------------------------------------------------------------------
    */

    'session' => [
        // Absolute session lifetime; access tokens never outlive it.
        'lifetime_hours' => (int) env('IDENTITY_SESSION_TTL_HOURS', 12),
        // Device labels and IP-derived location are informational only and
        // stored as hashes; they never authenticate a request.
        'store_device_metadata' => (bool) env('IDENTITY_SESSION_STORE_DEVICE_METADATA', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Credential policy (see ADR-0003)
    |--------------------------------------------------------------------------
    */

    'password' => [
        'min_length' => (int) env('IDENTITY_PASSWORD_MIN_LENGTH', 12),
        'max_length' => 128,
        // Tokens in this blocklist are rejected verbatim. Extend via a
        // deployment-provided file; breach-corpus integration is deferred.
        'blocklist' => [
            'password', 'password1', 'password123', '123456', '1234567',
            '12345678', '123456789', '1234567890', 'qwerty', 'qwerty123',
            'abc123', 'letmein', 'welcome', 'welcome1', 'admin', 'admin123',
            'iloveyou', 'monkey', 'dragon', 'football', 'baseball', 'sunshine',
            'princess', 'changeme', 'ulaxys', 'sifre', 'sifre123', 'parola',
            'parola123', '123123', '111111', '000000', '121212', '654321',
            'qwe123', '1q2w3e4r', 'qwertyuiop', 'asdfgh', 'zxcvbnm',
            'gunes', 'turkiye', 'istanbul', 'ankara', 'ramadan', ' Profilo',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Login throttling and lockout (fail closed)
    |--------------------------------------------------------------------------
    */

    'throttle' => [
        // Attempts per identifier+IP window before 429.
        'login_max_attempts' => (int) env('IDENTITY_LOGIN_MAX_ATTEMPTS', 8),
        'login_decay_seconds' => (int) env('IDENTITY_LOGIN_DECAY_SECONDS', 300),
        'refresh_max_attempts' => (int) env('IDENTITY_REFRESH_MAX_ATTEMPTS', 30),
        'recovery_max_attempts' => (int) env('IDENTITY_RECOVERY_MAX_ATTEMPTS', 5),
        'recovery_decay_seconds' => (int) env('IDENTITY_RECOVERY_DECAY_SECONDS', 900),
        'setup_max_attempts' => (int) env('IDENTITY_SETUP_MAX_ATTEMPTS', 10),
        'introspect_max_attempts' => (int) env('IDENTITY_INTROSPECT_MAX_ATTEMPTS', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | One-time challenge tokens
    |--------------------------------------------------------------------------
    */

    'challenge' => [
        'reset_ttl_minutes' => (int) env('IDENTITY_RESET_TTL_MINUTES', 60),
        'setup_ttl_minutes' => (int) env('IDENTITY_SETUP_TTL_MINUTES', 1440),
        'email_verification_ttl_minutes' => (int) env('IDENTITY_EMAIL_VERIFICATION_TTL_MINUTES', 1440),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signing keys (metadata in PostgreSQL, PEM material on secure storage)
    |--------------------------------------------------------------------------
    */

    'keys' => [
        'storage_path' => env('IDENTITY_KEYS_PATH', storage_path('keys')),
        'key_size' => 3072,
        // Retired public keys stay published for rotation overlap.
        'retired_publication_days' => (int) env('IDENTITY_RETIRED_KEY_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | One-time token delivery abstraction (no real email transport in I1)
    |--------------------------------------------------------------------------
    */

    'delivery' => [
        // Supported drivers: null (default, audit-only), log (local dev only).
        'driver' => env('IDENTITY_DELIVERY_DRIVER', 'null'),
        'log_path' => env('IDENTITY_DELIVERY_LOG_PATH', storage_path('logs/identity-delivery.log')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Actor context contract (docs/gateway-integration.md)
    |--------------------------------------------------------------------------
    */

    'actor_context' => [
        // Bumped when the contract changes; the Gateway must treat it as opaque.
        'version' => 1,
    ],

];
