<?php

namespace Ulaxys\Identity\Tests\Feature;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Ulaxys\Identity\Integration\IntegrationException;
use Ulaxys\Identity\Integration\ObservedRouteCollection;
use Ulaxys\Identity\Integration\OutputWriter;
use Ulaxys\Identity\Integration\RouteDiscovery;
use Ulaxys\Identity\Tests\Fixtures\DomainExample\ExampleLabelController;
use Ulaxys\Identity\Tests\Fixtures\DomainExample\ExampleLabelRepositoryInterface;
use Ulaxys\Identity\Tests\Fixtures\DomainExample\PostgresExampleLabelRepository;
use Ulaxys\Identity\Tests\Fixtures\Integration\FixtureController;
use Ulaxys\Identity\Tests\TestCase;

final class IntegrationGeneratorTest extends TestCase
{
    private string $bundle;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bundle = 'test-'.bin2hex(random_bytes(8));
        $this->destination = base_path('generated/integration/'.$this->bundle);
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
        FixtureController::$calls = 0;
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->destination);
        parent::tearDown();
    }

    private function generate(array $options = [], string $command = 'ulaxys:generate-integration', int $exit = 0): void
    {
        $this->artisan($command, ['--output' => $this->bundle, ...$options])->assertExitCode($exit);
    }

    private function artifact(string $path): array
    {
        return json_decode(file_get_contents($this->destination.'/'.$path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function snapshot(): array
    {
        $result = [];
        if (is_dir($this->destination)) {
            foreach ((new Filesystem)->allFiles($this->destination, true) as $file) {
                $result[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($result);

        return $result;
    }

    public function test_real_domain_reference_routes_generate_explicit_gateway_and_identity_contracts(): void
    {
        $this->app->bind(ExampleLabelRepositoryInterface::class, PostgresExampleLabelRepository::class);
        Route::middleware(['api', 'service.signature', 'json.body', 'lang'])->group(function () {
            Route::get('/example/labels', [ExampleLabelController::class, 'index'])->name('fixture.labels.index');
            Route::post('/example/labels', [ExampleLabelController::class, 'store'])->name('fixture.labels.store');
        });
        $metadata = config('integration');
        $metadata['modules'][] = require base_path('tests/Fixtures/DomainExample/integration.php');
        config(['integration' => $metadata]);

        $this->generate(['--strict' => true]);
        $gateway = $this->artifact('gateway/maingate.routes.json');
        $routes = array_column($gateway['routes'], null, 'name');
        $this->assertSame('/services/fixture/example/labels', $routes['fixture.labels.index']['destinationPath']);
        $this->assertSame('index', $routes['fixture.labels.index']['permission']['action']);
        $this->assertSame('create', $routes['fixture.labels.store']['permission']['action']);
        $this->assertSame(['GET'], $routes['fixture.labels.index']['methods']);
        $this->assertSame(['POST'], $routes['fixture.labels.store']['methods']);

        $identity = $this->artifact('identity/permissions.json');
        $subjects = array_column($identity['permissions'], null, 'subject');
        $this->assertSame(['create', 'index'], array_column($subjects['fixture-labels']['actions'], 'action'));
        $this->assertSame('fixture', $subjects['fixture-labels']['actions'][0]['module']);
        $this->generate(['--all' => true, '--check' => true, '--strict' => true]);
    }

    public function test_modular_discovery_preserves_parameters_methods_order_and_does_not_execute_handlers(): void
    {
        $before = $this->app['router']->getRoutes();
        $discovery = app(RouteDiscovery::class)->collect($this->app['router']);
        $routes = array_column($discovery['routes'], null, 'name');
        $this->assertCount(10, $routes);
        $this->assertCount(3, $discovery['excluded']);
        $this->assertSame(['GET'], $routes['fixture.records.index']['methods']);
        $this->assertSame(['HEAD'], $routes['fixture.records.status']['methods']);
        $this->assertSame(['PUT', 'PATCH'], $routes['fixture.records.update']['methods']);
        $this->assertSame('/records/{record}/children/{child}', $routes['fixture.records.show']['uri']);
        $this->assertSame([['name' => 'record', 'optional' => false, 'constraint' => '[0-9]+'], ['name' => 'child', 'optional' => false, 'constraint' => '[a-z]+']], $routes['fixture.records.show']['parameters']);
        $this->generate(['--strict' => true]);
        $this->assertSame($before, $this->app['router']->getRoutes());
        $this->assertSame(0, FixtureController::$calls);
        $manifest = $this->artifact('gateway/maingate.routes.json');
        $this->assertFalse($manifest['deployReady']);
        $this->assertTrue($manifest['securityRequirements']['stripIncomingSignature']);
        $records = array_column($manifest['routes'], null, 'name');
        $this->assertSame('/services/fixture/records/{record}/children/{child}', $records['fixture.records.show']['destinationPath']);
        $this->assertSame('records', $records['fixture.records.show']['module']);
        $this->assertSame('index', $records['fixture.records.search']['permission']['action']); // POST read is not create.
        $this->assertCount(2, $manifest['excludedOperations']);
        $service = $this->artifact('gateway/service-gateway.routes.json');
        $this->assertSame('serviceGateway', $service['target']);
        $this->assertSame('/fixture/records', $service['routes'][0]['destinationPath']);
    }

    public function test_explicit_head_can_override_get_alias_and_optional_parameter_is_preserved(): void
    {
        Route::middleware(['api', 'service.signature'])->group(function () {
            Route::get('/probe', fn () => null)->name('fixture.probe.get');
            Route::match(['HEAD'], '/probe', fn () => null)->name('fixture.probe.head');
            Route::get('/optional/{key?}', fn () => null)->name('fixture.optional');
        });
        $routes = array_column(app(RouteDiscovery::class)->collect($this->app['router'])['routes'], null, 'name');
        $this->assertSame(['HEAD'], $routes['fixture.probe.head']['methods']);
        $this->assertTrue($routes['fixture.optional']['parameters'][0]['optional']);
    }

    public function test_laravel_builtin_parameter_constraints_are_preserved(): void
    {
        Route::middleware(['api', 'service.signature'])->group(function () {
            foreach (['whereAlpha', 'whereAlphaNumeric', 'whereNumber', 'whereUlid', 'whereUuid'] as $helper) {
                Route::get('/supported/'.$helper.'/{value}', fn () => null)->name('supported.'.$helper)->{$helper}('value');
            }
        });
        $routes = array_column(app(RouteDiscovery::class)->collect($this->app['router'])['routes'], null, 'name');
        foreach (['whereAlpha', 'whereAlphaNumeric', 'whereNumber', 'whereUlid', 'whereUuid'] as $helper) {
            $this->assertSame('value', $routes['supported.'.$helper]['parameters'][0]['name']);
            $this->assertNotNull($routes['supported.'.$helper]['parameters'][0]['constraint']);
        }
    }

    public function test_permission_declaration_has_multiple_subjects_semantic_actions_and_localization_keys(): void
    {
        $this->generate([], 'ulaxys:generate-permissions');
        $declaration = $this->artifact('identity/permissions.json');
        $this->assertFalse($declaration['executableSeeder']);
        $subjects = $declaration['permissions'];
        $this->assertSame(['fixtureAudits', 'fixtureRecords'], array_column($subjects, 'subject'));
        $this->assertSame(['create', 'delete', 'edit', 'index', 'show'], array_column($subjects[1]['actions'], 'action'));
        $this->assertSame('permission.fixtureRecords', $subjects[1]['description']);
        $this->assertSame('fixture', $subjects[1]['actions'][0]['module']);
        $this->assertFileDoesNotExist($this->destination.'/gateway/maingate.routes.json');
        $this->assertStringNotContainsString('"id"', file_get_contents($this->destination.'/identity/permissions.json'));
    }

    public function test_dry_run_check_and_idempotent_generation(): void
    {
        $this->generate(['--dry-run' => true, '--strict' => true]);
        $this->assertDirectoryDoesNotExist($this->destination);
        $this->generate(['--check' => true], exit: 1);
        $this->assertDirectoryDoesNotExist($this->destination);
        $this->generate(['--all' => true]);
        $before = $this->snapshot();
        $mtime = filemtime($this->destination.'/identity/permissions.json');
        $this->generate(['--check' => true]);
        $this->generate(['--all' => true]);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($mtime, filemtime($this->destination.'/identity/permissions.json'));
        $metadata = config('integration');
        $metadata['gateway']['mainGate']['prefix'] = 'new/service';
        config(['integration' => $metadata]);
        $this->generate(['--check' => true], exit: 1);
        $this->assertSame($before, $this->snapshot());
        $this->generate();
        $this->generate(['--check' => true]);
        $this->assertNotSame($before, $this->snapshot());
    }

    public function test_independent_commands_and_destination_changes_preserve_other_targets_and_remove_owned_obsolete_files(): void
    {
        $this->generate([], 'ulaxys:generate-gateway');
        $gateway = file_get_contents($this->destination.'/gateway/maingate.routes.json');
        $this->generate([], 'ulaxys:generate-permissions');
        $this->assertSame($gateway, file_get_contents($this->destination.'/gateway/maingate.routes.json'));
        $config = config('integration');
        $config['gateway']['mainGate']['file'] = 'custom/routes.json';
        config(['integration' => $config]);
        $this->generate(['--gateway' => true, '--check' => true], exit: 1);
        $this->generate(['--gateway' => true]);
        $this->assertFileExists($this->destination.'/custom/routes.json');
        $this->assertFileDoesNotExist($this->destination.'/gateway/maingate.routes.json');
        $this->assertFileExists($this->destination.'/identity/permissions.json');
        $this->generate(['--permissions' => true, '--check' => true]);
    }

    public function test_manual_and_edited_files_are_never_overwritten_or_partially_published(): void
    {
        mkdir($this->destination.'/identity', 0755, true);
        file_put_contents($this->destination.'/identity/permissions.json', 'human-authored');
        $this->generate(exit: 1);
        $this->assertSame('human-authored', file_get_contents($this->destination.'/identity/permissions.json'));
        $this->assertDirectoryDoesNotExist($this->destination.'/gateway');
        unlink($this->destination.'/identity/permissions.json');
        $this->generate();
        file_put_contents($this->destination.'/identity/permissions.json', 'manually-edited');
        $before = $this->snapshot();
        $this->generate(exit: 1);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_staging_failure_leaves_entire_previous_bundle_intact(): void
    {
        $writer = app(OutputWriter::class);
        $writer->apply(['a.json' => ['kind' => 'gateway-route-manifest', 'value' => 1]], $this->bundle, false, false);
        $before = $this->snapshot();
        $fs = new class extends Filesystem
        {
            private int $writes = 0;

            public function put($path, $contents, $lock = false)
            {
                if (++$this->writes === 2) {
                    throw new IntegrationException('Simulated disk failure during staging.');
                }

                return parent::put($path, $contents, $lock);
            }
        };
        try {
            (new OutputWriter($fs))->apply(['a.json' => ['kind' => 'gateway-route-manifest', 'value' => 2], 'b.json' => ['kind' => 'gateway-route-manifest']], $this->bundle, false, false);
            $this->fail('Expected staging failure.');
        } catch (IntegrationException $e) {
            $this->assertStringContainsString('disk failure', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], glob(base_path('generated/integration/.stage-*')));
    }

    public function test_publication_failure_rolls_back_and_preserves_manual_sibling_files(): void
    {
        app(OutputWriter::class)->apply(['a.json' => ['kind' => 'gateway-route-manifest', 'value' => 1]], $this->bundle, false, false);
        file_put_contents($this->destination.'/review.txt', 'human notes');
        $before = $this->snapshot();
        $fs = new class extends Filesystem
        {
            private int $moves = 0;

            public function move($path, $target)
            {
                if (++$this->moves === 2) {
                    throw new IntegrationException('Simulated publication failure.');
                }

                return parent::move($path, $target);
            }
        };
        try {
            (new OutputWriter($fs))->apply(['a.json' => ['kind' => 'gateway-route-manifest', 'value' => 2]], $this->bundle, false, false);
            $this->fail('Expected publication failure.');
        } catch (IntegrationException $e) {
            $this->assertStringContainsString('publication failure', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        app(OutputWriter::class)->apply(['a.json' => ['kind' => 'gateway-route-manifest', 'value' => 2]], $this->bundle, false, false);
        $this->assertSame('human notes', file_get_contents($this->destination.'/review.txt'));
    }

    #[DataProvider('invalidMetadata')]
    public function test_invalid_metadata_fails_without_outputs(string $case): void
    {
        $config = config('integration');
        switch ($case) {
            case 'version': $config['schemaVersion'] = 2;
                break;
            case 'identifier': $config['service'] = '../invalid';
                break;
            case 'unknown-key': $config['credentials'] = 'forbidden';
                break;
            case 'duplicate-module': $config['modules'][] = $config['modules'][0];
                break;
            case 'duplicate-subject': $config['modules'][1]['permissions']['subject'] = 'fixtureRecords';
                break;
            case 'duplicate-action': $config['modules'][0]['permissions']['actions'][] = $config['modules'][0]['permissions']['actions'][0];
                break;
            case 'missing-mapping': unset($config['modules'][0]['operations']['fixture.records.store']);
                break;
            case 'unknown-action': $config['modules'][0]['operations']['fixture.records.store']['permission'] = 'unknown';
                break;
            case 'conflicting-mapping': $config['modules'][1]['operations']['fixture.records.index'] = ['operation' => 'collision', 'permission' => 'index', 'expose' => true];
                break;
            case 'both-exemption-and-permission': $config['modules'][0]['operations']['fixture.records.store']['exempt'] = 'No.';
                break;
            case 'stale-operation': $config['modules'][1]['operations']['unregistered'] = ['operation' => 'missing', 'permission' => 'index', 'expose' => true];
                break;
            case 'public-exposure': $config['modules'][0]['operations']['fixture.records.public']['expose'] = true;
                break;
            case 'traversal': $config['permissionsFile'] = '../escape.json';
                break;
            case 'php-output': $config['permissionsFile'] = 'identity/Seeder.php';
                break;
            case 'destination-collision': $config['permissionsFile'] = $config['gateway']['mainGate']['file'];
                break;
        }
        config(['integration' => $config]);
        $this->generate(exit: 1);
        $this->assertDirectoryDoesNotExist($this->destination);
    }

    public static function invalidMetadata(): array
    {
        return array_map(fn ($case) => [$case], ['version', 'identifier', 'unknown-key', 'duplicate-module', 'duplicate-subject', 'duplicate-action', 'missing-mapping', 'unknown-action', 'conflicting-mapping', 'both-exemption-and-permission', 'stale-operation', 'public-exposure', 'traversal', 'php-output', 'destination-collision']);
    }

    public function test_unused_permissions_warn_normally_but_fail_strict(): void
    {
        $config = config('integration');
        $config['modules'][0]['permissions']['actions'][] = ['action' => 'unused', 'description' => 'permission.unused', 'module' => 'fixture'];
        config(['integration' => $config]);
        $this->generate(['--strict' => true], exit: 1);
        $this->assertDirectoryDoesNotExist($this->destination);
        $this->generate();
        $this->assertContains('Unused permission action fixtureRecords/unused', $this->artifact('identity/compatibility.json')['warnings']);
    }

    #[DataProvider('invalidRoutes')]
    public function test_conflicts_shadowing_and_unsupported_routes_fail_closed(string $case, string $message): void
    {
        Route::middleware(['api', 'service.signature'])->group(function () use ($case) {
            switch ($case) {
                case 'duplicate': Route::get('/duplicate', fn () => null)->name('first');
                    Route::get('/duplicate', fn () => null)->name('second');
                    break;
                case 'shadow': Route::get('/shadow/{key}', fn () => null)->name('first');
                    Route::get('/shadow/static', fn () => null)->name('second');
                    break;
                case 'ambiguous': Route::get('/same/{first}', fn () => null)->name('first');
                    Route::get('/same/{second}', fn () => null)->name('second');
                    break;
                case 'middleware': Route::get('/unsupported', fn () => null)->middleware('auth')->name('unsupported');
                    break;
                case 'method': Route::match(['CONNECT'], '/unsupported', fn () => null)->name('unsupported');
                    break;
                case 'unnamed': Route::get('/unnamed', fn () => null);
                    break;
                case 'catchall': Route::get('/all/{path}', fn () => null)->where('path', '.*')->name('catchall');
                    break;
                case 'uppercase-slash': Route::get('/unsafe-pattern/{value}', fn () => null)->where('value', '[A-Z/]+')->name('unsafe');
                    break;
                case 'encoded-slash': Route::get('/unsafe-pattern/{value}', fn () => null)->where('value', 'foo\\x2fbar')->name('unsafe');
                    break;
                case 'dot-uri': Route::get('/unsafe/../other', fn () => null)->name('unsafe');
                    break;
                case 'embedded': Route::get('/prefix-{key}', fn () => null)->name('embedded');
                    break;
                case 'optional-shadow': Route::get('/shadow-optional/{key?}', fn () => null)->name('first');
                    Route::get('/shadow-optional', fn () => null)->name('second');
                    break;
                case 'crossed-shadow': Route::get('/crossed/{key}/fixed', fn () => null)->name('first');
                    Route::get('/crossed/fixed/{key}', fn () => null)->name('second');
                    break;
                case 'domain': Route::domain('example.test')->get('/domain', fn () => null)->name('domain');
                    break;
            }
        });
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage($message);
        app(RouteDiscovery::class)->collect($this->app['router']);
    }

    public static function invalidRoutes(): array
    {
        return [['duplicate', 'Duplicate/conflicting'], ['shadow', 'shadowing'], ['ambiguous', 'shadowing'], ['middleware', 'Unsupported resolved middleware'], ['method', 'Unsupported HTTP method'], ['unnamed', 'unique explicit names'], ['catchall', 'catch-all'], ['domain', 'Domain-scoped'], ['uppercase-slash', 'Unsupported parameter constraint'], ['encoded-slash', 'Unsupported parameter constraint'], ['dot-uri', 'Unsupported URI segment'], ['embedded', 'Unsupported URI segment'], ['optional-shadow', 'shadowing'], ['crossed-shadow', 'shadowing']];
    }

    public function test_semantically_reordered_metadata_produces_identical_output_bytes(): void
    {
        $this->generate(['--strict' => true]);
        $before = $this->snapshot();
        $config = config('integration');
        foreach ($config['modules'] as &$module) {
            $module['operations'] = array_reverse($module['operations'], true);
            $module['permissions']['actions'] = array_map(fn ($action) => array_reverse($action, true), array_reverse($module['permissions']['actions']));
        }
        unset($module);
        $config['modules'] = array_reverse($config['modules']);
        config(['integration' => $config]);
        $this->generate(['--strict' => true]);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_signature_alias_replacement_cannot_claim_a_protected_boundary(): void
    {
        app(Kernel::class);
        $this->app['router']->aliasMiddleware('service.signature', SubstituteBindings::class);
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Signature alias');
        app(RouteDiscovery::class)->collect($this->app['router']);
    }

    public function test_nested_unknown_group_middleware_is_rejected(): void
    {
        app(Kernel::class);
        $this->app['router']->pushMiddlewareToGroup('api', Authenticate::class);
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Unsupported resolved middleware');
        app(RouteDiscovery::class)->collect($this->app['router']);
    }

    public function test_middleware_exclusion_cannot_bypass_signature(): void
    {
        Route::middleware(['api', 'service.signature'])->get('/excluded-signature', fn () => null)->withoutMiddleware('service.signature')->name('excluded');
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('Excluded middleware');
        app(RouteDiscovery::class)->collect($this->app['router']);
    }

    public function test_cached_routes_are_refused_without_touching_real_cache(): void
    {
        $old = $this->app->bootstrapPath();
        $cached = $this->app->routesAreCached();
        $temporary = sys_get_temp_dir().'/integration-bootstrap-'.bin2hex(random_bytes(8));
        mkdir($temporary.'/cache', 0755, true);
        file_put_contents($temporary.'/cache/routes-v7.php', '<?php return [];');
        $this->app->useBootstrapPath($temporary);
        $this->app->forgetInstance('routes.cached');
        try {
            $this->expectException(IntegrationException::class);
            $this->expectExceptionMessage('route:clear');
            app(RouteDiscovery::class)->collect($this->app['router']);
        } finally {
            $this->app->useBootstrapPath($old);
            $this->app->instance('routes.cached', $cached);
            (new Filesystem)->deleteDirectory($temporary);
        }
    }

    public function test_output_traversal_and_symlink_escape_are_rejected(): void
    {
        $this->generate(['--output' => '../outside'], exit: 1);
        $this->generate(['--output' => '/tmp/outside'], exit: 1);
        $outside = sys_get_temp_dir().'/integration-outside-'.bin2hex(random_bytes(8));
        mkdir($outside);
        (new Filesystem)->ensureDirectoryExists(dirname($this->destination));
        symlink($outside, $this->destination);
        try {
            $this->generate(exit: 1);
            $this->assertSame([], array_diff(scandir($outside), ['.', '..']));
        } finally {
            unlink($this->destination);
            rmdir($outside);
        }
    }

    public function test_fresh_artisan_process_observes_real_routes_without_changing_http_registry_or_openapi(): void
    {
        $process = new Process([PHP_BINARY, 'artisan', 'ulaxys:generate-integration', '--strict', '--dry-run', '--output='.$this->bundle], base_path());
        $process->mustRun();
        $this->assertStringContainsString('MainGate installation requires runtime verification', $process->getOutput());
        $this->assertDirectoryDoesNotExist($this->destination);
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $gate = new Process([PHP_BINARY, '.docker/verify-openapi.php'], base_path());
        $gate->mustRun();
        $this->assertStringContainsString($this->mountedOperationCount().' mounted operations', $gate->getOutput());
    }

    public function test_runtime_router_does_not_use_registration_observer_and_invalid_options_fail(): void
    {
        $this->generate(['--all' => true, '--gateway' => true], exit: 1);
        $this->generate(['--dry-run' => true, '--check' => true], exit: 1);
        $process = new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo get_class($app["router"]->getRoutes()), " ", count($app["router"]->getRoutes());'], base_path());
        $process->mustRun();
        [$class, $count] = explode(' ', $process->getOutput());
        $this->assertSame('Illuminate\\Routing\\RouteCollection', $class);
        $routes = $this->runtimeRoutes();
        $this->assertCount((int) $count, $routes);
        $this->assertSame([], array_filter(array_column($routes, 'name'), fn ($name) => str_starts_with((string) $name, 'fixture.')));
        $this->assertSame(['healthCheck/apiStatus', 'healthCheck/postgresStatus', 'openapi.json'], array_values(array_intersect(
            ['healthCheck/apiStatus', 'healthCheck/postgresStatus', 'openapi.json'], array_column($routes, 'uri'))));
    }
}
