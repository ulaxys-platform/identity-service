<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity\Provider;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProviderLifecycleLock
{
    public function run(string $userId, Closure $operation): void
    {
        if (! Str::isUlid($userId)) {
            throw new ProviderFailure('A platform user ULID is required.');
        }

        // Session lock on the writer. No transaction spans a provider call.
        $pdo = DB::connection()->getPdo();
        $key = 'identity:provider-lifecycle:'.strtolower($userId);
        $acquire = $pdo->prepare('SELECT CASE WHEN pg_try_advisory_lock(hashtextextended(?, 0)) THEN 1 ELSE 0 END');
        $acquire->execute([$key]);
        if ((int) $acquire->fetchColumn() !== 1) {
            throw new ProviderFailure('Another provider lifecycle operation is in progress.');
        }

        try {
            $operation();
        } finally {
            $release = $pdo->prepare('SELECT pg_advisory_unlock(hashtextextended(?, 0))');
            $release->execute([$key]);
        }
    }
}
