<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Database;

use Illuminate\Support\Facades\Hash;
use Ulaxys\Identity\Models\CredentialChallenge;
use Ulaxys\Identity\Models\LoginIdentifier;
use Ulaxys\Identity\Services\Identity\Bootstrap\BootstrapAdministratorService;
use Ulaxys\Identity\Tests\Support\Identity\CapturingDelivery;
use Ulaxys\Identity\Tests\Support\Identity\InteractsWithIdentityDatabase;
use Ulaxys\Identity\Tests\TestCase;

final class IdentityCredentialLifecycleTest extends TestCase
{
    use InteractsWithIdentityDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIdentityDatabase();
    }

    private function bearer(string $token): array
    {
        return ['Signature' => $this->signature(), 'Authorization' => 'Bearer '.$token];
    }

    public function test_password_change_requires_current_password_and_revokes_other_sessions(): void
    {
        $user = $this->createIdentityUser('change@ulaxys.test', 'Test-Passphrase-123');
        $current = $this->loginJson('change@ulaxys.test', 'Test-Passphrase-123')->assertOk();
        $other = $this->loginJson('change@ulaxys.test', 'Test-Passphrase-123')->assertOk();
        $currentAccess = (string) $current->json('tokens.accessToken');
        $otherAccess = (string) $other->json('tokens.accessToken');

        $wrong = $this->withHeaders($this->bearer($currentAccess))
            ->postJson('/api/v1/credentials/password', ['currentPassword' => 'wrong-password-99', 'newPassword' => 's3ntence-Staple-Calm-9']);
        $wrong->assertStatus(400);

        $changed = $this->withHeaders($this->bearer($currentAccess))
            ->postJson('/api/v1/credentials/password', ['currentPassword' => 'Test-Passphrase-123', 'newPassword' => 's3ntence-Staple-Calm-9']);
        $changed->assertOk();

        $this->withHeaders($this->bearer($otherAccess))->getJson('/api/v1/profile/me')->assertStatus(401);
        $this->withHeaders($this->bearer($currentAccess))->getJson('/api/v1/profile/me')->assertOk();
        $this->loginJson('change@ulaxys.test', 'Test-Passphrase-123')->assertStatus(401);
        $this->loginJson('change@ulaxys.test', 's3ntence-Staple-Calm-9')->assertOk();
    }

    public function test_password_policy_rejects_weak_and_identifier_derived_passwords(): void
    {
        $user = $this->createIdentityUser('policy@ulaxys.test', 'Test-Passphrase-123');
        $login = $this->loginJson('policy@ulaxys.test', 'Test-Passphrase-123')->assertOk();
        $access = (string) $login->json('tokens.accessToken');

        $this->withHeaders($this->bearer($access))
            ->postJson('/api/v1/credentials/password', ['currentPassword' => 'Test-Passphrase-123', 'newPassword' => 'short'])
            ->assertStatus(400)
            ->assertJsonPath('code', 400);
        $this->withHeaders($this->bearer($access))
            ->postJson('/api/v1/credentials/password', ['currentPassword' => 'Test-Passphrase-123', 'newPassword' => 'policy@ulaxys.test-99'])
            ->assertStatus(400);
    }

    public function test_forgot_password_is_enumeration_safe(): void
    {
        $this->createIdentityUser('forgot@ulaxys.test', 'Test-Passphrase-123');
        $existing = $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/credentials/forgot-password', ['identifier' => 'forgot@ulaxys.test']);
        $missing = $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/credentials/forgot-password', ['identifier' => 'ghost@ulaxys.test']);
        $existing->assertOk();
        $this->assertSame($existing->json(), $missing->json(), 'Recovery responses must not reveal account existence.');
        $this->assertSame(1, CredentialChallenge::query()->where('purpose', 'password_reset')->count());
    }

    public function test_reset_password_is_single_use_and_revokes_all_sessions(): void
    {
        $delivery = new CapturingDelivery;
        $this->app->instance(\Ulaxys\Identity\Services\Identity\Delivery\TokenDeliveryInterface::class, $delivery);
        $this->createIdentityUser('reset@ulaxys.test', 'Test-Passphrase-123');
        $first = $this->loginJson('reset@ulaxys.test', 'Test-Passphrase-123')->assertOk();

        $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/credentials/forgot-password', ['identifier' => 'reset@ulaxys.test'])
            ->assertOk();
        $token = (string) $delivery->lastToken('password_reset');
        $this->assertNotSame('', $token);

        $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/credentials/reset-password', ['token' => $token, 'newPassword' => 's3ntence-Staple-Calm-9'])
            ->assertOk();
        $this->withHeaders($this->bearer((string) $first->json('tokens.accessToken')))->getJson('/api/v1/profile/me')->assertStatus(401);
        $this->loginJson('reset@ulaxys.test', 'Test-Passphrase-123')->assertStatus(401);
        $this->loginJson('reset@ulaxys.test', 's3ntence-Staple-Calm-9')->assertOk();

        $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/credentials/reset-password', ['token' => $token, 'newPassword' => 'another-Str0ng-Pass'])
            ->assertStatus(400);
    }

    public function test_expired_reset_tokens_are_rejected(): void
    {
        $user = $this->createIdentityUser('expired@ulaxys.test', 'Test-Passphrase-123');
        $token = $this->createChallenge((string) $user->id, 'password_reset', now()->subMinutes(5));
        $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/credentials/reset-password', ['token' => $token, 'newPassword' => 's3ntence-Staple-Calm-9'])
            ->assertStatus(400);
    }

    public function test_bootstrap_is_idempotent_seeds_no_password_and_setup_activates_the_account(): void
    {
        /** @var BootstrapAdministratorService $service */
        $service = $this->app->make(BootstrapAdministratorService::class);
        $first = $service->bootstrap('ops@ulaxys.test', 'Platform Operator', 1440);
        $this->assertTrue($first['created']);
        $this->assertNotNull($first['token']);
        $second = $service->bootstrap('ops@ulaxys.test', 'Platform Operator', 1440);
        $this->assertFalse($second['created']);
        $this->assertNull($second['token']);
        // No seeded passwords: setup is the only activation path.
        $this->loginJson('ops@ulaxys.test', 'anything-at-all-1')->assertStatus(401);
        $this->artisan('identity:bootstrap-admin', ['identifier' => 'ops@ulaxys.test'])->assertSuccessful();
        $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/auth/setup', ['token' => (string) $first['token'], 'newPassword' => 's3ntence-Staple-Calm-9'])
            ->assertStatus(201);
        $this->loginJson('ops@ulaxys.test', 's3ntence-Staple-Calm-9')->assertOk();
        $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/auth/setup', ['token' => (string) $first['token'], 'newPassword' => 'another-Str0ng-Pass'])
            ->assertStatus(400);
    }

    public function test_email_verification_start_and_confirm(): void
    {
        $delivery = new CapturingDelivery;
        $this->app->instance(\Ulaxys\Identity\Services\Identity\Delivery\TokenDeliveryInterface::class, $delivery);
        $user = $this->createIdentityUser('verify@ulaxys.test', 'Test-Passphrase-123');
        $login = $this->loginJson('verify@ulaxys.test', 'Test-Passphrase-123')->assertOk();
        $access = (string) $login->json('tokens.accessToken');
        $this->assertNull(LoginIdentifier::query()->where('user_id', $user->id)->first()->verified_at);
        $this->withHeaders($this->bearer($access))
            ->postJson('/api/v1/credentials/email-verification/start')
            ->assertOk();
        $token = (string) $delivery->lastToken('email_verification');
        $this->assertNotSame('', $token);
        $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/credentials/email-verification/confirm', ['token' => $token])
            ->assertOk();
        $this->assertNotNull(LoginIdentifier::query()->where('user_id', $user->id)->first()->verified_at);
        $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/credentials/email-verification/confirm', ['token' => $token])
            ->assertStatus(400);
    }

    public function test_second_distinct_bootstrap_identifier_is_rejected(): void
    {
        $service = $this->app->make(BootstrapAdministratorService::class);
        $service->bootstrap('first-admin@ulaxys.test', 'First Admin', 1440);
        $this->expectException(\RuntimeException::class);
        $service->bootstrap('second-admin@ulaxys.test', 'Second Admin', 1440);
    }

    public function test_credential_repository_consumption_requires_a_fresh_unexpired_row(): void
    {
        $user = $this->createIdentityUser('consume@ulaxys.test', 'Test-Passphrase-123');
        $repo = $this->app->make(\Ulaxys\Identity\Repositories\RepositoryInterface\CredentialChallengeRepositoryInterface::class);
        $token = $this->createChallenge((string) $user->id, 'password_reset');
        $record = $repo->findByTokenHash(hash('sha256', $token));
        $this->assertNotNull($record);
        $this->assertTrue($repo->consume((string) $record->id));
        $this->assertFalse($repo->consume((string) $record->id));
        $expired = $this->createChallenge((string) $user->id, 'password_reset', now()->subMinute());
        $expiredRecord = $repo->findByTokenHash(hash('sha256', $expired));
        $this->assertNotNull($expiredRecord);
        $this->assertFalse($repo->consume((string) $expiredRecord->id));
    }
}
