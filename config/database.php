<?php

return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'connections' => ['pgsql' => [
        'driver' => 'pgsql', 'url' => env('DATABASE_URL'),
        'host' => env('DB_HOST'), 'port' => env('DB_PORT', '5432'),
        'database' => env('DB_DATABASE'), 'username' => env('DB_USERNAME'),
        'password' => env('DB_PASSWORD'), 'charset' => 'utf8', 'prefix' => '',
        'prefix_indexes' => true, 'search_path' => 'public',
        'sslmode' => env('DB_SSLMODE', 'prefer'),
    ]],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
    'redis' => [
        'client' => 'phpredis',
        'options' => ['prefix' => env('REDIS_PREFIX', 'service_')],
        'default' => [
            'url' => env('REDIS_URL'), 'host' => env('REDIS_HOST'),
            'username' => env('REDIS_USERNAME'), 'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'), 'database' => env('REDIS_DB', '0'),
        ],
        'cache' => [
            'url' => env('REDIS_URL'), 'host' => env('REDIS_HOST'),
            'username' => env('REDIS_USERNAME'), 'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'), 'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],
];
