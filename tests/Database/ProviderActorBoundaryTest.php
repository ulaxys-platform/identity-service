<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Database;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Tests\Support\DestructiveDatabaseGuard;
use Ulaxys\Identity\Tests\TestCase;

final class ProviderActorBoundaryTest extends TestCase
{
    public function test_verified_access_token_requires_an_active_platform_mapping(): void
    {
        DestructiveDatabaseGuard::assertSafe($this->app, DB::connection());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $issuer = 'https://keycloak.example.test/realms/identity';
        $sid = (string) Str::uuid();
        config([
            'keycloak.base_url' => 'https://keycloak.example.test',
            'keycloak.realm' => 'identity',
            'keycloak.issuer' => $issuer,
            'keycloak.access.audience' => 'identity-api',
            'keycloak.access.introspection_client_id' => 'identity-introspection',
            'keycloak.access.introspection_client_secret' => 'fixture',
            'services.internal.signature' => 'fixture-signature',
        ]);
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $rsa = openssl_pkey_get_details($pair)['rsa'];

        $active = true;
        $unavailable = false;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($issuer, $rsa, $sid, &$active, &$unavailable) {
            return match ($request->url()) {
                $issuer.'/.well-known/openid-configuration' => Http::response([
                    'issuer' => $issuer,
                    'jwks_uri' => $issuer.'/protocol/openid-connect/certs',
                    'introspection_endpoint' => $issuer.'/protocol/openid-connect/token/introspect',
                ]),
                $issuer.'/protocol/openid-connect/certs' => Http::response(['keys' => [[
                    'kid' => 'fixture', 'use' => 'sig', 'alg' => 'RS256', 'kty' => 'RSA',
                    'n' => JWT::urlsafeB64Encode($rsa['n']), 'e' => JWT::urlsafeB64Encode($rsa['e']),
                ]]]),
                $issuer.'/protocol/openid-connect/token/introspect' => Http::response(
                    ['active' => $active, 'iss' => $issuer, 'sub' => 'provider-subject', 'sid' => $sid],
                    $unavailable ? 503 : 200,
                ),
                default => $this->fail('Unexpected provider endpoint.'),
            };
        });
        $user = User::query()->create(['status' => User::STATUS_ACTIVE, 'type' => User::TYPE_HUMAN]);
        $claims = [
            'iss' => $issuer, 'aud' => ['identity-api'], 'typ' => 'Bearer', 'sub' => 'provider-subject',
            'sid' => $sid, 'iat' => time(), 'exp' => time() + 60, 'amr' => ['pwd'],
        ];
        $request = fn (array $overrides = [], $signer = null) => $this->getJson('/api/v1/actor/context', [
            'Signature' => 'fixture-signature',
            'Authorization' => 'Bearer '.JWT::encode(array_replace($claims, $overrides), $signer ?? $pair, 'RS256', 'fixture'),
            'X-Actor-Id' => 'forged-header',
        ]);

        $request()->assertUnauthorized();
        DB::table('identity_provider_mappings')->insert([
            'request_id' => (string) Str::ulid(), 'user_id' => $user->id, 'issuer' => $issuer,
            'subject' => 'provider-subject', 'state' => 'active',
        ]);
        $request()->assertOk()->assertJsonPath('items.actorId', $user->id)
            ->assertJsonPath('items.providerIssuer', $issuer)->assertJsonPath('items.providerSubject', 'provider-subject');
        $request(['typ' => 'ID'])->assertUnauthorized();
        $request(['aud' => 'other'])->assertUnauthorized();
        $request(['iss' => 'https://untrusted.example.test'])->assertUnauthorized();
        $request(['exp' => time() - 1])->assertUnauthorized();
        $request(['nbf' => time() + 60])->assertUnauthorized();
        $request([], openssl_pkey_new(['private_key_bits' => 2048]))->assertUnauthorized();
        $active = false;
        $request()->assertUnauthorized();
        $unavailable = true;
        $request()->assertStatus(503);
        $active = true;
        $unavailable = false;
        $user->update(['status' => User::STATUS_SUSPENDED]);
        $request()->assertUnauthorized();
    }
}
