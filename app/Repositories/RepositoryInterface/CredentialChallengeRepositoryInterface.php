<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\RepositoryInterface;

use Ulaxys\Identity\Models\CredentialChallenge;

interface CredentialChallengeRepositoryInterface
{
    public function create(array $attributes): CredentialChallenge;

    public function findByTokenHash(string $tokenHash): ?CredentialChallenge;

    public function consume(string $id): bool;
}
