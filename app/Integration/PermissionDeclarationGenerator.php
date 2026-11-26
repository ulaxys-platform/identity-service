<?php

namespace Ulaxys\Identity\Integration;

final class PermissionDeclarationGenerator
{
    public function generate(array $snapshot, array $config): array
    {
        return [
            $config['permissionsFile'] => ['schemaVersion' => 1, 'kind' => 'identity-permission-declaration',
                'service' => $snapshot['service'], 'adapter' => 'intermediate', 'executableSeeder' => false,
                'permissions' => $snapshot['permissions']],
            'identity/compatibility.json' => ['schemaVersion' => 1, 'kind' => 'identity-compatibility-report',
                'service' => $snapshot['service'], 'nativeAdapterVerified' => false, 'executableSeeder' => false,
                'requiredVerification' => ['Inspect actual PHP Identity subject/actions/module/description storage and localization contract.',
                    'Confirm permission identity/ID allocation, uniqueness, upsert/grant preservation and cache invalidation.',
                    'Review/install the native Identity package when selected and test against the actual application; never invent numeric IDs.'],
                'warnings' => $snapshot['warnings']],
        ];
    }
}
