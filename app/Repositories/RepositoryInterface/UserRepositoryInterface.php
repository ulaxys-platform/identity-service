<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\RepositoryInterface;

use Ulaxys\Identity\Models\User;

interface UserRepositoryInterface
{
    public function findById(string $id): ?User;

    /** @return array{0: list<User>, 1: int} */
    public function page(int $limit, int $offset, ?string $status): array;

    public function createWithProfile(array $userAttributes, array $profileAttributes): User;

    public function setStatus(string $userId, string $status, ?string $actorId): void;

    public function updateProfile(string $userId, array $profileAttributes): void;
}
