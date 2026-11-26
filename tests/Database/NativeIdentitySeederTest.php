<?php

namespace Ulaxys\Identity\Tests\Database;

use Composer\Autoload\ClassLoader;
use Database\Seeders\Permissions\FixturePermissionSeeder;
use Database\Seeders\Permissions\SecondPermissionSeeder;
use Database\Seeders\Support\PermissionSeedWriter;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Ulaxys\Identity\Tests\Support\DestructiveDatabaseGuard;
use Ulaxys\Identity\Tests\Support\Identity\ConfiguresIdentityFixture;
use Ulaxys\Identity\Tests\TestCase;

final class NativeIdentitySeederTest extends TestCase
{
    use ConfiguresIdentityFixture;

    private ?string $schema = null;

    private ?string $oldSearchPath = null;

    private ?ClassLoader $loader = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('SERVICE_ALLOW_DESTRUCTIVE_TESTS') !== 'true') {
            $this->markTestSkipped('Requires explicitly opted-in disposable PostgreSQL.');
        }
        DestructiveDatabaseGuard::assertSafe($this->app, DB::connection());
        $this->configureIdentityFixture();
        $this->publishNative();
        $this->loader = new ClassLoader;
        $this->loader->addPsr4('Database\\Seeders\\', $this->directory.'/identity/database/seeders/');
        $this->loader->register(true);
        $this->oldSearchPath = DB::selectOne('SHOW search_path')->search_path;
        $this->schema = 'identity_test_'.bin2hex(random_bytes(8)); // Never interpolate externally supplied names.
        DB::statement('CREATE SCHEMA '.$this->schema);
        DB::statement('SET search_path TO '.$this->schema);
        DB::connection()->getSchemaBuilder()->create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('module');
            $table->string('subject');
            $table->string('action');
            $table->text('description');
            $table->string('model')->nullable();
            $table->bigInteger('role')->nullable();
            $table->boolean('active')->default(true);
        });
        // Only an isolated preservation sentinel, not a claim about real Identity grant schema.
        DB::connection()->getSchemaBuilder()->create('permission_grants', function (Blueprint $table) {
            $table->bigInteger('permission_id');
            $table->bigInteger('principal_id');
        });
    }

    protected function tearDown(): void
    {
        if ($this->schema !== null) {
            DB::statement('DROP SCHEMA '.$this->schema.' CASCADE');
            DB::statement('SELECT set_config(?, ?, false)', ['search_path', $this->oldSearchPath]);
        }
        $this->loader?->unregister();
        if (isset($this->directory)) {
            $this->removeIdentityFixture();
        }
        parent::tearDown();
    }

    private function catalogPath(): string
    {
        return $this->directory.'/identity/database/seeders/Support/permissions-fixture.php';
    }

    private function runWriter(): void
    {
        PermissionSeedWriter::seed($this->catalogPath());
    }

    private function changeCatalog(callable $change): void
    {
        $catalog = require $this->catalogPath();
        $catalog = $change($catalog);
        file_put_contents($this->catalogPath(), '<?php return '.var_export($catalog, true).';');
    }

    public function test_first_and_second_run_preserve_ids_roles_flags_grants_and_unrelated_records(): void
    {
        $description = json_encode(['tr' => 'Önceki İzin', 'en' => 'Previous Permission', 'ar' => 'الإذن السابق']);
        $id = DB::table('permissions')->insertGetId(['module' => 'fixture', 'subject' => 'fixtureRecords', 'action' => 'index',
            'description' => $description, 'model' => 'ExistingModel', 'role' => 41, 'active' => false]);
        $unrelated = DB::table('permissions')->insertGetId(['module' => 'other', 'subject' => 'other-records', 'action' => 'index', 'description' => $description]);
        DB::table('permission_grants')->insert(['permission_id' => $id, 'principal_id' => 73]);
        $this->runWriter();
        $this->assertSame(7, DB::table('permissions')->count());
        $row = DB::table('permissions')->where('id', $id)->first();
        $this->assertSame('ExistingModel', $row->model);
        $this->assertSame(41, $row->role);
        $this->assertFalse($row->active);
        $this->assertSame(['tr' => 'Listeleme İzni', 'en' => 'Listing Permission', 'ar' => 'إذن عرض القائمة'], json_decode($row->description, true));
        $before = DB::table('permissions')->orderBy('id')->get()->toJson();
        DB::connection()->enableQueryLog();
        $this->runWriter();
        $this->assertSame($before, DB::table('permissions')->orderBy('id')->get()->toJson());
        $mutations = array_filter(DB::getQueryLog(), fn ($query) => preg_match('/^(insert|update|delete|truncate)/i', $query['query']));
        $this->assertSame([], array_values($mutations));
        DB::connection()->disableQueryLog();
        $this->assertSame($description, DB::table('permissions')->where('id', $unrelated)->value('description'));
        $this->assertEquals([(object) ['permission_id' => $id, 'principal_id' => 73]], DB::table('permission_grants')->get()->all());
    }

    public function test_explicit_description_update_changes_only_description_and_retains_unicode(): void
    {
        $this->runWriter();
        $ids = DB::table('permissions')->orderBy('id')->pluck('id')->all();
        $this->changeCatalog(function ($catalog) {
            $catalog[0]['data']['description'] = ['tr' => 'Güncel İzin', 'en' => 'Updated Permission', 'ar' => 'إذن محدث'];

            return $catalog;
        });
        $this->runWriter();
        $this->assertSame($ids, DB::table('permissions')->orderBy('id')->pluck('id')->all());
        $this->assertSame('إذن محدث', json_decode(DB::table('permissions')->where('action', 'create')->value('description'), true)['ar']);
    }

    #[DataProvider('invalidCatalogs')]
    public function test_invalid_catalogs_fail_before_any_database_query_or_partial_write(string $case): void
    {
        $this->changeCatalog(function ($catalog) use ($case) {
            switch ($case) {
                case 'duplicate': $catalog[] = $catalog[0];
                    break;
                case 'conflict': $duplicate = $catalog[0];
                    $duplicate['data']['description']['en'] = 'Conflict';
                    $catalog[] = $duplicate;
                    break;
                case 'collision': $catalog[0]['data']['action'] = 'a';
                    $catalog[0]['data']['subject'] = 'b-c';
                    $catalog[1]['data']['action'] = 'a-b';
                    $catalog[1]['data']['subject'] = 'c';
                    break;
                case 'cross-module': $duplicate = $catalog[0];
                    $duplicate['data']['module'] = 'other';
                    $catalog[] = $duplicate;
                    break;
                case 'language': unset($catalog[1]['data']['description']['ar']);
                    break;
                case 'key': $catalog[1]['data']['description']['en'] = 'permission.test.index';
                    break;
                case 'role': $catalog[1]['data']['role'] = '__ROLE_KEY__:admin';
                    break;
                case 'model': $catalog[1]['data']['model'] = null;
                    break;
                case 'active': $catalog[1]['data']['active'] = false;
                    break;
                case 'subject': $catalog[1]['data']['subject'] = 'unsafe_subject';
                    break;
                case 'wrapper': $catalog[1]['id'] = 42;
                    break;
                case 'not-list': return ['invalid' => $catalog[0]];
            }

            return $catalog;
        });
        // Resolve the generated writer before query counting; autoload must be PSR-4 compatible.
        class_exists(PermissionSeedWriter::class);
        DB::connection()->enableQueryLog();
        try {
            $this->runWriter();
            $this->fail('Invalid catalog must fail.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::connection()->disableQueryLog();
        }
        $this->assertSame(0, DB::table('permissions')->count());
    }

    public static function invalidCatalogs(): array
    {
        return array_map(fn ($case) => [$case], ['duplicate', 'conflict', 'collision', 'cross-module', 'language', 'key', 'role', 'model', 'active', 'subject', 'wrapper', 'not-list']);
    }

    public function test_database_failure_rolls_back_earlier_inserts(): void
    {
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT simulated_failure CHECK (action <> 'delete')");
        try {
            $this->runWriter();
            $this->fail('Expected simulated database rejection.');
        } catch (QueryException) {
            $this->assertSame(0, DB::table('permissions')->count());
        }
    }

    public function test_existing_canonical_collision_is_rejected_without_mutation(): void
    {
        DB::table('permissions')->insert(['module' => 'other', 'subject' => 'fixtureRecords', 'action' => 'index', 'description' => '{}']);
        try {
            $this->runWriter();
            $this->fail('Ambiguous database identity must not be overwritten.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Ambiguous existing', $error->getMessage());
        }
        $this->assertSame(1, DB::table('permissions')->count());
    }

    public function test_existing_duplicate_identity_and_cross_subject_middleware_collision_are_rejected(): void
    {
        $row = ['module' => 'fixture', 'subject' => 'fixtureRecords', 'action' => 'index', 'description' => '{}'];
        DB::table('permissions')->insert([$row, $row]);
        try {
            $this->runWriter();
            $this->fail('Duplicate database identities must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Ambiguous existing', $exception->getMessage());
        }
        $this->assertSame(2, DB::table('permissions')->count());
        // Clear only this test's isolated schema fixture, never shared/production tables.
        DB::table('permissions')->delete();
        $this->changeCatalog(function ($catalog) {
            $catalog[0]['data']['subject'] = 'b-c';
            $catalog[0]['data']['action'] = 'a';

            return $catalog;
        });
        DB::table('permissions')->insert(['module' => 'fixture', 'subject' => 'c', 'action' => 'a-b', 'description' => '{}']);
        try {
            $this->runWriter();
            $this->fail('Different tuples sharing a middleware name must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Ambiguous existing', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('permissions')->count());
    }

    public function test_service_specific_seeders_load_catalogs_and_coexist_using_one_shared_writer(): void
    {
        // All generated classes are autoloaded through the package's PSR-4 root, never source database/seeders.
        $installed = $this->directory.'/installed/seeders';
        (new Filesystem)->copyDirectory($this->directory.'/identity/database/seeders', $installed);
        $this->loader->addPsr4('Database\\Seeders\\', $installed, true);
        (new FixturePermissionSeeder)->run();
        $this->assertSame(6, DB::table('permissions')->count());
        $config = config('integration');
        $config['service'] = 'second';
        foreach ($config['modules'] as &$module) {
            $module['permissions']['subject'] = 'second-'.$module['permissions']['subject'];
            foreach ($module['permissions']['actions'] as &$action) {
                $action['module'] = 'second';
            }
            unset($action);
        }
        unset($module);
        config(['integration' => $config]);
        foreach ($this->nativeFiles() as $path => $file) {
            if (str_starts_with($path, 'identity/database/seeders/') && ! str_ends_with($path, 'PermissionSeedWriter.php')) {
                $target = $installed.'/'.substr($path, strlen('identity/database/seeders/'));
                (new Filesystem)->ensureDirectoryExists(dirname($target));
                file_put_contents($target, $file->contents);
            }
        }
        (new SecondPermissionSeeder)->run();
        $this->assertSame(12, DB::table('permissions')->count());
        (new SecondPermissionSeeder)->run();
        $this->assertSame(12, DB::table('permissions')->count());
        (new FixturePermissionSeeder)->run();
        $this->assertSame(12, DB::table('permissions')->count());
    }
}
