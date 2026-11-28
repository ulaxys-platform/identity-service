<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\Repository;

use Illuminate\Support\Facades\DB;
use Ulaxys\Identity\Models\PlatformAdministrator;
use Ulaxys\Identity\Repositories\RepositoryInterface\PlatformAdministratorRepositoryInterface;

final class PlatformAdministratorRepository implements PlatformAdministratorRepositoryInterface
{
    public function findActiveByUserId(string $userId): ?PlatformAdministrator
    {
        return PlatformAdministrator::query()->where('user_id', $userId)->whereNull('revoked_at')->first();
    }

    public function lockBootstrap(): void
    {
        DB::statement('LOCK TABLE identity_platform_administrators IN SHARE ROW EXCLUSIVE MODE');
    }

    public function hasAnyGrant(): bool
    {
        return PlatformAdministrator::query()->exists();
    }

    public function create(array $attributes): PlatformAdministrator
    {
        return PlatformAdministrator::query()->create($attributes);
    }
}
