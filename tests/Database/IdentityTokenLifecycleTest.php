<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Database;

use Ulaxys\Identity\Services\Identity\Tokens\JwtCodec;
use Ulaxys\Identity\Tests\Support\Identity\InteractsWithIdentityDatabase;
use Ulaxys\Identity\Tests\TestCase;

final class IdentityTokenLifecycleTest extends TestCase
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

    public function test_jwks_publishes_public_keys_with_thumbprint_kids(): void
    {
        $response = $this->withHeader('Signature', $this->signature())->getJson('/api/v1/oauth/jwks');
        $response->assertOk();
        $keys = $response->json('items.keys');
        $this->assertNotEmpty($keys);
        $this->assertSame('RSA', $keys[0]['kty']);
        $this->assertSame('sig', $keys[0]['use']);
        $this->assertArrayNotHasKey('d', $keys[0], 'Private material must never be published.');
    }

    public function test_introspection_reflects_token_and_session_state(): void
    {
        $user = $this->createIdentityUser('introspect@ulaxys.test', 'Test-Passphrase-123');
        $login = $this->loginJson('introspect@ulaxys.test', 'Test-Passphrase-123')->assertOk();
        $access = (string) $login->json('tokens.accessToken');
        $introspect = fn (string $token) => $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/oauth/introspect', ['token' => $token]);
        $introspect($access)->assertOk()
            ->assertJsonPath('items.active', true)
            ->assertJsonPath('items.sub', (string) $user->id)
            ->assertJsonPath('items.tokenType', 'access');
        $introspect('garbage-token')->assertOk()->assertJsonPath('items.active', false);
        $this->withHeader('Signature', $this->signature())
            ->withHeader('Authorization', 'Bearer '.$access)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();
        $introspect($access)->assertOk()->assertJsonPath('items.active', false);
    }

    public function test_revoke_endpoint_revokes_the_session_and_its_family(): void
    {
        $this->createIdentityUser('revoke@ulaxys.test', 'Test-Passphrase-123');
        $login = $this->loginJson('revoke@ulaxys.test', 'Test-Passphrase-123')->assertOk();
        $access = (string) $login->json('tokens.accessToken');
        $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/oauth/revoke', ['token' => $access])
            ->assertOk();
        $this->withHeader('Signature', $this->signature())
            ->withHeader('Authorization', 'Bearer '.$access)
            ->getJson('/api/v1/profile/me')
            ->assertStatus(401);
    }

    public function test_wrong_audience_and_expired_tokens_are_rejected(): void
    {
        $codec = new JwtCodec((string) config('identity.issuer'), (int) config('identity.token.clock_skew_seconds', 30));
        $keys = $this->identityKeys;
        $signingKey = $keys->signingKey();
        $now = time();
        $base = ['iss' => (string) config('identity.issuer'), 'sub' => '01UNKNOWN000000000000000', 'sid' => '01UNKNOWN000000000000000'];
        $wrongAudience = $codec->encode($base + ['aud' => 'other-platform', 'iat' => $now, 'nbf' => $now, 'exp' => $now + 600, 'jti' => 'x', 'amr' => ['password']], $signingKey['private_key_pem'], $signingKey['kid']);
        $expired = $codec->encode($base + ['aud' => (string) config('identity.token.audience'), 'iat' => $now - 3600, 'nbf' => $now - 3600, 'exp' => $now - 1800, 'jti' => 'y', 'amr' => ['password']], $signingKey['private_key_pem'], $signingKey['kid']);
        $this->withHeaders($this->bearer($wrongAudience))->getJson('/api/v1/profile/me')->assertStatus(401);
        $this->withHeaders($this->bearer($expired))->getJson('/api/v1/profile/me')->assertStatus(401);
    }

    public function test_key_rotation_keeps_old_tokens_valid_within_the_overlap_window(): void
    {
        $user = $this->createIdentityUser('rotate-keys@ulaxys.test', 'Test-Passphrase-123');
        $login = $this->loginJson('rotate-keys@ulaxys.test', 'Test-Passphrase-123')->assertOk();
        $oldAccess = (string) $login->json('tokens.accessToken');
        $this->identityKeys->generate(true);
        $this->withHeaders($this->bearer($oldAccess))->getJson('/api/v1/profile/me')->assertOk();
        config(['identity.keys.retired_publication_days' => 0]);
        $this->withHeaders($this->bearer($oldAccess))->getJson('/api/v1/profile/me')->assertStatus(401);
        $this->loginJson('rotate-keys@ulaxys.test', 'Test-Passphrase-123')->assertOk();
    }

    public function test_introspection_denies_wrong_audience_and_ineligible_user(): void
    {
        $user = $this->createIdentityUser('introspection-deny@ulaxys.test', 'Test-Passphrase-123');
        $login = $this->loginJson('introspection-deny@ulaxys.test', 'Test-Passphrase-123')->assertOk();
        $access = (string) $login->json('tokens.accessToken');
        $key = $this->identityKeys->signingKey();
        $codec = new JwtCodec((string) config('identity.issuer'));
        $claims = $codec->decode($access, [$key['kid'] => $key['public_key_pem']], (string) config('identity.token.audience'));
        $wrongAudience = $codec->encode(array_replace($claims, ['aud' => 'other-platform']), $key['private_key_pem'], $key['kid']);
        $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/oauth/introspect', ['token' => $wrongAudience])
            ->assertOk()->assertJsonPath('items.active', false);
        $user->update(['status' => \Ulaxys\Identity\Models\User::STATUS_SUSPENDED]);
        $this->withHeader('Signature', $this->signature())
            ->postJson('/api/v1/oauth/introspect', ['token' => $access])
            ->assertOk()->assertJsonPath('items.active', false);
    }
}
