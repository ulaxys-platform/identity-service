<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\Repository;

use Ulaxys\Identity\Models\PlatformAdministrator;
use Ulaxys\Identity\Repositories\RepositoryInterface\PlatformAdministratorRepositoryInterface;

final class PlatformAdministratorRepository implements PlatformAdministratorRepositoryInterface
{
    public function findActiveByUserId(string $userId): ?PlatformAdministrator
    {
        return PlatformAdministrator::query()->where('user_id', $userId)->whereNull('revoked_at')->first();
    }

    public function create(array $attributes): PlatformAdministrator
    {
        return PlatformAdministrator::query()->create($attributes);
    }
}
