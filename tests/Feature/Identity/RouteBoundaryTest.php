<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Feature\Identity;

use Ulaxys\Identity\Http\Middleware\Identity\RequirePlatformAdministrator;
use Ulaxys\Identity\Http\Middleware\Identity\VerifyActorToken;
use Ulaxys\Identity\Tests\TestCase;

final class RouteBoundaryTest extends TestCase
{
    private const PRE_AUTH_URIS = [
        'api/v1/auth/login',
        'api/v1/auth/refresh',
        'api/v1/auth/setup',
        'api/v1/credentials/forgot-password',
        'api/v1/credentials/reset-password',
        'api/v1/credentials/email-verification/confirm',
        'api/v1/oauth/jwks',
        'api/v1/oauth/introspect',
        'api/v1/oauth/revoke',
    ];

    private const ADMIN_URIS = [
        'api/v1/admin/users',
        'api/v1/admin/users/create',
        'api/v1/admin/users/{userId}',
    ];

    public function test_all_identity_routes_sit_behind_the_service_signature(): void
    {
        foreach ($this->identityRoutes() as $route) {
            $this->assertContains('service.signature', $route['middleware'], 'Missing service signature boundary: '.$route['uri']);
        }
    }

    public function test_every_route_outside_the_pre_auth_set_requires_a_verified_actor(): void
    {
        foreach ($this->identityRoutes() as $route) {
            if (in_array($route['uri'], self::PRE_AUTH_URIS, true)) {
                continue;
            }
            $this->assertContains(VerifyActorToken::class, $route['middleware'], 'Missing actor token boundary: '.$route['uri']);
        }
    }

    public function test_admin_routes_require_the_interim_platform_administrator_gate(): void
    {
        foreach ($this->identityRoutes() as $route) {
            if (! in_array($route['uri'], self::ADMIN_URIS, true)) {
                continue;
            }
            $this->assertContains(RequirePlatformAdministrator::class, $route['middleware'], 'Missing administrator gate: '.$route['uri']);
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
