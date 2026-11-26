<?php

namespace Ulaxys\Identity\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Ulaxys\Identity\Integration\IntegrationMetadata;
use Ulaxys\Identity\Integration\NativeMainGateRenderer;
use Ulaxys\Identity\Integration\OutputWriter;
use Ulaxys\Identity\Integration\PermissionMapper;
use Ulaxys\Identity\Integration\RouteDiscovery;
use Ulaxys\Identity\Tests\TestCase;

/** Opt-in real read-only reference verification, with no copied business runtime fixtures. */
final class InventoryMainGateParityTest extends TestCase
{
    public function test_actual_inventory_registry_manifest_and_native_package_semantic_parity(): void
    {
        $reference = getenv('ULAXYS_INVENTORY_REFERENCE');
        if (! $reference) {
            $this->markTestSkipped('Set ULAXYS_INVENTORY_REFERENCE to a read-only Inventory checkout for actual reference parity.');
        }
        $manifest = json_decode(file_get_contents($reference.'/gateway/maingate/permissions/permission-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $source = $this->execute(['tests/Support/MainGate/export-inventory.php', $reference]);
        $actualPackage = $this->execute(['tests/Support/MainGate/export-package.php', $reference.'/gateway/maingate/routes/inventory.php',
            $reference.'/gateway/maingate/controllers/InventoryController.php']);
        $catalog = [];
        foreach ($manifest['permissions'] as $permission) {
            $catalog[$permission['subject']][$permission['action']] = $permission['name'];
            $this->assertSame($permission['action'].'-'.$permission['subject'], $permission['name']);
        }
        $expected = [];
        $modules = [];
        foreach ($manifest['operations'] as $operation) {
            $key = $operation['method'].' '.$operation['upstream_route'];
            $this->assertArrayNotHasKey($key, $expected);
            $expected[$key] = $operation;
            $id = $operation['module'];
            if (! isset($modules[$id])) {
                $actions = [];
                foreach ($catalog[$operation['permission_subject']] as $action => $name) {
                    $actions[] = ['action' => $action, 'description' => $name, 'module' => $manifest['service']];
                }
                $modules[$id] = ['id' => $id, 'permissions' => ['subject' => $operation['permission_subject'], 'description' => $id, 'actions' => $actions], 'operations' => []];
            }
        }
        $routes = $excluded = [];
        foreach ($source as $route) {
            if (in_array($route['uri'], $manifest['excluded_upstream_routes'], true)) {
                $excluded[] = $route['uri'];

                continue;
            }
            $this->assertContains('service.signature', $route['middleware']);
            $this->assertContains('api', $route['middleware']);
            $this->assertCount(1, $route['methods']); // Current reference uses explicit single-method operations.
            $operation = $expected[$route['methods'][0].' '.$route['uri']] ?? null;
            $this->assertNotNull($operation, 'Undeclared Inventory operation '.$route['uri']);
            $this->assertSame($operation['source_action'], $route['action']);
            $this->assertSame((array) $operation['constraints'], (array) $route['constraints']);
            $this->assertSame(array_keys((array) $operation['parameters']), $route['parameters']);
            $modules[$operation['module']]['operations'][$route['name']] = ['operation' => $operation['operation_id'],
                'gatewayAction' => $operation['controller_action'], 'permission' => $operation['permission_action'], 'expose' => true];
            $parameters = [];
            foreach ($route['parameters'] as $parameter) {
                $parameters[] = ['name' => $parameter, 'optional' => false, 'constraint' => $route['constraints'][$parameter] ?? null];
            }
            $routes[] = [...$route, 'parameters' => $parameters, 'requiresSignature' => true, 'resolvedMiddleware' => []];
        }
        $this->assertCount(count($expected), $routes);
        $this->assertEqualsCanonicalizing($manifest['excluded_upstream_routes'], $excluded);
        $config = app(IntegrationMetadata::class)->validate(['schemaVersion' => 1, 'service' => $manifest['service'], 'gateway' => [
            'mainGate' => ['prefix' => ltrim($manifest['external_prefix'], '/'), 'file' => 'gateway/maingate.routes.json'],
            'serviceGateway' => ['prefix' => $manifest['service'], 'file' => 'gateway/service-gateway.routes.json']],
            'permissionsFile' => 'identity/permissions.json', 'modules' => array_values($modules)]);
        $snapshot = app(PermissionMapper::class)->map(['routes' => $routes, 'excluded' => $excluded], $config, true);
        $files = app(NativeMainGateRenderer::class)->generate($snapshot, $config);
        $bundle = 'inventory-parity-'.bin2hex(random_bytes(8));
        $root = base_path('generated/integration/'.$bundle);
        try {
            app(OutputWriter::class)->apply($files, $bundle, false, false);
            $generated = $this->execute(['tests/Support/MainGate/export-package.php', $root.'/gateway/maingate/routes/inventory.php',
                $root.'/gateway/maingate/controllers/InventoryController.php', '--contract', ltrim($manifest['external_prefix'], '/'), $manifest['service']]);
            $this->assertCount(count($expected), $generated);
            $indexed = [];
            foreach ($generated as $route) {
                foreach ($route['methods'] as $method) {
                    $key = $method.' '.substr($route['uri'], strlen($manifest['external_prefix']));
                    $this->assertArrayNotHasKey($key, $indexed);
                    $operation = $expected[$key];
                    $this->assertSame($operation['external_route'], $route['uri']);
                    $this->assertSame('App\\Http\\Controllers\\InventoryController@'.$operation['controller_action'], $route['action']);
                    $this->assertSame('inventory.'.$operation['controller_action'], $route['name']);
                    $this->assertSame(['auth', $operation['middleware_key']], $route['middleware']);
                    $this->assertSame((array) $operation['constraints'], (array) $route['constraints']);
                    $this->assertSame(array_keys((array) $operation['parameters']), $route['parameters']);
                    $indexed[$key] = $route;
                    $modulePhp = $files['gateway/maingate/routes/inventory/'.$operation['module'].'.php']->contents;
                    $this->assertStringContainsString("'".$operation['controller_action']."'", $modulePhp);
                }
            }
            $this->assertCount(count($modules) + 3, $files); // Entry, controller, README plus modules, no fixed operation/module count.
            $referenceIndexed = [];
            foreach ($actualPackage as $index => $route) {
                $referenceIndexed[$route['methods'][0].' '.$route['uri']] = $index;
                $key = $route['methods'][0].' '.substr($route['uri'], strlen($manifest['external_prefix']));
                $this->assertSame($route, $indexed[$key]);
            }
            // Independent module order may differ. Every potentially matching pair retains reviewed precedence.
            foreach ($generated as $index => $earlier) {
                foreach (array_slice($generated, $index + 1) as $later) {
                    if (array_intersect($earlier['methods'], $later['methods']) && (app(RouteDiscovery::class)->mayShadow($earlier['uri'], $later['uri']) || app(RouteDiscovery::class)->mayShadow($later['uri'], $earlier['uri']))) {
                        $this->assertLessThan($referenceIndexed[$later['methods'][0].' '.$later['uri']], $referenceIndexed[$earlier['methods'][0].' '.$earlier['uri']]);
                    }
                }
            }
        } finally {
            (new Filesystem)->deleteDirectory($root);
        }
    }

    private function execute(array $arguments): array
    {
        $process = new Process([PHP_BINARY, ...$arguments], base_path());
        $process->mustRun();

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
}
