<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\RepositoryInterface;

use Ulaxys\Identity\Models\AuthSession;

interface AuthSessionRepositoryInterface
{
    public function create(array $attributes): AuthSession;

    public function findById(string $id): ?AuthSession;

    /** Only call inside a transaction. */
    public function lockById(string $id): ?AuthSession;

    public function touch(string $id): void;

    public function revoke(string $id, string $reason): void;

    /** @return int number of revoked rows */
    public function revokeAllForUser(string $userId, ?string $exceptSessionId, string $reason): int;

    /** @return list<AuthSession> */
    public function usableSessionsForUser(string $userId): array;
}
