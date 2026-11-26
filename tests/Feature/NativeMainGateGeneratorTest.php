<?php

namespace Ulaxys\Identity\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Ulaxys\Identity\Integration\IntegrationException;
use Ulaxys\Identity\Integration\IntegrationMetadata;
use Ulaxys\Identity\Integration\NativeMainGateRenderer;
use Ulaxys\Identity\Integration\ObservedRouteCollection;
use Ulaxys\Identity\Integration\OutputWriter;
use Ulaxys\Identity\Integration\PermissionMapper;
use Ulaxys\Identity\Integration\RouteDiscovery;
use Ulaxys\Identity\Tests\TestCase;

final class NativeMainGateGeneratorTest extends TestCase
{
    private string $bundle;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bundle = 'native-test-'.bin2hex(random_bytes(8));
        $this->directory = base_path('generated/integration/'.$this->bundle);
        $collection = new ObservedRouteCollection;
        foreach ($this->app['router']->getRoutes() as $route) {
            // Consumer routes have independent parity coverage; retain infrastructure plus this suite's fixture.
            if (str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $collection->add($route);
        }
        $this->app['router']->setRoutes($collection);
        require base_path('tests/Fixtures/Integration/routes/api.php');
        config(['integration' => require base_path('tests/Fixtures/Integration/metadata.php')]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function generate(array $options = [], int $exit = 0): void
    {
        $this->artisan('ulaxys:generate-integration', ['--all' => true, '--strict' => true, '--output' => $this->bundle, ...$options])->assertExitCode($exit);
    }

    private function render(): array
    {
        $config = app(IntegrationMetadata::class)->validate(config('integration'));
        $snapshot = app(PermissionMapper::class)->map(app(RouteDiscovery::class)->collect($this->app['router']), $config, true);

        return app(NativeMainGateRenderer::class)->generate($snapshot, $config);
    }

    public function test_native_package_syntax_routing_and_original_request_proxy_contract(): void
    {
        $before = $this->app['router']->getRoutes();
        $this->generate();
        $this->assertSame($before, $this->app['router']->getRoutes());
        $root = $this->directory.'/gateway/maingate';
        $this->assertFileExists($root.'/routes/fixture/records.php');
        $this->assertFileExists($root.'/routes/fixture/audits.php');
        foreach ((new Filesystem)->allFiles($root) as $file) {
            if ($file->getExtension() === 'php') {
                (new Process([PHP_BINARY, '-l', $file->getPathname()]))->mustRun();
            }
        }
        $worker = new Process([PHP_BINARY, 'tests/Support/MainGate/export-package.php', $root.'/routes/fixture.php',
            $root.'/controllers/FixtureController.php', '--contract', 'services/fixture', 'fixture'], base_path());
        $worker->mustRun();
        $routes = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(8, $routes);
        $this->assertCount(count($routes), array_unique(array_column($routes, 'action')));
        $this->assertCount(count($routes), array_unique(array_column($routes, 'name')));
        $names = array_column($routes, null, 'name');
        $this->assertSame(['PUT', 'PATCH'], $names['fixture.records_modify']['methods']);
        $this->assertSame(['HEAD'], $names['fixture.records_status']['methods']);
        $this->assertSame(['auth', 'permission:index-fixtureRecords'], $names['fixture.records_search']['middleware']);
        $this->assertSame(['auth', 'permission:edit-fixtureRecords'], $names['fixture.records_modify']['middleware']);
        $this->assertSame('/services/fixture/records/{record}/children/{child}', $names['fixture.records_readChild']['uri']);
        $this->assertSame(['record' => '[0-9]+', 'child' => '[a-z]+'], $names['fixture.records_readChild']['constraints']);
        $this->assertStringContainsString('MainGate', file_get_contents($root.'/README.md'));
        $ledger = json_decode(file_get_contents($this->directory.'/.ulaxys-generated.json'), true);
        $this->assertSame('gateway', $ledger['files']['gateway/maingate/controllers/FixtureController.php']['group']);
        $this->generate(['--check' => true]);
    }

    public function test_json_only_mode_and_permission_commands_preserve_unrelated_files(): void
    {
        $this->generate();
        $identity = file_get_contents($this->directory.'/identity/permissions.json');
        file_put_contents($this->directory.'/review.txt', 'human notes');
        $this->artisan('ulaxys:generate-gateway', ['--output' => $this->bundle, '--gateway-format' => 'json'])->assertSuccessful();
        $this->assertFileDoesNotExist($this->directory.'/gateway/maingate/controllers/FixtureController.php');
        $this->assertSame($identity, file_get_contents($this->directory.'/identity/permissions.json'));
        $this->assertSame('human notes', file_get_contents($this->directory.'/review.txt'));
        $this->generate(['--gateway-format' => 'native']);
        $this->assertFileDoesNotExist($this->directory.'/gateway/maingate.routes.json');
        $this->assertFileExists($this->directory.'/gateway/maingate/controllers/FixtureController.php');
        $this->generate();
        $this->assertFileExists($this->directory.'/gateway/maingate.routes.json');
    }

    public function test_native_files_are_owned_and_dry_run_check_and_regeneration_are_safe(): void
    {
        $this->generate(['--dry-run' => true]);
        $this->assertDirectoryDoesNotExist($this->directory);
        $this->generate();
        $file = $this->directory.'/gateway/maingate/controllers/FixtureController.php';
        $bytes = file_get_contents($file);
        $mtime = filemtime($file);
        $this->generate();
        $this->assertSame($mtime, filemtime($file));
        $config = config('integration');
        $config['modules'][0]['operations']['fixture.records.index']['gatewayAction'] = 'listRecords';
        config(['integration' => $config]);
        $this->generate(['--check' => true], 1);
        $this->assertSame($bytes, file_get_contents($file));
        $this->generate();
        $this->assertStringContainsString('function listRecords(', file_get_contents($file));
        file_put_contents($file, '// manually edited');
        $this->generate(exit: 1);
        $this->assertSame('// manually edited', file_get_contents($file));
    }

    #[DataProvider('invalidNative')]
    public function test_unsupported_native_semantics_fail_before_publication(string $case): void
    {
        $config = config('integration');
        switch ($case) {
            case 'exemption':
                $config['modules'][0]['operations']['fixture.records.synchronize']['expose'] = true;
                break;
            case 'collision':
                $config['modules'][0]['operations']['fixture.records.index']['gatewayAction'] = 'duplicate';
                $config['modules'][1]['operations']['fixture.audits.index']['gatewayAction'] = 'DUPLICATE';
                break;
            case 'reserved':
                $config['modules'][0]['operations']['fixture.records.index']['gatewayAction'] = 'callAction';
                break;
            case 'optional':
            case 'trace':
            case 'parameter':
            case 'repeated':
                $uri = $case === 'optional' ? '/extra/{key?}' : ($case === 'parameter' ? '/extra/{request}' : ($case === 'repeated' ? '/extra/{key}/{key}' : '/extra'));
                Route::middleware(['api', 'service.signature'])->match([$case === 'trace' ? 'TRACE' : 'GET'], $uri, fn () => null)->name('extra');
                $config['modules'][0]['operations']['extra'] = ['operation' => 'extra', 'expose' => true, 'permission' => 'index'];
                break;
            case 'permission':
                $config['modules'][0]['permissions']['subject'] = 'unsupported_subject';
                break;
            case 'controller':
                $config['gateway']['mainGate']['controller'] = 'Controller';
                break;
        }
        config(['integration' => $config]);
        $this->generate(exit: 1);
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public static function invalidNative(): array
    {
        return array_map(fn ($case) => [$case], ['exemption', 'collision', 'reserved', 'optional', 'trace', 'parameter', 'permission', 'controller', 'repeated']);
    }

    public function test_explicit_options_and_head_keep_their_methods_and_semantic_action_names(): void
    {
        Route::middleware(['api', 'service.signature'])->match(['OPTIONS'], '/capabilities', fn () => null)->name('capabilities');
        $config = config('integration');
        $config['modules'][0]['operations']['capabilities'] = ['operation' => 'capabilities', 'expose' => true, 'permission' => 'index', 'gatewayAction' => 'inspectCapabilities'];
        config(['integration' => $config]);
        $files = $this->render();
        $this->assertStringContainsString("Route::match(['OPTIONS'], '/capabilities'", $files['gateway/maingate/routes/fixture/records.php']->contents);
        $this->assertStringContainsString('function inspectCapabilities(', $files['gateway/maingate/controllers/FixtureController.php']->contents);
    }

    public function test_module_partitioning_preserves_significant_order_and_refuses_cycles(): void
    {
        $config = config('integration');
        foreach ([['/shared/static', 1, 'static'], ['/shared/{key}', 0, 'dynamic']] as [$uri, $module, $operation]) {
            Route::middleware(['api', 'service.signature'])->get($uri, fn () => null)->name('order.'.$operation);
            $config['modules'][$module]['operations']['order.'.$operation] = ['operation' => $operation, 'expose' => true, 'permission' => 'index'];
        }
        config(['integration' => $config]);
        $entry = $this->render()['gateway/maingate/routes/fixture.php']->contents;
        $this->assertLessThan(strpos($entry, '/records.php'), strpos($entry, '/audits.php'));
        foreach ([['/other/static', 0, 'otherStatic'], ['/other/{key}', 1, 'otherDynamic']] as [$uri, $module, $operation]) {
            Route::middleware(['api', 'service.signature'])->get($uri, fn () => null)->name('order.'.$operation);
            $config['modules'][$module]['operations']['order.'.$operation] = ['operation' => $operation, 'expose' => true, 'permission' => 'index'];
        }
        config(['integration' => $config]);
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('significant route order');
        $this->render();
    }

    public function test_ambiguous_canonical_permission_names_are_refused(): void
    {
        $config = config('integration');
        foreach ([[0, 'b-c', 'a'], [1, 'c', 'a-b']] as [$index, $subject, $action]) {
            $config['modules'][$index]['permissions'] = ['subject' => $subject, 'description' => 'test',
                'actions' => [['action' => $action, 'description' => 'test', 'module' => 'fixture']]];
            foreach ($config['modules'][$index]['operations'] as &$operation) {
                if (isset($operation['permission'])) {
                    $operation['permission'] = $action;
                }
            }
            unset($operation);
        }
        config(['integration' => $config]);
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Ambiguous canonical permission');
        $this->render();
    }

    public function test_mixed_native_publication_rolls_back_on_failed_final_rename(): void
    {
        $this->generate();
        $before = file_get_contents($this->directory.'/gateway/maingate/controllers/FixtureController.php');
        $ledger = file_get_contents($this->directory.'/.ulaxys-generated.json');
        $config = config('integration');
        $config['modules'][0]['operations']['fixture.records.index']['gatewayAction'] = 'renamed';
        config(['integration' => $config]);
        $filesystem = new class extends Filesystem
        {
            private int $moves = 0;

            public function move($path, $target)
            {
                return ++$this->moves === 2 ? false : parent::move($path, $target);
            }
        };
        try {
            (new OutputWriter($filesystem))->apply($this->render(), $this->bundle, false, false);
            $this->fail('Expected publication failure.');
        } catch (IntegrationException $exception) {
            $this->assertStringContainsString('previous output restored', $exception->getMessage());
        }
        $this->assertSame($before, file_get_contents($this->directory.'/gateway/maingate/controllers/FixtureController.php'));
        $this->assertSame($ledger, file_get_contents($this->directory.'/.ulaxys-generated.json'));
        $this->assertFileExists($this->directory.'/identity/permissions.json');
    }

    public function test_fresh_artisan_process_can_generate_check_and_detect_stale_native_output(): void
    {
        $arguments = [PHP_BINARY, 'tests/Support/MainGate/generate-fixture.php', 'ulaxys:generate-integration', '--all', '--strict', '--output='.$this->bundle];
        (new Process([...$arguments, '--dry-run'], base_path()))->mustRun();
        $this->assertDirectoryDoesNotExist($this->directory);
        (new Process($arguments, base_path()))->mustRun();
        (new Process([...$arguments, '--check'], base_path()))->mustRun();
        $file = $this->directory.'/gateway/maingate/routes/fixture.php';
        $before = file_get_contents($file);
        $stale = new Process([...$arguments, '--check'], base_path(), ['ULAXYS_TEST_CHANGED_PREFIX' => '1']);
        $stale->run();
        $this->assertSame(1, $stale->getExitCode());
        $this->assertSame($before, file_get_contents($file));
    }

    public function test_explicit_head_overlapping_get_alias_is_refused_due_to_compiled_semantics(): void
    {
        $config = config('integration');
        foreach ([[0, 'GET', 'getProbe'], [1, 'HEAD', 'headProbe']] as [$module, $method, $operation]) {
            Route::middleware(['api', 'service.signature'])->match([$method], '/probe', fn () => null)->name($operation);
            $config['modules'][$module]['operations'][$operation] = ['operation' => $operation, 'expose' => true, 'permission' => 'index'];
        }
        config(['integration' => $config]);
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Explicit HEAD overlaps a GET alias');
        $this->render();
    }

    public function test_explicit_native_controller_destination_and_prefix_configuration(): void
    {
        $config = config('integration');
        $config['gateway']['mainGate'] += ['controller' => 'ReviewedProxyController', 'destination' => 'reviewed-destination'];
        $config['gateway']['mainGate']['prefix'] = 'custom/service/path';
        config(['integration' => $config]);
        $this->generate();
        $root = $this->directory.'/gateway/maingate';
        $process = new Process([PHP_BINARY, 'tests/Support/MainGate/export-package.php', $root.'/routes/fixture.php',
            $root.'/controllers/ReviewedProxyController.php', '--contract', 'custom/service/path', 'reviewed-destination'], base_path());
        $process->mustRun();
        $routes = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('/custom/service/path/records', $routes[0]['uri']);
        $this->assertSame('App\\Http\\Controllers\\ReviewedProxyController@records_list', $routes[0]['action']);
    }

    public function test_unrelated_operations_do_not_rename_existing_actions(): void
    {
        $before = $this->render()['gateway/maingate/routes/fixture/records.php']->contents;
        Route::middleware(['api', 'service.signature'])->get('/extra', fn () => null)->name('extra');
        $config = config('integration');
        $config['modules'][1]['operations']['extra'] = ['operation' => 'extra', 'expose' => true, 'permission' => 'index'];
        config(['integration' => $config]);
        $this->assertSame($before, $this->render()['gateway/maingate/routes/fixture/records.php']->contents);
    }
}
