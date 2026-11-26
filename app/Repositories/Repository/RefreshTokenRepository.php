<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\Repository;

use Ulaxys\Identity\Models\RefreshToken;
use Ulaxys\Identity\Repositories\RepositoryInterface\RefreshTokenRepositoryInterface;

final class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function create(array $attributes): RefreshToken
    {
        return RefreshToken::query()->create($attributes);
    }

    public function findByTokenHash(string $tokenHash): ?RefreshToken
    {
        return RefreshToken::query()->where('token_hash', $tokenHash)->first();
    }

    public function consume(string $id): void
    {
        RefreshToken::query()->whereKey($id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
    }

    public function revokeFamily(string $familyId, string $reason): int
    {
        return RefreshToken::query()
            ->where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    public function revokeForUser(string $userId, ?string $exceptSessionId, string $reason): int
    {
        $query = RefreshToken::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at');
        if ($exceptSessionId !== null) {
            $query->where('session_id', '!=', $exceptSessionId);
        }

        return $query->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    public function revokeForSession(string $sessionId, string $reason): int
    {
        return RefreshToken::query()
            ->where('session_id', $sessionId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }
}
