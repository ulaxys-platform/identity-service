<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\RepositoryInterface;

use Ulaxys\Identity\Models\PasswordCredential;

interface PasswordCredentialRepositoryInterface
{
    public function findByUserId(string $userId): ?PasswordCredential;

    public function createForUser(string $userId, string $passwordHash): PasswordCredential;

    /** Bumps the version, records last_changed_at and clears must_change. */
    public function updateHash(string $userId, string $passwordHash): void;
}
