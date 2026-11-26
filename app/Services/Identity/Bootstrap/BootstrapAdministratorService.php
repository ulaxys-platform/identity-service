<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Services\Identity\Bootstrap;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Ulaxys\Identity\Models\CredentialChallenge;
use Ulaxys\Identity\Models\LoginIdentifier;
use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Repositories\RepositoryInterface\CredentialChallengeRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\LoginIdentifierRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\PlatformAdministratorRepositoryInterface;
use Ulaxys\Identity\Repositories\RepositoryInterface\UserRepositoryInterface;
use Ulaxys\Identity\Services\Identity\AuditLogger;

/**
 * Secure bootstrap of the first platform administrator (ADR-0004):
 * idempotent, auditable, no seeded passwords, no undocumented bypass.
 * The one-time setup token is returned exactly once to the operator.
 */
final class BootstrapAdministratorService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly LoginIdentifierRepositoryInterface $identifiers,
        private readonly PlatformAdministratorRepositoryInterface $administrators,
        private readonly CredentialChallengeRepositoryInterface $challenges,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{created: bool, user_id: string, token: ?string, expires_at: \Illuminate\Support\Carbon|null} */
    public function bootstrap(string $identifier, string $displayName, int $ttlMinutes): array
    {
        return DB::transaction(function () use ($identifier, $displayName, $ttlMinutes): array {
            $this->administrators->lockBootstrap();
            $kind = LoginIdentifier::kindFor($identifier);
            $normalized = LoginIdentifier::normalize($identifier);
            $existingIdentifier = $this->identifiers->findByKindAndNormalized($kind, $normalized);
            if ($existingIdentifier !== null) {
                $existingGrant = $this->administrators->findActiveByUserId((string) $existingIdentifier->user_id);
                if ($existingGrant !== null) {
                    // Idempotent re-run: report, change nothing, audit once.
                    $this->audit->audit('identity.bootstrap.admin_bootstrap_idempotent', null, 'operator', 'user', (string) $existingIdentifier->user_id, 'success');

                    return ['created' => false, 'user_id' => (string) $existingIdentifier->user_id, 'token' => null, 'expires_at' => null];
                }
                throw new RuntimeException('This login identifier already belongs to a user without a platform administrator grant; bootstrap cannot silently escalate it.');
            }
            if ($this->administrators->hasAnyGrant()) {
                throw new RuntimeException('Initial platform administrator already bootstrapped; use the approved administrator lifecycle.');
            }
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $expiresAt = now()->addMinutes(max(15, $ttlMinutes));
            $user = $this->users->createWithProfile(
                ['status' => User::STATUS_INVITED, 'type' => User::TYPE_HUMAN, 'created_by' => null],
                ['display_name' => $displayName, 'preferred_language' => 'en', 'preferred_timezone' => 'UTC'],
            );
            $this->identifiers->createForUser((string) $user->id, $kind, $identifier, true);
            $this->administrators->create([
                'user_id' => (string) $user->id,
                'granted_by' => null,
                'grant_reason' => 'Initial platform administrator bootstrap (identity:bootstrap-admin)',
                'granted_at' => now(),
            ]);
            $this->challenges->create([
                'user_id' => (string) $user->id,
                'purpose' => CredentialChallenge::PURPOSE_ACCOUNT_SETUP,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
            ]);
            $this->audit->audit('identity.bootstrap.admin_created', null, 'operator', 'user', (string) $user->id, 'success', ['purpose' => 'account_setup']);
            $this->audit->security('auth.bootstrap.admin_created', (string) $user->id, null, 'success', []);

            return ['created' => true, 'user_id' => (string) $user->id, 'token' => $token, 'expires_at' => $expiresAt];
        });
    }
}
