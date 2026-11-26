<?php

namespace Ulaxys\Identity\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Ulaxys\Identity\Integration\IntegrationException;
use Ulaxys\Identity\Integration\NativeIdentityFile;
use Ulaxys\Identity\Integration\OutputWriter;
use Ulaxys\Identity\Tests\Support\Identity\ConfiguresIdentityFixture;
use Ulaxys\Identity\Tests\TestCase;

final class NativeIdentityGeneratorTest extends TestCase
{
    use ConfiguresIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureIdentityFixture();
    }

    protected function tearDown(): void
    {
        $this->removeIdentityFixture();
        parent::tearDown();
    }

    private function generate(array $options = [], int $exit = 0, string $command = 'ulaxys:generate-integration'): void
    {
        $this->artisan($command, ['--strict' => true, '--output' => $this->bundle, ...$options])->assertExitCode($exit);
    }

    private function catalog(): array
    {
        return require $this->directory.'/identity/database/seeders/Support/permissions-fixture.php';
    }

    public function test_native_output_namespaces_syntax_translations_and_gateway_identity_parity(): void
    {
        $before = $this->app['router']->getRoutes();
        $this->generate(['--all' => true]);
        $this->assertSame($before, $this->app['router']->getRoutes());
        $this->assertFileDoesNotExist($this->directory.'/identity/database/seeders/DatabaseSeeder.php');
        $root = $this->directory.'/identity';
        $catalog = $this->catalog();
        $this->assertCount(6, $catalog);
        $manifest = json_decode(file_get_contents($root.'/database/seeders/Support/permissions-fixture-keys.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['schema_version', 'service', 'permissions'], array_keys($manifest));
        $names = [];
        foreach ($catalog as $entry) {
            $this->assertSame(['data'], array_keys($entry));
            $data = $entry['data'];
            $this->assertSame('fixture', $data['module']);
            $this->assertSame(['tr', 'en', 'ar'], array_keys($data['description']));
            $names[] = $data['action'].'-'.$data['subject'];
        }
        $this->assertSame($names, array_column($manifest['permissions'], 'name'));
        $this->assertCount(count($names), array_unique($names));
        $this->assertStringContainsString('إذن', file_get_contents($root.'/database/seeders/Support/permissions-fixture.php'));
        $gateway = new Process([PHP_BINARY, 'tests/Support/MainGate/export-package.php', $this->directory.'/gateway/maingate/routes/fixture.php', $this->directory.'/gateway/maingate/controllers/FixtureController.php'], base_path());
        $gateway->mustRun();
        $used = [];
        foreach (json_decode($gateway->getOutput(), true, 512, JSON_THROW_ON_ERROR) as $route) {
            $this->assertSame('auth', $route['middleware'][0]);
            $permission = substr($route['middleware'][1], strlen('permission:'));
            $this->assertContains($permission, $names);
            $used[$permission] = true;
        }
        $this->assertEqualsCanonicalizing($names, array_keys($used));
        foreach ((new Filesystem)->allFiles($root) as $file) {
            if ($file->getExtension() === 'php') {
                (new Process([PHP_BINARY, '-l', $file->getPathname()]))->mustRun();
            }
        }
        $worker = new Process([PHP_BINARY, '-r', 'require "vendor/autoload.php"; $loader=new Composer\\Autoload\\ClassLoader; $loader->addPsr4("Database\\\\Seeders\\\\",$argv[1]); $loader->register(true); if (!class_exists("Database\\\\Seeders\\\\Permissions\\\\FixturePermissionSeeder") || !class_exists("Database\\\\Seeders\\\\Support\\\\PermissionSeedWriter")) {exit(1);} echo "PSR-4 verified";', $root.'/database/seeders/'], base_path());
        $worker->mustRun();
        $this->assertStringContainsString('PSR-4 verified', $worker->getOutput());
        $this->assertStringContainsString('do not overwrite', file_get_contents($root.'/README.md'));
        $this->generate(['--check' => true]);
    }

    public function test_legacy_strings_are_json_only_by_default_and_strict_native_requires_translations(): void
    {
        config(['integration' => require base_path('tests/Fixtures/Integration/metadata.php')]);
        $this->generate(['--permission-format' => 'both'], 1);
        $this->assertDirectoryDoesNotExist($this->directory);
        $this->generate(['--permission-format' => 'native'], 1);
        $this->generate();
        $this->assertFileExists($this->directory.'/identity/permissions.json');
        $this->assertFileDoesNotExist($this->directory.'/identity/README.md');
        $this->generate(['--permission-format' => 'json']);
    }

    public function test_independent_format_switching_ownership_dry_run_and_stale_check(): void
    {
        $this->generate(['--dry-run' => true]);
        $this->assertDirectoryDoesNotExist($this->directory);
        $this->generate(['--check' => true], 1);
        $this->generate();
        $file = $this->directory.'/identity/database/seeders/Support/permissions-fixture.php';
        $before = file_get_contents($file);
        $mtime = filemtime($file);
        $gateway = file_get_contents($this->directory.'/gateway/maingate/routes/fixture.php');
        $this->generate();
        $this->assertSame($mtime, filemtime($file));
        $config = config('integration');
        $config['modules'][0]['permissions']['actions'][0]['description']['tr'] = 'Yeni Görüntüleme İzni';
        config(['integration' => $config]);
        $this->generate(['--check' => true], 1);
        $this->assertSame($before, file_get_contents($file));
        $this->generate(['--permission-format' => 'native'], command: 'ulaxys:generate-permissions');
        $this->assertFileDoesNotExist($this->directory.'/identity/permissions.json');
        $this->assertSame($gateway, file_get_contents($this->directory.'/gateway/maingate/routes/fixture.php'));
        file_put_contents($this->directory.'/review.txt', 'manual notes');
        $this->generate(['--permission-format' => 'json'], command: 'ulaxys:generate-permissions');
        $this->assertFileDoesNotExist($file);
        $this->assertSame('manual notes', file_get_contents($this->directory.'/review.txt'));
        $this->generate();
        file_put_contents($file, 'manual edit');
        $this->generate(exit: 1);
        $this->assertSame('manual edit', file_get_contents($file));
    }

    #[DataProvider('invalidMetadata')]
    public function test_invalid_multilingual_or_native_identity_metadata_is_not_published(string $case): void
    {
        $config = config('integration');
        switch ($case) {
            case 'language': unset($config['modules'][0]['permissions']['actions'][0]['description']['ar']);
                break;
            case 'extra-language': $config['modules'][0]['permissions']['actions'][0]['description']['de'] = 'text';
                break;
            case 'key': $config['modules'][0]['permissions']['actions'][0]['description']['tr'] = 'permission.records.index';
                break;
            case 'other-key': $config['modules'][0]['permissions']['actions'][0]['description']['tr'] = 'labels.records.index';
                break;
            case 'empty': $config['modules'][0]['permissions']['actions'][0]['description']['ar'] = '';
                break;
            case 'utf8': $config['modules'][0]['permissions']['actions'][0]['description']['ar'] = "\xff";
                break;
            case 'optional': $config['modules'][0]['permissions']['actions'][0]['role'] = 1;
                break;
            case 'subject': $config['modules'][0]['permissions']['subject'] = 'unsafe_subject';
                break;
            case 'action': $config['modules'][0]['permissions']['actions'][0]['action'] = 'bad_action';
                break;
        }
        config(['integration' => $config]);
        $this->generate(exit: 1);
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public static function invalidMetadata(): array
    {
        return array_map(fn ($case) => [$case], ['language', 'extra-language', 'key', 'other-key', 'empty', 'utf8', 'optional', 'subject', 'action']);
    }

    public function test_localization_order_is_deterministic_and_does_not_change_existing_native_gateway_files(): void
    {
        $this->generate();
        $native = $this->nativeFiles();
        $gateway = file_get_contents($this->directory.'/gateway/maingate/controllers/FixtureController.php');
        $config = config('integration');
        foreach ($config['modules'] as &$module) {
            foreach ($module['permissions']['actions'] as &$action) {
                $action['description'] = array_reverse($action['description'], true);
            }
            unset($action);
        }
        unset($module);
        config(['integration' => $config]);
        foreach ($this->nativeFiles() as $path => $file) {
            $this->assertSame($native[$path]->contents, $file->contents);
        }
        $this->generate();
        $this->assertSame($gateway, file_get_contents($this->directory.'/gateway/maingate/controllers/FixtureController.php'));
        config(['integration' => require base_path('tests/Fixtures/Integration/metadata.php')]);
        $this->generate(['--permission-format' => 'json']);
        $this->assertSame($gateway, file_get_contents($this->directory.'/gateway/maingate/controllers/FixtureController.php'));
    }

    public function test_fresh_artisan_process_native_publication_stale_check_and_no_database_execution(): void
    {
        $arguments = [PHP_BINARY, 'tests/Support/MainGate/generate-fixture.php', 'ulaxys:generate-integration', '--all', '--strict', '--output='.$this->bundle];
        $environment = ['ULAXYS_TEST_LOCALIZED_METADATA' => '1', 'DB_HOST' => 'not-a-database.invalid'];
        (new Process([...$arguments, '--dry-run'], base_path(), $environment))->mustRun();
        $this->assertDirectoryDoesNotExist($this->directory);
        (new Process($arguments, base_path(), $environment))->mustRun();
        (new Process([...$arguments, '--check'], base_path(), $environment))->mustRun();
        $file = $this->directory.'/identity/database/seeders/Support/permissions-fixture.php';
        $before = file_get_contents($file);
        $stale = new Process([...$arguments, '--check'], base_path(), [...$environment, 'ULAXYS_TEST_CHANGED_TRANSLATION' => '1']);
        $stale->run();
        $this->assertSame(1, $stale->getExitCode());
        $this->assertSame($before, file_get_contents($file));
        $this->assertFileDoesNotExist(database_path('seeders/Permissions/FixturePermissionSeeder.php'));
    }

    public function test_native_paths_and_mixed_publication_rollback_are_protected(): void
    {
        $this->generate();
        $ledger = file_get_contents($this->directory.'/.ulaxys-generated.json');
        $config = config('integration');
        $config['modules'][0]['permissions']['actions'][0]['description']['en'] = 'Changed permission';
        config(['integration' => $config]);
        $fs = new class extends Filesystem
        {
            private int $moves = 0;

            public function move($path, $target)
            {
                return ++$this->moves === 2 ? false : parent::move($path, $target);
            }
        };
        try {
            (new OutputWriter($fs))->apply($this->nativeFiles(), $this->bundle, false, false);
            $this->fail('Expected publication failure.');
        } catch (IntegrationException $error) {
            $this->assertStringContainsString('restored', $error->getMessage());
        }
        $this->assertSame($ledger, file_get_contents($this->directory.'/.ulaxys-generated.json'));
        $this->expectException(IntegrationException::class);
        NativeIdentityFile::fromRenderer('identity/../Seeder.php', '<?php');
    }
}
