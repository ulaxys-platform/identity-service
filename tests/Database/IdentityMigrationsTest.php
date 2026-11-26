<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Ulaxys\Identity\Models\CredentialChallenge;
use Ulaxys\Identity\Models\LoginIdentifier;
use Ulaxys\Identity\Models\PasswordCredential;
use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Models\UserProfile;
use Ulaxys\Identity\Tests\Support\Identity\InteractsWithIdentityDatabase;
use Ulaxys\Identity\Tests\TestCase;

final class IdentityMigrationsTest extends TestCase
{
    use InteractsWithIdentityDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIdentityDatabase();
    }

    public function test_all_identity_tables_and_core_indexes_exist(): void
    {
        foreach ([
            'identity_users', 'identity_user_profiles', 'identity_login_identifiers',
            'identity_password_credentials', 'identity_identifier_verifications',
            'identity_credential_challenges', 'identity_auth_sessions',
            'identity_refresh_tokens', 'identity_platform_administrators',
            'identity_signing_keys', 'identity_audit_events', 'identity_security_events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), 'Missing table '.$table);
        }
    }

    public function test_login_identifier_uniqueness_is_enforced_per_kind_and_normalized(): void
    {
        $this->createIdentityUser('dupe@ulaxys.test', null);
        $second = User::query()->create(['status' => User::STATUS_ACTIVE, 'type' => User::TYPE_HUMAN]);
        try {
            LoginIdentifier::query()->create([
                'user_id' => $second->id,
                'kind' => LoginIdentifier::KIND_EMAIL,
                'identifier' => 'dupe@ulaxys.test',
                'normalized' => 'dupe@ulaxys.test',
            ]);
            $this->fail('Duplicate normalized identifier must violate the unique constraint.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', (string) $exception->getCode());
        }
    }

    public function test_one_credential_row_per_user_is_enforced(): void
    {
        $user = $this->createIdentityUser('cred@ulaxys.test', 'first-password-123');
        try {
            PasswordCredential::query()->create(['user_id' => $user->id, 'password_hash' => 'x']);
            $this->fail('Second credential row must violate the unique constraint.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', (string) $exception->getCode());
        }
    }

    public function test_challenge_token_hash_uniqueness_is_enforced(): void
    {
        $user = $this->createIdentityUser('chal@ulaxys.test', null);
        $hash = str_repeat('a', 64);
        CredentialChallenge::query()->create(['user_id' => $user->id, 'purpose' => 'password_reset', 'token_hash' => $hash, 'expires_at' => now()->addHour()]);
        try {
            CredentialChallenge::query()->create(['user_id' => $user->id, 'purpose' => 'password_reset', 'token_hash' => $hash, 'expires_at' => now()->addHour()]);
            $this->fail('Duplicate token hash must violate the unique constraint.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', (string) $exception->getCode());
        }
    }

    public function test_profile_is_unique_per_user_and_cascades_on_user_delete(): void
    {
        $user = $this->createIdentityUser('cascade@ulaxys.test', null);
        try {
            UserProfile::query()->create(['user_id' => $user->id, 'display_name' => 'second']);
            $this->fail('Second profile must violate the unique constraint.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', (string) $exception->getCode());
        }
        $user->delete();
        $this->assertFalse(Schema::hasTable('identity_login_identifiers') && LoginIdentifier::query()->where('user_id', $user->id)->exists());
    }
}
