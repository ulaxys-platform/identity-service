<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Support\Identity;

use Illuminate\Support\Carbon;
use Ulaxys\Identity\Models\CredentialChallenge;
use Ulaxys\Identity\Models\LoginIdentifier;
use Ulaxys\Identity\Models\PasswordCredential;
use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Models\UserProfile;
use Ulaxys\Identity\Services\Identity\Tokens\SigningKeyService;
use Ulaxys\Identity\Tests\Support\DestructiveDatabaseGuard;
use Ulaxys\Identity\Tests\TestCase;

/**
 * Shared setup for destructive Identity database tests. Enforces the template
 * safety guard, runs migrations only against the disposable database, and
 * generates disposable signing keys for the test process.
 */
trait InteractsWithIdentityDatabase
{
    protected SigningKeyService $identityKeys;

    protected function setUpIdentityDatabase(): void
    {
        if (getenv('SERVICE_ALLOW_DESTRUCTIVE_TESTS') !== 'true') {
            $this->markTestSkipped('Requires explicit disposable PostgreSQL opt-in.');
        }
        DestructiveDatabaseGuard::assertSafe($this->app, $this->app['db']->connection());
        $this->artisan('migrate:fresh', ['--force' => true]);
        config(['services.internal.signature' => 'test-identity-signature']);
        config(['identity.keys.storage_path' => storage_path('framework/testing/identity-keys-'.bin2hex(random_bytes(4)))]);
        $this->identityKeys = $this->app->make(SigningKeyService::class);
        $this->identityKeys->generate(false);
    }

    protected function signature(): string
    {
        return 'test-identity-signature';
    }

    protected function createIdentityUser(string $identifier, ?string $password, string $status = User::STATUS_ACTIVE, string $kind = LoginIdentifier::KIND_EMAIL, string $displayName = 'Test User'): User
    {
        $user = User::query()->create(['status' => $status, 'type' => User::TYPE_HUMAN]);
        UserProfile::query()->create(['user_id' => $user->id, 'display_name' => $displayName]);
        LoginIdentifier::query()->create([
            'user_id' => $user->id,
            'kind' => $kind,
            'identifier' => $identifier,
            'normalized' => LoginIdentifier::normalize($identifier),
            'verified_at' => null,
        ]);
        if ($password !== null) {
            $driver = strtoupper((string) config('hashing.driver', 'argon2id'));
            PasswordCredential::query()->create([
                'user_id' => $user->id,
                'password_hash' => (string) password_hash($password, defined('PASSWORD_'.$driver) ? constant('PASSWORD_'.$driver) : PASSWORD_DEFAULT),
                'algorithm' => (string) config('hashing.driver', 'argon2id'),
            ]);
        }

        return $user->refresh();
    }

    protected function loginJson(string $identifier, string $password, array $extra = [])
    {
        return $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/auth/login', ['identifier' => $identifier, 'password' => $password] + $extra);
    }

    protected function createChallenge(string $userId, string $purpose, ?Carbon $expiresAt = null): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        CredentialChallenge::query()->create([
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt ?? now()->addHour(),
        ]);

        return $token;
    }
}
