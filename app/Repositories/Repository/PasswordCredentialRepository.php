<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\Repository;

use Ulaxys\Identity\Models\PasswordCredential;
use Ulaxys\Identity\Repositories\RepositoryInterface\PasswordCredentialRepositoryInterface;

final class PasswordCredentialRepository implements PasswordCredentialRepositoryInterface
{
    public function findByUserId(string $userId): ?PasswordCredential
    {
        return PasswordCredential::query()->where('user_id', $userId)->first();
    }

    public function createForUser(string $userId, string $passwordHash): PasswordCredential
    {
        return PasswordCredential::query()->create([
            'user_id' => $userId,
            'password_hash' => $passwordHash,
            'algorithm' => (string) config('hashing.driver', 'argon2id'),
        ]);
    }

    public function updateHash(string $userId, string $passwordHash): void
    {
        $credential = PasswordCredential::query()->where('user_id', $userId)->firstOrFail();
        $credential->forceFill([
            'password_hash' => $passwordHash,
            'algorithm' => (string) config('hashing.driver', 'argon2id'),
            'version' => $credential->version + 1,
            'must_change' => false,
            'last_changed_at' => now(),
        ])->save();
    }
}
