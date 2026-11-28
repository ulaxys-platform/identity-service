<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Database;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ulaxys\Identity\Models\User;
use Ulaxys\Identity\Tests\Support\DestructiveDatabaseGuard;
use Ulaxys\Identity\Tests\TestCase;

final class ProviderProvisioningTest extends TestCase
{
    public function test_retries_reconcile_a_disabled_account_without_replacing_the_platform_user(): void
    {
        DestructiveDatabaseGuard::assertSafe($this->app, DB::connection());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config([
            'keycloak.base_url' => 'https://provider.example.test',
            'keycloak.realm' => 'identity',
            'keycloak.issuer' => 'https://provider.example.test/realms/identity',
            'keycloak.admin.client_id' => 'provisioner',
            'keycloak.admin.client_secret' => 'fixture',
        ]);

        Http::preventStrayRequests();
        $account = null;
        $creates = 0;
        Http::fake(function (Request $request) use (&$account, &$creates) {
            if (str_ends_with($request->url(), '/token')) {
                return Http::response(['access_token' => 'fixture-token']);
            }
            if ($request->method() === 'POST') {
                $creates++;
                $this->assertFalse($request['enabled']);
                $this->assertArrayNotHasKey('credentials', $request->data());
                $account = [...$request->data(), 'id' => 'provider-subject'];
                throw new ConnectionException('fixture response loss');
            }

            return Http::response($account === null ? [] : [$account]);
        });

        $requestId = (string) Str::ulid();
        $this->artisan('identity:provider-stage', ['request' => $requestId])->assertFailed();
        $mapping = DB::table('identity_provider_mappings')->where('request_id', $requestId)->first();
        $this->assertSame('retry_required', $mapping->state);
        $this->assertNull($mapping->subject);
        $this->assertFalse(User::query()->findOrFail($mapping->user_id)->isLoginEligible());

        $this->artisan('identity:provider-stage', ['request' => $requestId])
            ->expectsOutput($mapping->user_id)->assertSuccessful();
        $this->artisan('identity:provider-stage', ['request' => $requestId])
            ->expectsOutput($mapping->user_id)->assertSuccessful();

        $this->assertSame(1, $creates);
        $this->assertDatabaseCount('identity_users', 1);
        $this->assertDatabaseHas('identity_provider_mappings', [
            'request_id' => $requestId,
            'user_id' => $mapping->user_id,
            'subject' => 'provider-subject',
            'state' => 'staged_disabled',
        ]);
        $this->assertDatabaseHas('identity_audit_events', [
            'action' => 'identity.provider.provision.staged',
            'subject_id' => $mapping->user_id,
            'correlation_id' => $requestId,
        ]);
    }

    public function test_provider_lifecycle_requires_confirmed_activation_and_reconciles_disablement(): void
    {
        DestructiveDatabaseGuard::assertSafe($this->app, DB::connection());
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        config([
            'keycloak.base_url' => 'https://provider.example.test',
            'keycloak.realm' => 'identity',
            'keycloak.issuer' => 'https://provider.example.test/realms/identity',
            'keycloak.admin.client_id' => 'provisioner',
            'keycloak.admin.client_secret' => 'fixture',
        ]);

        $user = User::query()->create(['status' => User::STATUS_INVITED, 'type' => User::TYPE_HUMAN]);
        DB::table('identity_provider_mappings')->insert([
            'request_id' => (string) Str::ulid(), 'user_id' => $user->id,
            'issuer' => config('keycloak.issuer'), 'contact_email' => 'fixture@example.test',
            'state' => 'staged_disabled', 'subject' => 'provider-subject',
        ]);
        $verified = false;
        $disableAvailable = false;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($user, &$verified, &$disableAvailable) {
            if (str_ends_with($request->url(), '/token')) {
                return Http::response(['access_token' => 'fixture-admin-token']);
            }
            if ($request->method() === 'GET') {
                return Http::response([
                    'id' => 'provider-subject', 'username' => 'identity-'.strtolower($user->id),
                    'email' => 'fixture@example.test', 'emailVerified' => $verified,
                    'attributes' => ['identity_platform_ulid' => [$user->id]],
                    'enabled' => true, 'requiredActions' => $verified ? [] : ['VERIFY_EMAIL'],
                ]);
            }
            if (str_contains($request->url(), '/execute-actions-email')) {
                $this->assertStringContainsString('lifespan=900', $request->url());

                return Http::response(null, 204);
            }

            return Http::response(null, $disableAvailable ? 204 : 503);
        });
        $parameters = ['user' => $user->id];
        $this->artisan('identity:provider-account', $parameters + ['operation' => 'invite'])->assertSuccessful();
        $this->artisan('identity:provider-account', $parameters + ['operation' => 'bootstrap'])->assertFailed();
        $this->assertDatabaseCount('identity_platform_administrators', 0);
        $this->assertFalse($user->fresh()->isLoginEligible());

        $verified = true;
        $this->artisan('identity:provider-account', $parameters + ['operation' => 'bootstrap'])->assertSuccessful();
        $this->artisan('identity:provider-account', $parameters + ['operation' => 'bootstrap'])->assertSuccessful();
        $this->assertDatabaseCount('identity_platform_administrators', 1);
        $this->assertTrue($user->fresh()->isLoginEligible());

        $this->artisan('identity:provider-account', $parameters + ['operation' => 'disable'])->assertFailed();
        $this->assertFalse($user->fresh()->isLoginEligible());
        $this->assertDatabaseHas('identity_provider_mappings', ['user_id' => $user->id, 'state' => 'disable_pending']);
        $this->artisan('identity:provider-account', $parameters + ['operation' => 'activate'])->assertFailed();
        $disableAvailable = true;
        $this->artisan('identity:provider-reconcile')->assertSuccessful();
        $this->assertDatabaseHas('identity_provider_mappings', ['user_id' => $user->id, 'state' => 'disabled']);
    }
}
