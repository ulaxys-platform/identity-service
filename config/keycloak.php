<?php

return [
    'base_url' => env('KEYCLOAK_BASE_URL'),
    'realm' => env('KEYCLOAK_REALM'),
    'issuer' => env('KEYCLOAK_ISSUER'),
    'admin' => [
        'client_id' => env('KEYCLOAK_ADMIN_CLIENT_ID'),
        'client_secret' => env('KEYCLOAK_ADMIN_CLIENT_SECRET'),
    ],
    'connect_timeout' => (int) env('KEYCLOAK_CONNECT_TIMEOUT', 2),
    'request_timeout' => (int) env('KEYCLOAK_REQUEST_TIMEOUT', 5),
];
