<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\Repository;

use Ulaxys\Identity\Models\AuthSession;
use Ulaxys\Identity\Repositories\RepositoryInterface\AuthSessionRepositoryInterface;

final class AuthSessionRepository implements AuthSessionRepositoryInterface
{
    public function create(array $attributes): AuthSession
    {
        return AuthSession::query()->create($attributes);
    }

    public function findById(string $id): ?AuthSession
    {
        return AuthSession::query()->find($id);
    }

    public function lockById(string $id): ?AuthSession
    {
        return AuthSession::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function touch(string $id): void
    {
        AuthSession::query()->whereKey($id)->update(['last_seen_at' => now()]);
    }

    public function revoke(string $id, string $reason): void
    {
        AuthSession::query()
            ->whereKey($id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    public function revokeAllForUser(string $userId, ?string $exceptSessionId, string $reason): int
    {
        $query = AuthSession::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at');
        if ($exceptSessionId !== null) {
            $query->whereKeyNot($exceptSessionId);
        }

        return $query->update(['revoked_at' => now(), 'revoked_reason' => $reason]);
    }

    public function usableSessionsForUser(string $userId): array
    {
        return AuthSession::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }
}
