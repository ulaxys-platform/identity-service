<?php

namespace Ulaxys\Identity\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

final class DestructiveDatabaseGuard
{
    public static function assertSafe(Application $app, Connection $connection): void
    {
        $requirements = 'Use a disposable PostgreSQL database named exactly service_test or service_testing; export APP_ENV=testing and SERVICE_ALLOW_DESTRUCTIVE_TESTS=true. Clear stale Laravel configuration with php artisan config:clear and verify the connection before retrying.';
        if (getenv('APP_ENV') !== 'testing' || $app->environment() !== 'testing') {
            throw new RuntimeException('Destructive database tests refused: both runtime APP_ENV and Laravel environment must be testing. '.$requirements);
        }
        // Read the explicit process opt-in, never a potentially cached configuration value.
        if (getenv('SERVICE_ALLOW_DESTRUCTIVE_TESTS') !== 'true') {
            throw new RuntimeException('Destructive database tests refused: SERVICE_ALLOW_DESTRUCTIVE_TESTS must be exactly true. '.$requirements);
        }
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Destructive database tests refused: the active driver must be pgsql. '.$requirements);
        }
        try {
            // Use the write PDO: migrate:fresh must not be authorized by a read replica's identity.
            $identity = $connection->selectOne('SELECT current_database() AS database_name', [], false);
        } catch (Throwable $e) {
            throw new RuntimeException('Destructive database tests refused: could not verify the active PostgreSQL database identity. '.$requirements, 0, $e);
        }
        if (! in_array($identity->database_name ?? null, ['service_test', 'service_testing'], true)) {
            throw new RuntimeException('Destructive database tests refused: current_database() must be exactly service_test or service_testing. '.$requirements);
        }
    }
}
