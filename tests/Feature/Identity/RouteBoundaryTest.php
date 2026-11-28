<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Feature\Identity;

use Illuminate\Support\Facades\Artisan;
use Ulaxys\Identity\Http\Middleware\Identity\RequirePlatformAdministrator;
use Ulaxys\Identity\Http\Middleware\Identity\VerifyActorToken;
use Ulaxys\Identity\Tests\TestCase;

final class RouteBoundaryTest extends TestCase
{
    private const RETIRED_URIS = [
        'api/v1/auth/login',
        'api/v1/auth/refresh',
        'api/v1/auth/setup',
        'api/v1/credentials/forgot-password',
        'api/v1/credentials/reset-password',
        'api/v1/credentials/email-verification/confirm',
        'api/v1/credentials/email-verification/start',
        'api/v1/credentials/password',
        'api/v1/oauth/jwks',
        'api/v1/oauth/introspect',
        'api/v1/oauth/revoke',
    ];

    public function test_all_identity_routes_sit_behind_the_service_signature(): void
    {
        foreach ($this->identityRoutes() as $route) {
            $this->assertContains('service.signature', $route['middleware'], 'Missing service signature boundary: '.$route['uri']);
        }
    }

    public function test_every_identity_route_requires_a_verified_actor(): void
    {
        foreach ($this->identityRoutes() as $route) {
            $this->assertContains(VerifyActorToken::class, $route['middleware'], 'Missing actor token boundary: '.$route['uri']);
        }
    }

    public function test_admin_routes_require_the_interim_platform_administrator_gate(): void
    {
        foreach ($this->identityRoutes() as $route) {
            if (! str_starts_with($route['uri'], 'api/v1/admin/users')) {
                continue;
            }
            $this->assertContains(RequirePlatformAdministrator::class, $route['middleware'], 'Missing administrator gate: '.$route['uri']);
        }
    }

    public function test_local_authentication_routes_and_authority_commands_are_unavailable(): void
    {
        $routes = array_column($this->identityRoutes(), 'uri');
        foreach (self::RETIRED_URIS as $path) {
            $this->assertNotContains($path, $routes);
        }
        foreach (['identity:bootstrap-admin', 'identity:keys:generate'] as $command) {
            $this->assertArrayNotHasKey($command, Artisan::all());
        }
    }

    public function test_identity_route_names_are_unique_and_slash_separated(): void
    {
        $names = array_map(fn (array $route) => (string) $route['name'], $this->identityRoutes());
        $this->assertSame($names, array_values(array_unique($names)), 'Route names must be unique.');
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^identity\/[A-Za-z0-9\/_-]+$/', $name);
        }
    }

    /** @return list<array<string, mixed>> */
    private function identityRoutes(): array
    {
        return array_values(array_filter(
            $this->runtimeRoutes(),
            fn (array $route) => str_starts_with((string) $route['uri'], 'api/v1/'),
        ));
    }
}
