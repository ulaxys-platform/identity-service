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
}
