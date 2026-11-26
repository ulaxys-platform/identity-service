<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Repositories\Repository;

use Illuminate\Support\Carbon;
use Ulaxys\Identity\Models\IdentifierVerification;
use Ulaxys\Identity\Models\LoginIdentifier;
use Ulaxys\Identity\Repositories\RepositoryInterface\LoginIdentifierRepositoryInterface;

final class LoginIdentifierRepository implements LoginIdentifierRepositoryInterface
{
    public function findByKindAndNormalized(string $kind, string $normalized): ?LoginIdentifier
    {
        return LoginIdentifier::query()
            ->where('kind', $kind)
            ->where('normalized', $normalized)
            ->first();
    }

    public function createForUser(string $userId, string $kind, string $identifier, bool $verified): LoginIdentifier
    {
        return LoginIdentifier::query()->create([
            'user_id' => $userId,
            'kind' => $kind,
            'identifier' => $identifier,
            'normalized' => LoginIdentifier::normalize($identifier),
            'verified_at' => $verified ? now() : null,
        ]);
    }

    public function identifiersForUser(string $userId): array
    {
        return LoginIdentifier::query()->where('user_id', $userId)->orderBy('id')->get()->all();
    }

    public function createVerification(string $identifierId, string $tokenHash, Carbon $expiresAt): IdentifierVerification
    {
        return IdentifierVerification::query()->create([
            'login_identifier_id' => $identifierId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);
    }

    public function findUsableVerification(string $tokenHash): ?IdentifierVerification
    {
        $verification = IdentifierVerification::query()->where('token_hash', $tokenHash)->first();
        if ($verification === null || ! $verification->isUsable()) {
            return null;
        }

        return $verification;
    }

    public function consumeVerification(string $verificationId): void
    {
        IdentifierVerification::query()->whereKey($verificationId)->update(['consumed_at' => now()]);
    }

    public function markVerified(string $identifierId): void
    {
        LoginIdentifier::query()->whereKey($identifierId)->update(['verified_at' => now()]);
    }
}
