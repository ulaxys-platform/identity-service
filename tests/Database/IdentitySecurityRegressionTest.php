<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Database;

use Ulaxys\Identity\Models\AuditEvent;
use Ulaxys\Identity\Models\SecurityEvent;
use Ulaxys\Identity\Services\Identity\Bootstrap\BootstrapAdministratorService;
use Ulaxys\Identity\Tests\Support\Identity\InteractsWithIdentityDatabase;
use Ulaxys\Identity\Tests\TestCase;

final class IdentitySecurityRegressionTest extends TestCase
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

    public function test_unknown_request_fields_are_rejected_before_any_state_change(): void
    {
        $this->loginJson('nobody@ulaxys.test', 'Test-Passphrase-123', ['isAdmin' => true])->assertStatus(400);
        $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/credentials/forgot-password', ['identifier' => 'nobody@ulaxys.test', 'status' => 'active'])
            ->assertStatus(400);
    }

    public function test_caller_supplied_actor_headers_are_never_trusted(): void
    {
        $this->createIdentityUser('spoof@ulaxys.test', 'Test-Passphrase-123');
        $this->withHeader('Signature', $this->signature())
            ->withHeaders(['X-Actor-User-Id' => '01SPOOFEDACTOR0000000000', 'X-User-Id' => '01SPOOFEDACTOR0000000000', 'X-Actor-Type' => 'user'])
            ->getJson('/api/v1/profile/me')
            ->assertStatus(401);
    }

    public function test_session_revocation_is_owner_scoped_idor_safe(): void
    {
        $this->createIdentityUser('owner@ulaxys.test', 'Test-Passphrase-123');
        $this->createIdentityUser('attacker@ulaxys.test', 'Test-Passphrase-123');
        $owner = $this->loginJson('owner@ulaxys.test', 'Test-Passphrase-123')->assertOk();
        $attacker = $this->loginJson('attacker@ulaxys.test', 'Test-Passphrase-123')->assertOk();
        $ownerSession = (string) $owner->json('actor.sessionId');
        $this->withHeaders($this->bearer((string) $attacker->json('tokens.accessToken')))
            ->deleteJson('/api/v1/sessions/'.$ownerSession)
            ->assertStatus(404);
        $this->withHeaders($this->bearer((string) $owner->json('tokens.accessToken')))
            ->getJson('/api/v1/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'items');
    }

    public function test_user_administration_requires_the_bootstrap_grant(): void
    {
        $this->createIdentityUser('plain@ulaxys.test', 'Test-Passphrase-123');
        /** @var BootstrapAdministratorService $service */
        $service = $this->app->make(BootstrapAdministratorService::class);
        $bootstrapped = $service->bootstrap('admin@ulaxys.test', 'Platform Admin', 1440);
        $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/auth/setup', ['token' => (string) $bootstrapped['token'], 'newPassword' => 's3ntence-Staple-Calm-9'])
            ->assertStatus(201);

        $plain = $this->loginJson('plain@ulaxys.test', 'Test-Passphrase-123')->assertOk();
        $this->withHeaders($this->bearer((string) $plain->json('tokens.accessToken')))
            ->getJson('/api/v1/admin/users')
            ->assertStatus(403);

        $admin = $this->loginJson('admin@ulaxys.test', 's3ntence-Staple-Calm-9')->assertOk();
        $this->withHeaders($this->bearer((string) $admin->json('tokens.accessToken')))
            ->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);
        $this->withHeaders($this->bearer((string) $admin->json('tokens.accessToken')))
            ->postJson('/api/v1/admin/users', ['identifier' => 'invited@ulaxys.test', 'kind' => 'email', 'displayName' => 'Invited User'])
            ->assertStatus(201)
            ->assertJsonPath('items.status', 'invited');
        $this->withHeaders($this->bearer((string) $admin->json('tokens.accessToken')))
            ->postJson('/api/v1/admin/users', ['identifier' => 'invited@ulaxys.test', 'kind' => 'email', 'displayName' => 'Duplicate'])
            ->assertStatus(409);
    }

    public function test_actor_context_contract_shape(): void
    {
        $this->createIdentityUser('context@ulaxys.test', 'Test-Passphrase-123');
        $login = $this->loginJson('context@ulaxys.test', 'Test-Passphrase-123')->assertOk();
        $this->withHeaders($this->bearer((string) $login->json('tokens.accessToken')))
            ->getJson('/api/v1/actor/context')
            ->assertOk()
            ->assertJsonPath('items.actorType', 'user')
            ->assertJsonPath('items.sessionId', (string) $login->json('actor.sessionId'))
            ->assertJsonPath('items.authenticationMethods.0', 'password')
            ->assertJsonPath('items.authorizationVersion', 1);
    }

    public function test_audit_and_security_events_never_store_plaintext_secrets(): void
    {
        $password = 'SuperSecret-Password-777';
        $this->createIdentityUser('audit@ulaxys.test', $password);
        $this->loginJson('audit@ulaxys.test', 'wrong-password-attempt')->assertStatus(401);
        $this->loginJson('audit@ulaxys.test', $password)->assertOk();
        $dumped = json_encode([
            'audit' => AuditEvent::query()->get()->map->toArray()->all(),
            'security' => SecurityEvent::query()->get()->map->toArray()->all(),
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($password, $dumped, 'Passwords must never reach audit storage.');
        $this->assertStringNotContainsString('wrong-password-attempt', $dumped, 'Attempted passwords must never reach audit storage.');
    }

    public function test_identity_routes_enforce_the_service_signature(): void
    {
        $this->getJson('/api/v1/sessions')->assertStatus(403);
        $this->postJson('/api/v1/auth/login', ['identifier' => 'x@y.test', 'password' => 'whatever-1'])->assertStatus(403);
        $this->getJson('/api/v1/oauth/jwks')->assertStatus(403);
    }

    public function test_forbidden_access_never_uses_406(): void
    {
        $this->withHeader('Signature', $this->signature())->getJson('/api/v1/admin/users')->assertStatus(401);
        $this->assertNotSame(406, $this->withHeader('Signature', $this->signature())->getJson('/api/v1/admin/users')->getStatusCode());
    }
}
