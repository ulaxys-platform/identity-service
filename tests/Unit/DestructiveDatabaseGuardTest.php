<?php

namespace Ulaxys\Identity\Tests\Unit;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Ulaxys\Identity\Tests\Support\DestructiveDatabaseGuard;

class DestructiveDatabaseGuardTest extends TestCase
{
    private array $previousEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['APP_ENV', 'SERVICE_ALLOW_DESTRUCTIVE_TESTS'] as $name) {
            $this->previousEnvironment[$name] = getenv($name);
        }
        putenv('APP_ENV=testing');
        putenv('SERVICE_ALLOW_DESTRUCTIVE_TESTS=true');
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
        parent::tearDown();
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_unsafe_configuration_fails_before_database_query(?string $runtime, string $effective, ?string $flag, string $driver, string $reason): void
    {
        putenv($runtime === null ? 'APP_ENV' : 'APP_ENV='.$runtime);
        putenv($flag === null ? 'SERVICE_ALLOW_DESTRUCTIVE_TESTS' : 'SERVICE_ALLOW_DESTRUCTIVE_TESTS='.$flag);
        $app = $this->createStub(Application::class);
        $app->method('environment')->willReturn($effective);
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn($driver);
        $connection->expects($this->never())->method('selectOne');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($reason);
        DestructiveDatabaseGuard::assertSafe($app, $connection);
    }

    public static function unsafeConfigurations(): array
    {
        return [
            'missing environment' => [null, 'testing', 'true', 'pgsql', 'environment must be testing'],
            'production runtime with testing cache' => ['production', 'testing', 'true', 'pgsql', 'environment must be testing'],
            'production cache with testing runtime' => ['testing', 'production', 'true', 'pgsql', 'environment must be testing'],
            'missing opt-in' => ['testing', 'testing', null, 'pgsql', 'must be exactly true'],
            'false opt-in' => ['testing', 'testing', 'false', 'pgsql', 'must be exactly true'],
            'truthy opt-in' => ['testing', 'testing', '1', 'pgsql', 'must be exactly true'],
            'sqlite' => ['testing', 'testing', 'true', 'sqlite', 'driver must be pgsql'],
        ];
    }

    #[DataProvider('databaseNames')]
    public function test_only_actual_dedicated_database_identity_is_accepted(?string $actual, bool $safe): void
    {
        $app = $this->createStub(Application::class);
        $app->method('environment')->willReturn('testing');
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn('pgsql');
        // Cached/configured names cannot authorize the operation; only the writer's query can.
        $connection->expects($this->never())->method('getDatabaseName');
        $connection->expects($this->once())->method('selectOne')
            ->with('SELECT current_database() AS database_name', [], false)
            ->willReturn((object) ['database_name' => $actual]);
        if (! $safe) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('current_database() must be exactly');
        }
        DestructiveDatabaseGuard::assertSafe($app, $connection);
    }

    public static function databaseNames(): array
    {
        return [
            ['service_test', true], ['service_testing', true],
            ['service', false], ['service_v2', false], ['production', false],
            ['service_test_backup', false], ['', false], [null, false],
        ];
    }

    public function test_identity_query_failure_is_explicit_and_fails_closed(): void
    {
        $app = $this->createStub(Application::class);
        $app->method('environment')->willReturn('testing');
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn('pgsql');
        $connection->expects($this->once())->method('selectOne')->willThrowException(new RuntimeException('Connection unavailable'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not verify the active PostgreSQL database identity');
        DestructiveDatabaseGuard::assertSafe($app, $connection);
    }
}
