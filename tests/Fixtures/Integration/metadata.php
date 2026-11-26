<?php

return [
    'schemaVersion' => 1, 'service' => 'fixture',
    'gateway' => [
        'mainGate' => ['prefix' => 'services/fixture', 'file' => 'gateway/maingate.routes.json'],
        'serviceGateway' => ['prefix' => 'fixture', 'file' => 'gateway/service-gateway.routes.json'],
    ],
    'permissionsFile' => 'identity/permissions.json',
    'modules' => [
        ['id' => 'records', 'permissions' => [
            'subject' => 'fixtureRecords', 'description' => 'permission.fixtureRecords',
            'actions' => [
                ['action' => 'show', 'description' => 'permission.fixtureRecords.show', 'module' => 'fixture'],
                ['action' => 'index', 'description' => 'permission.fixtureRecords.index', 'module' => 'fixture'],
                ['action' => 'edit', 'description' => 'permission.fixtureRecords.edit', 'module' => 'fixture'],
                ['action' => 'delete', 'description' => 'permission.fixtureRecords.delete', 'module' => 'fixture'],
                ['action' => 'create', 'description' => 'permission.fixtureRecords.create', 'module' => 'fixture'],
            ],
        ], 'operations' => [
            'fixture.records.index' => ['operation' => 'list', 'permission' => 'index', 'expose' => true],
            'fixture.records.search' => ['operation' => 'search', 'permission' => 'index', 'expose' => true],
            'fixture.records.store' => ['operation' => 'create', 'permission' => 'create', 'expose' => true],
            'fixture.records.status' => ['operation' => 'status', 'permission' => 'index', 'expose' => true],
            'fixture.records.show' => ['operation' => 'readChild', 'permission' => 'show', 'expose' => true],
            'fixture.records.update' => ['operation' => 'modify', 'permission' => 'edit', 'expose' => true],
            'fixture.records.destroy' => ['operation' => 'remove', 'permission' => 'delete', 'expose' => true],
            'fixture.records.synchronize' => ['operation' => 'synchronize', 'exempt' => 'Explicit operator-only integration; native adapter owner must approve separate authorization.', 'expose' => false],
            'fixture.records.public' => ['operation' => 'publicState', 'exempt' => 'Public source capability stays private to this service network and is not gateway exposed.', 'expose' => false],
        ]],
        ['id' => 'audits', 'permissions' => ['subject' => 'fixtureAudits', 'description' => 'permission.fixtureAudits',
            'actions' => [['action' => 'index', 'description' => 'permission.fixtureAudits.index', 'module' => 'fixture']]],
            'operations' => ['fixture.audits.index' => ['operation' => 'list', 'permission' => 'index', 'expose' => true]]],
    ],
];
