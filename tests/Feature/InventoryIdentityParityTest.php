<?php

namespace Ulaxys\Identity\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Ulaxys\Identity\Integration\IntegrationMetadata;
use Ulaxys\Identity\Integration\NativeIdentityRenderer;
use Ulaxys\Identity\Integration\NativeMainGateRenderer;
use Ulaxys\Identity\Integration\OutputWriter;
use Ulaxys\Identity\Integration\PermissionMapper;
use Ulaxys\Identity\Tests\TestCase;

final class InventoryIdentityParityTest extends TestCase
{
    public function test_latest_inventory_catalog_manifest_registered_operations_and_generated_packages_match(): void
    {
        $reference = getenv('ULAXYS_INVENTORY_REFERENCE');
        if (! $reference) {
            $this->markTestSkipped('Set ULAXYS_INVENTORY_REFERENCE to a read-only Inventory checkout containing identity/database/seeders.');
        }
        $support = $reference.'/identity/database/seeders/Support';
        $catalog = require $support.'/permissions-inventory.php'; // Trusted read-only test evidence, never generation input execution.
        $manifest = json_decode(file_get_contents($reference.'/gateway/maingate/permissions/permission-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $keys = json_decode(file_get_contents($support.'/permissions-inventory-keys.json'), true, 512, JSON_THROW_ON_ERROR);
        $byKey = [];
        foreach ($catalog as $entry) {
            $this->assertSame(['data'], array_keys($entry));
            $data = $entry['data'];
            $name = $data['action'].'-'.$data['subject'];
            $this->assertArrayNotHasKey($name, $byKey);
            $this->assertSame('inventory', $data['module']);
            $this->assertSame(['tr', 'en', 'ar'], array_keys($data['description']));
            $this->assertSame($data['description'], IntegrationMetadata::description($data['description']));
            $byKey[$name] = $data;
        }
        $this->assertEqualsCanonicalizing(array_keys($byKey), array_column($keys['permissions'], 'name'));
        $this->assertEqualsCanonicalizing(array_keys($byKey), array_column($manifest['permissions'], 'name'));
        $operations = $modules = [];
        foreach ($manifest['operations'] as $operation) {
            $key = $operation['method'].' '.$operation['upstream_route'];
            $this->assertArrayNotHasKey($key, $operations);
            $operations[$key] = $operation;
            $this->assertArrayHasKey($operation['permission_name'], $byKey);
            $this->assertSame('permission:'.$operation['permission_name'], $operation['middleware_key']);
            $id = $operation['module'];
            if (! isset($modules[$id])) {
                $actions = [];
                foreach ($byKey as $data) {
                    if ($data['subject'] === $operation['permission_subject']) {
                        $actions[] = ['action' => $data['action'], 'module' => $data['module'], 'description' => $data['description']];
                    }
                }
                $modules[$id] = ['id' => $id, 'permissions' => ['subject' => $operation['permission_subject'], 'description' => $id, 'actions' => $actions], 'operations' => []];
            }
        }
        $source = new Process([PHP_BINARY, 'tests/Support/MainGate/export-inventory.php', $reference], base_path());
        $source->mustRun();
        $routes = $excluded = [];
        foreach (json_decode($source->getOutput(), true, 512, JSON_THROW_ON_ERROR) as $route) {
            if (in_array($route['uri'], $manifest['excluded_upstream_routes'], true)) {
                $excluded[] = $route['uri'];

                continue;
            }
            $this->assertCount(1, $route['methods']);
            $key = $route['methods'][0].' '.$route['uri'];
            $this->assertArrayHasKey($key, $operations);
            $operation = $operations[$key];
            unset($operations[$key]);
            $this->assertSame($operation['source_action'], $route['action']);
            $modules[$operation['module']]['operations'][$route['name']] = ['operation' => $operation['operation_id'], 'gatewayAction' => $operation['controller_action'], 'permission' => $operation['permission_action'], 'expose' => true];
            $parameters = array_map(fn ($name) => ['name' => $name, 'optional' => false, 'constraint' => $route['constraints'][$name] ?? null], $route['parameters']);
            $routes[] = [...$route, 'parameters' => $parameters, 'requiresSignature' => in_array('service.signature', $route['middleware'], true), 'resolvedMiddleware' => []];
        }
        $this->assertSame([], $operations);
        $this->assertEqualsCanonicalizing($manifest['excluded_upstream_routes'], $excluded);
        $config = app(IntegrationMetadata::class)->validate(['schemaVersion' => 1, 'service' => 'inventory', 'gateway' => [
            'mainGate' => ['prefix' => ltrim($manifest['external_prefix'], '/'), 'file' => 'gateway/maingate.routes.json'],
            'serviceGateway' => ['prefix' => 'inventory', 'file' => 'gateway/service-gateway.routes.json']],
            'permissionsFile' => 'identity/permissions.json', 'modules' => array_values($modules)]);
        $snapshot = app(PermissionMapper::class)->map(['routes' => $routes, 'excluded' => $excluded], $config, true);
        $files = app(NativeIdentityRenderer::class)->generate($snapshot, $config) + app(NativeMainGateRenderer::class)->generate($snapshot, $config);
        $bundle = 'identity-parity-'.bin2hex(random_bytes(8));
        $root = base_path('generated/integration/'.$bundle);
        try {
            app(OutputWriter::class)->apply($files, $bundle, false, false);
            $generatedCatalog = require $root.'/identity/database/seeders/Support/permissions-inventory.php';
            $this->assertSame($catalog, $generatedCatalog);
            $generatedKeys = json_decode($files['identity/database/seeders/Support/permissions-inventory-keys.json']->contents, true);
            $this->assertSame($keys, $generatedKeys);
            $process = new Process([PHP_BINARY, 'tests/Support/MainGate/export-package.php', $root.'/gateway/maingate/routes/inventory.php', $root.'/gateway/maingate/controllers/InventoryController.php'], base_path());
            $process->mustRun();
            $used = [];
            foreach (json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR) as $route) {
                $name = substr($route['middleware'][1], strlen('permission:'));
                $this->assertArrayHasKey($name, $byKey);
                $used[$name] = true;
            }
            $this->assertEqualsCanonicalizing(array_keys($byKey), array_keys($used));
            foreach ($files as $path => $file) {
                if (str_ends_with($path, '.php')) {
                    (new Process([PHP_BINARY, '-l', $root.'/'.$path]))->mustRun();
                }
            }
            $this->assertStringContainsString('namespace Database\\Seeders\\Support;', $files['identity/database/seeders/Support/PermissionSeedWriter.php']->contents);
            $this->assertStringContainsString('use Database\\Seeders\\Support\\PermissionSeedWriter;', $files['identity/database/seeders/Permissions/InventoryPermissionSeeder.php']->contents);
            // Confirm the reviewed reference defect; never execute/copy its writer as our implementation.
            $this->assertStringNotContainsString('namespace Database\\Seeders\\Support;', file_get_contents($support.'/PermissionSeedWriter.php'));
            $this->assertStringContainsString("'role' => \$data['role'] ?? null", file_get_contents($support.'/PermissionSeedWriter.php'));
        } finally {
            (new Filesystem)->deleteDirectory($root);
        }
    }
}
