<?php

declare(strict_types=1);

// Optional test-only metadata applied on top of the existing integration fixture.
// Route names must match actual registered Laravel routes, never inferred verbs.
return [
    'id' => 'labels',
    'permissions' => [
        'subject' => 'fixture-labels',
        'description' => 'Fixture label definitions',
        'actions' => [
            ['action' => 'index', 'description' => 'View fixture labels', 'module' => 'fixture'],
            ['action' => 'create', 'description' => 'Create fixture labels', 'module' => 'fixture'],
        ],
    ],
    'operations' => [
        'fixture.labels.index' => ['operation' => 'list', 'gatewayAction' => 'listLabels', 'permission' => 'index', 'expose' => true],
        'fixture.labels.store' => ['operation' => 'create', 'gatewayAction' => 'createLabel', 'permission' => 'create', 'expose' => true],
    ],
];
