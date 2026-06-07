<?php

declare(strict_types=1);

return [
    'jwt' => [
        'issuer' => env('POS_ADMIN_JWT_ISSUER', env('APP_URL', 'http://localhost')),
        'audience' => env('POS_ADMIN_JWT_AUDIENCE', 'mithqal-pos-admin'),
        'ttl_minutes' => (int) env('POS_ADMIN_JWT_TTL_MINUTES', 60),
        'cookie' => env('POS_ADMIN_JWT_COOKIE', 'pos_admin_jwt'),
    ],

    'session' => [
        'idle_timeout_minutes' => (int) env('POS_ADMIN_IDLE_TIMEOUT_MINUTES', 30),
    ],

    'rate_limits' => [
        'login_per_minute' => (int) env('POS_ADMIN_LOGIN_RATE_LIMIT_PER_MINUTE', 5),
    ],

    'default_admin' => [
        'name' => env('POS_ADMIN_DEFAULT_NAME', 'MITHQAL Admin'),
        'email' => env('POS_ADMIN_DEFAULT_EMAIL', 'admin@mithqal.local'),
        'password' => env('POS_ADMIN_DEFAULT_PASSWORD', 'MithqalAdmin@2026!'),
    ],
];
