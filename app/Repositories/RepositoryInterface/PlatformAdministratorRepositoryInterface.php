<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\RepositoryInterface;

use Ulaxys\Identity\Models\PlatformAdministrator;

interface PlatformAdministratorRepositoryInterface
{
    public function findActiveByUserId(string $userId): ?PlatformAdministrator;

    public function create(array $attributes): PlatformAdministrator;
}
