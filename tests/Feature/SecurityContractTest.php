<?php

namespace Ulaxys\Identity\Tests\Feature;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Ulaxys\Identity\Http\Middleware\VerifyServiceSignature;
use Ulaxys\Identity\Integration\IntegrationException;
use Ulaxys\Identity\Integration\ObservedRouteCollection;
use Ulaxys\Identity\Integration\SecurityContract;
use Ulaxys\Identity\Tests\Fixtures\Integration\SecurityActorMiddleware;
use Ulaxys\Identity\Tests\TestCase;

final class SecurityContractTest extends TestCase
{
    private function chain(): array
    {
        return [['kind' => 'actor', 'middleware' => SecurityActorMiddleware::class]];
    }

    public function test_explicit_actor_contract_matches_route_and_requires_signature(): void
    {
        $chain = $this->chain();
        $this->assertSame($chain, SecurityContract::validate($chain));
        SecurityContract::matchRoute($chain, ['api', 'service.signature', SecurityActorMiddleware::class], [VerifyServiceSignature::class, SecurityActorMiddleware::class], '/actor');
        $this->expectException(IntegrationException::class);
        SecurityContract::matchRoute($chain, ['api', SecurityActorMiddleware::class], [SecurityActorMiddleware::class], '/actor');
    }

    public function test_unclassified_middleware_is_rejected_even_with_empty_chain(): void
    {
        $this->expectException(IntegrationException::class);
        SecurityContract::matchRoute([], ['api', 'service.signature', SecurityActorMiddleware::class], [VerifyServiceSignature::class, SecurityActorMiddleware::class], '/actor');
    }

    public function test_wrong_semantics_and_alias_are_rejected(): void
    {
        $this->expectException(IntegrationException::class);
        SecurityContract::validate([['kind' => 'authorization', 'middleware' => SecurityActorMiddleware::class]]);
    }

    public function test_resolved_class_mismatch_is_rejected(): void
    {
        $this->expectException(IntegrationException::class);
        SecurityContract::matchRoute($this->chain(), ['api', 'service.signature', SecurityActorMiddleware::class], [VerifyServiceSignature::class, ThrottleRequests::class], '/actor');
    }

    public function test_named_rate_limit_must_exist_and_match_resolved_parameters(): void
    {
        app(RateLimiter::class)->for('security-fixture', fn () => Limit::perMinute(1));
        $chain = [['kind' => 'rateLimit', 'middleware' => 'throttle:security-fixture']];
        SecurityContract::matchRoute($chain, ['api', 'service.signature', 'throttle:security-fixture'], [VerifyServiceSignature::class, ThrottleRequests::class.':security-fixture'], '/actor');
        $this->expectException(IntegrationException::class);
        SecurityContract::matchRoute($chain, ['api', 'service.signature', 'throttle:security-fixture'], [VerifyServiceSignature::class, ThrottleRequests::class.':other'], '/actor');
    }

    public function test_openapi_requires_signature_and_bearer_not_or(): void
    {
        $schemes = ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']];
        SecurityContract::verifyOpenApi($this->chain(), true, ['security' => [['serviceSignature' => [], 'bearerAuth' => []]]], $schemes);
        $this->expectException(IntegrationException::class);
        SecurityContract::verifyOpenApi($this->chain(), true, ['security' => [['serviceSignature' => []], ['bearerAuth' => []]]], $schemes);
    }

    public function test_strict_commands_accept_review_only_contract_and_reject_native_output(): void
    {
        $collection = new ObservedRouteCollection;
        foreach ($this->app['router']->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $collection->add($route);
        }
        $this->app['router']->setRoutes($collection);
        Route::middleware(['api', 'service.signature', SecurityActorMiddleware::class])
            ->get('/security-fixture/actor', fn () => null)->name('security.actor');
        $config = config('integration');
        $config['schemaVersion'] = 2;
        $config['modules'] = [[
            'id' => 'security-fixture',
            'permissions' => null,
            'operations' => [
                'security.actor' => ['operation' => 'actor', 'expose' => true, 'exempt' => 'Authenticated actor self context.', 'securityMiddleware' => $this->chain()],
            ],
        ]];
        config(['integration' => $config]);

        $options = ['--strict' => true, '--dry-run' => true, '--output' => 'security-fixture-review'];
        $this->artisan('ulaxys:generate-integration', [...$options, '--all' => true, '--gateway-format' => 'json'])->assertExitCode(0);
        $this->artisan('ulaxys:generate-deploy', $options)->assertExitCode(0);
        $this->artisan('ulaxys:generate-integration', [...$options, '--all' => true])->assertExitCode(1);

        $config['modules'][0]['operations']['security.actor']['expose'] = false;
        $config['modules'][0]['operations']['security.actor']['securityMiddleware'] = [];
        config(['integration' => $config]);
        $this->artisan('ulaxys:generate-deploy', $options)->assertExitCode(1);
    }
}
