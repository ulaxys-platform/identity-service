<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\RepositoryInterface;

use Ulaxys\Identity\Models\RefreshToken;

interface RefreshTokenRepositoryInterface
{
    public function create(array $attributes): RefreshToken;

    public function findByTokenHash(string $tokenHash): ?RefreshToken;

    public function consume(string $id): bool;

    /** @return int number of revoked rows in the family */
    public function revokeFamily(string $familyId, string $reason): int;

    /** Revokes every family of the user, optionally keeping one session. */
    public function revokeForUser(string $userId, ?string $exceptSessionId, string $reason): int;

    public function revokeForSession(string $sessionId, string $reason): int;
}
