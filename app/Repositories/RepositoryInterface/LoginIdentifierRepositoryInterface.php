<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\RepositoryInterface;

use Illuminate\Support\Carbon;
use Ulaxys\Identity\Models\IdentifierVerification;
use Ulaxys\Identity\Models\LoginIdentifier;

interface LoginIdentifierRepositoryInterface
{
    public function findByKindAndNormalized(string $kind, string $normalized): ?LoginIdentifier;

    public function createForUser(string $userId, string $kind, string $identifier, bool $verified): LoginIdentifier;

    /** @return list<LoginIdentifier> */
    public function identifiersForUser(string $userId): array;

    public function createVerification(string $identifierId, string $tokenHash, Carbon $expiresAt): IdentifierVerification;

    public function findUsableVerification(string $tokenHash): ?IdentifierVerification;

    public function consumeVerification(string $verificationId): void;

    public function markVerified(string $identifierId): void;
}
