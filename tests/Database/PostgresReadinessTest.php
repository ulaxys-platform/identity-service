<?php

namespace Ulaxys\Identity\Tests\Database;

use Ulaxys\Identity\Tests\Support\DestructiveDatabaseGuard;
use Ulaxys\Identity\Tests\TestCase;

final class PostgresReadinessTest extends TestCase
{
    public function test_real_postgres_readiness_on_opted_in_disposable_database(): void
    {
        if (getenv('SERVICE_ALLOW_DESTRUCTIVE_TESTS') !== 'true') {
            $this->markTestSkipped('Requires explicit disposable PostgreSQL test configuration.');
        }
        DestructiveDatabaseGuard::assertSafe($this->app, $this->app['db']->connection());
        $this->getJson('/healthCheck/postgresStatus')->assertOk()->assertExactJson(['status' => 200, 'size' => 0, 'message' => 'Database is available']);
    }
}
