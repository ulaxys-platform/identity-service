<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\RepositoryInterface;

use Ulaxys\Identity\Models\PlatformAdministrator;

interface PlatformAdministratorRepositoryInterface
{
    public function findActiveByUserId(string $userId): ?PlatformAdministrator;

    /** Acquire the exclusive bootstrap writer lock inside a PostgreSQL transaction. */
    public function lockBootstrap(): void;

    public function hasAnyGrant(): bool;

    public function create(array $attributes): PlatformAdministrator;
}
