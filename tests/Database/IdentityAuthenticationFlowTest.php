<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Database;

use Ulaxys\Identity\Models\AuditEvent;
use Ulaxys\Identity\Models\AuthSession;
use Ulaxys\Identity\Models\SecurityEvent;
use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Tests\Support\Identity\InteractsWithIdentityDatabase;
use Ulaxys\Identity\Tests\TestCase;

final class IdentityAuthenticationFlowTest extends TestCase
{
    use InteractsWithIdentityDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIdentityDatabase();
    }

    public function test_successful_login_issues_tokens_session_and_audit(): void
    {
        $this->createIdentityUser('login@ulaxys.test', 'Test-Passphrase-123');
        $response = $this->loginJson('login@ulaxys.test', 'Test-Passphrase-123');
        $response->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('tokens.tokenType', 'Bearer')
            ->assertJsonPath('actor.actorType', 'user')
            ->assertJsonPath('actor.authenticationMethods.0', 'password');
        $this->assertNotSame('', $response->json('tokens.accessToken'));
        $this->assertNotSame('', $response->json('tokens.refreshToken'));
        $this->assertSame(1, AuthSession::query()->count());
        $this->assertGreaterThanOrEqual(1, AuditEvent::query()->where('action', 'identity.auth.login')->count());
        $this->assertGreaterThanOrEqual(1, SecurityEvent::query()->where('event_type', 'auth.login.success')->count());
    }

    public function test_unknown_identifier_wrong_password_and_inactive_accounts_share_identical_denials(): void
    {
        $this->createIdentityUser('active@ulaxys.test', 'Test-Passphrase-123');
        $this->createIdentityUser('suspended@ulaxys.test', 'Test-Passphrase-123', User::STATUS_SUSPENDED);
        $unknown = $this->loginJson('ghost@ulaxys.test', 'Test-Passphrase-123');
        $wrongPassword = $this->loginJson('active@ulaxys.test', 'wrong-password-99');
        $suspended = $this->loginJson('suspended@ulaxys.test', 'Test-Passphrase-123');
        foreach ([$wrongPassword, $suspended] as $response) {
            $response->assertStatus(401);
            $this->assertSame($unknown->json(), $response->json(), 'Denial bodies must be identical to prevent enumeration.');
        }
        $this->assertSame(0, AuthSession::query()->count());
    }

    public function test_login_throttling_blocks_repeated_failures(): void
    {
        config(['identity.throttle.login_max_attempts' => 2, 'identity.throttle.login_decay_seconds' => 60]);
        $this->createIdentityUser('throttle@ulaxys.test', 'Test-Passphrase-123');
        $this->loginJson('throttle@ulaxys.test', 'wrong-password-1')->assertStatus(401);
        $this->loginJson('throttle@ulaxys.test', 'wrong-password-2')->assertStatus(401);
        $this->loginJson('throttle@ulaxys.test', 'wrong-password-3')->assertStatus(429);
    }

    public function test_refresh_rotation_and_reuse_detection(): void
    {
        $user = $this->createIdentityUser('rotate@ulaxys.test', 'Test-Passphrase-123');
        $login = $this->loginJson('rotate@ulaxys.test', 'Test-Passphrase-123')->assertOk();
        $firstAccess = (string) $login->json('tokens.accessToken');
        $firstRefresh = (string) $login->json('tokens.refreshToken');

        $rotated = $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/auth/refresh', ['refreshToken' => $firstRefresh]);
        $rotated->assertOk();
        $secondAccess = (string) $rotated->json('tokens.accessToken');
        $this->assertNotSame($firstAccess, $secondAccess);

        // Replaying the consumed token revokes the family and the session.
        $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/auth/refresh', ['refreshToken' => $firstRefresh])
            ->assertStatus(401);
        $this->withHeader('Signature', $this->signature())
            ->withHeader('Authorization', 'Bearer '.$secondAccess)
            ->getJson('/api/v1/profile/me')
            ->assertStatus(401, 'Rotated-then-replayed family must revoke the session.');
        $this->assertGreaterThanOrEqual(1, SecurityEvent::query()->where('event_type', 'auth.token.refresh.failure')->count());
    }

    public function test_logout_revokes_the_session_immediately(): void
    {
        $user = $this->createIdentityUser('logout@ulaxys.test', 'Test-Passphrase-123');
        $login = $this->loginJson('logout@ulaxys.test', 'Test-Passphrase-123')->assertOk();
        $access = (string) $login->json('tokens.accessToken');
        $this->withHeader('Signature', $this->signature())
            ->withHeader('Authorization', 'Bearer '.$access)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();
        $this->withHeader('Signature', $this->signature())
            ->withHeader('Authorization', 'Bearer '.$access)
            ->getJson('/api/v1/profile/me')
            ->assertStatus(401);
    }

    public function test_missing_garbled_and_invalid_bearer_tokens_are_denied_with_www_authenticate(): void
    {
        $this->withHeader('Signature', $this->signature())
            ->getJson('/api/v1/profile/me')
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
        $this->withHeader('Signature', $this->signature())
            ->withHeader('Authorization', 'Bearer not-a-jwt')
            ->getJson('/api/v1/profile/me')
            ->assertStatus(401);
    }
}
