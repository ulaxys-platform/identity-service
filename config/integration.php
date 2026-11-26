<?php

// Versioned, semantic metadata only. HTTP methods and paths come from Laravel.
return [
    'schemaVersion' => 1,
    'service' => 'identity',
    'gateway' => [
        'mainGate' => ['prefix' => 'services/identity', 'file' => 'gateway/maingate.routes.json'],
        'serviceGateway' => ['prefix' => 'identity', 'file' => 'gateway/service-gateway.routes.json'],
    ],
    'permissionsFile' => 'identity/permissions.json',
    'modules' => [],
];
