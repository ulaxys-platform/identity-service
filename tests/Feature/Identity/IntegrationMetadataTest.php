<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Feature\Identity;

use Ulaxys\Identity\Integration\IntegrationMetadata;
use Ulaxys\Identity\Tests\TestCase;

final class IntegrationMetadataTest extends TestCase
{
    public function test_integration_config_passes_strict_metadata_validation(): void
    {
        $validated = app(IntegrationMetadata::class)->validate(config('integration'));
        $this->assertSame('identity', $validated['service']);
        $this->assertNotEmpty($validated['modules']);
    }

    public function test_every_mounted_identity_route_has_exact_metadata_and_no_ghost_operations(): void
    {
        $operations = $this->declaredOperations();
        $mounted = collect($this->runtimeRoutes())
            ->filter(fn (array $route) => str_starts_with((string) $route['uri'], 'api/v1/'))
            ->map(fn (array $route) => (string) $route['name'])
            ->all();
        foreach ($mounted as $name) {
            $this->assertArrayHasKey($name, $operations, 'Mounted identity route lacks integration metadata: '.$name);
        }
        foreach (array_keys($operations) as $name) {
            $this->assertContains($name, $mounted, 'Declared operation has no mounted route: '.$name);
        }
    }

    public function test_pre_auth_and_contract_operations_are_explicitly_exempt_and_business_operations_have_permissions(): void
    {
        $exempt = [
            'identity/auth/login', 'identity/auth/refresh', 'identity/auth/setup',
            'identity/credentials/forgot-password', 'identity/credentials/reset-password',
            'identity/credentials/email-verification/confirm',
            'identity/oauth/jwks', 'identity/oauth/introspect', 'identity/oauth/revoke',
            'identity/actor/context',
        ];
        $operations = $this->declaredOperations();
        foreach ($exempt as $name) {
            $this->assertArrayHasKey('exempt', $operations[$name], $name.' must carry an explicit exemption reason.');
            $this->assertArrayNotHasKey('permission', $operations[$name]);
        }
        foreach ($operations as $name => $operation) {
            if (in_array($name, $exempt, true)) {
                continue;
            }
            $this->assertArrayHasKey('permission', $operation, $name.' must reference a permission action.');
        }
    }

    public function test_permission_actions_carry_tr_en_ar_descriptions(): void
    {
        foreach (config('integration.modules') as $module) {
            if (($module['permissions'] ?? null) === null) {
                continue;
            }
            foreach ($module['permissions']['actions'] as $action) {
                $this->assertSame(['tr', 'en', 'ar'], array_keys($action['description']), 'Action '.$action['action'].' must localize tr/en/ar.');
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function declaredOperations(): array
    {
        $operations = [];
        foreach (app(IntegrationMetadata::class)->validate(config('integration'))['modules'] as $module) {
            foreach ($module['operations'] as $name => $operation) {
                $operations[$name] = $operation;
            }
        }

        return $operations;
    }
}
