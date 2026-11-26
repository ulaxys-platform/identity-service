<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\Repository;

use Ulaxys\Identity\Models\CredentialChallenge;
use Ulaxys\Identity\Repositories\RepositoryInterface\CredentialChallengeRepositoryInterface;

final class CredentialChallengeRepository implements CredentialChallengeRepositoryInterface
{
    public function create(array $attributes): CredentialChallenge
    {
        return CredentialChallenge::query()->create($attributes);
    }

    public function findByTokenHash(string $tokenHash): ?CredentialChallenge
    {
        return CredentialChallenge::query()->where('token_hash', $tokenHash)->first();
    }

    public function consume(string $id): void
    {
        CredentialChallenge::query()->whereKey($id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
    }
}
