<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ulaxys\Identity\Tests\Fixtures\DomainExample\ExampleLabel;
use Ulaxys\Identity\Tests\Fixtures\DomainExample\ExampleLabelController;
use Ulaxys\Identity\Tests\Fixtures\DomainExample\ExampleLabelRepositoryInterface;
use Ulaxys\Identity\Tests\Fixtures\DomainExample\PostgresExampleLabelRepository;
use Ulaxys\Identity\Tests\Support\DestructiveDatabaseGuard;
use Ulaxys\Identity\Tests\TestCase;

final class DomainExampleIntegrationTest extends TestCase
{
    private string $fixtureTable;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('SERVICE_ALLOW_DESTRUCTIVE_TESTS') !== 'true') {
            $this->markTestSkipped('Requires explicit disposable PostgreSQL opt-in.');
        }
        DestructiveDatabaseGuard::assertSafe($this->app, $this->app['db']->connection());
        $this->fixtureTable = 'example_labels_'.bin2hex(random_bytes(8));
        ExampleLabel::useFixtureTable($this->fixtureTable);
        config(['services.internal.signature' => 'test-only-fixture-signature']);
        Schema::create($this->fixtureTable, function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->jsonb('title');
            $table->boolean('active')->default(true);
            $table->string('internal_note')->nullable();
        });
        $this->app->bind(ExampleLabelRepositoryInterface::class, PostgresExampleLabelRepository::class);
        Route::middleware(['api', 'service.signature', 'json.body', 'lang'])->get('/_example/labels', [ExampleLabelController::class, 'index']);
        Route::middleware(['api', 'service.signature', 'json.body', 'lang'])->post('/_example/labels', [ExampleLabelController::class, 'store']);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->fixtureTable) && $this->app) {
                DestructiveDatabaseGuard::assertSafe($this->app, $this->app['db']->connection());
                Schema::dropIfExists($this->fixtureTable);
            }
        } finally {
            ExampleLabel::useFixtureTable(null);
            parent::tearDown();
        }
    }

    public function test_real_http_validation_write_list_scoping_and_field_whitelist(): void
    {
        $this->withHeader('Signature', 'test-only-fixture-signature');
        $this->postJson('/_example/labels', ['code' => 'AA', 'title' => ['en' => 'Label'], 'unapproved' => true])->assertStatus(400);
        $this->postJson('/_example/labels', ['code' => 'AA', 'title' => ['en' => 'Label', 'other' => 'leak']])->assertStatus(400);
        $this->assertDatabaseCount($this->fixtureTable, 0);
        $this->postJson('/_example/labels', ['code' => 'AA', 'title' => ['en' => 'Label', 'ar' => 'اسم']])->assertCreated()->assertJsonPath('items.code', 'AA')->assertJsonMissingPath('items.internal_note');
        ExampleLabel::query()->where('code', 'AA')->update(['internal_note' => 'private']);
        $this->postJson('/_example/labels', ['code' => 'AA', 'title' => ['en' => 'Duplicate']])->assertStatus(409);
        $this->getJson('/_example/labels?limit=10&offset=0')->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('items.0.title.ar', 'اسم')->assertJsonMissingPath('items.0.internal_note');
        $this->getJson('/_example/labels?limit=999')->assertStatus(400);
        ExampleLabel::query()->where('code', 'AA')->update(['active' => false]);
        $this->getJson('/_example/labels')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_signature_is_required_before_domain_reads_or_writes(): void
    {
        $this->getJson('/_example/labels')->assertForbidden()
            ->assertExactJson(['status' => 403, 'code' => 403, 'message' => 'Forbidden.']);
        $this->withHeader('Signature', 'incorrect')->postJson('/_example/labels', ['code' => 'NO', 'title' => ['en' => 'Denied']])
            ->assertForbidden();
        $this->assertDatabaseCount($this->fixtureTable, 0);
        $this->withHeader('Signature', 'test-only-fixture-signature')->getJson('/_example/labels')->assertOk();
    }

    public function test_late_database_unique_violation_is_a_safe_409(): void
    {
        // Simulate another transaction winning between domain precheck and INSERT.
        ExampleLabel::query()->create(['code' => 'AA', 'title' => ['en' => 'Existing'], 'active' => true]);
        $real = new PostgresExampleLabelRepository;
        $this->app->bind(ExampleLabelRepositoryInterface::class, fn () => new class($real) implements ExampleLabelRepositoryInterface
        {
            public function __construct(private PostgresExampleLabelRepository $real) {}

            public function page(int $limit, int $offset): array
            {
                return $this->real->page($limit, $offset);
            }

            public function codeExists(string $code): bool
            {
                return false;
            }

            public function create(string $code, array $title): ExampleLabel
            {
                return $this->real->create($code, $title);
            }
        });

        $this->withHeader('Signature', 'test-only-fixture-signature')
            ->postJson('/_example/labels', ['code' => 'AA', 'title' => ['en' => 'Race']])
            ->assertStatus(409)
            ->assertExactJson(['status' => 409, 'code' => 409, 'message' => 'Label code already exists.']);
        $this->assertDatabaseCount($this->fixtureTable, 1);
    }

    public function test_transaction_rolls_back_when_repository_fails_after_insert(): void
    {
        $this->withHeader('Signature', 'test-only-fixture-signature');
        $real = new PostgresExampleLabelRepository;
        $this->app->bind(ExampleLabelRepositoryInterface::class, fn () => new class($real) implements ExampleLabelRepositoryInterface
        {
            public function __construct(private PostgresExampleLabelRepository $real) {}

            public function page(int $limit, int $offset): array
            {
                return $this->real->page($limit, $offset);
            }

            public function codeExists(string $code): bool
            {
                return $this->real->codeExists($code);
            }

            public function create(string $code, array $title): ExampleLabel
            {
                $this->real->create($code, $title);

                throw new \RuntimeException('simulated second-step failure');
            }
        });
        $this->postJson('/_example/labels', ['code' => 'RB', 'title' => ['en' => 'Rollback']])
            ->assertStatus(500)
            ->assertExactJson(['status' => 500, 'code' => 500, 'message' => 'Internal server error.']);
        $this->assertDatabaseMissing($this->fixtureTable, ['code' => 'RB']);
        $this->assertDatabaseCount($this->fixtureTable, 0);
    }
}
