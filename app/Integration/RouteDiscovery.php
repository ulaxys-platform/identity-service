<?php

namespace Ulaxys\Identity\Integration;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Ulaxys\Identity\Http\Controllers\Documentation\OpenApiController;
use Ulaxys\Identity\Http\Controllers\HealthCheckController;
use Ulaxys\Identity\Http\Middleware\Language;
use Ulaxys\Identity\Http\Middleware\ValidateJsonBody;
use Ulaxys\Identity\Http\Middleware\VerifyServiceSignature;

final class RouteDiscovery
{
    // Reviewed single-segment expressions, including Laravel 13's built-in helpers.
    // Arbitrary PCRE cannot be proven slash-free with a few sample matches.
    private const SINGLE_SEGMENT_CONSTRAINTS = [
        '[^/]+', '[0-9]+', '[1-9][0-9]*', '[1-9][0-9]{0,18}', '\\d+',
        '[a-z]+', '[A-Z]+', '[a-zA-Z]+', '[A-Za-z]+',
        '[a-zA-Z0-9]+', '[A-Za-z0-9]+', '[a-z0-9-]+',
        '[a-zA-Z0-9_-]+', '[A-Za-z0-9_-]+',
        '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}',
        '[\\da-fA-F]{8}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{4}-[\\da-fA-F]{12}',
    ];

    public function collect(Router $router, array $config = []): array
    {
        // Apply Laravel 13 HTTP middleware aliases/groups without handling a request.
        app()->make(Kernel::class);
        if (app()->routesAreCached()) {
            throw new IntegrationException('Clear route cache with php artisan route:clear before generation; registration history is required.');
        }
        $collection = $router->getRoutes();
        if (! $collection instanceof ObservedRouteCollection) {
            throw new IntegrationException('Registration history unavailable. Run the ulaxys generator in a fresh Artisan process.');
        }
        $operations = $excluded = $seen = $names = [];
        foreach ($collection->registrations() as $route) {
            $methods = array_values(array_diff($route->methods(), in_array('GET', $route->methods(), true) ? ['HEAD'] : []));
            $uri = '/'.ltrim($route->uri(), '/');
            if ($route->getDomain() !== null) {
                throw new IntegrationException('Domain-scoped routes are unsupported; configure service-relative routes.');
            }
            foreach ($methods as $method) {
                $key = $method.' '.$uri;
                if (isset($seen[$key])) {
                    throw new IntegrationException('Duplicate/conflicting registered method/path: '.$key);
                }
                $seen[$key] = true;
            }
            $middleware = array_values(array_unique($route->gatherMiddleware()));
            if ($route->excludedMiddleware() !== []) {
                throw new IntegrationException('Excluded middleware is unsupported: '.$uri);
            }
            $resolvedMiddleware = $router->gatherRouteMiddleware($route);
            $security = SecurityContract::forRoute($config, $route->getName());
            if ($security !== null) {
                SecurityContract::matchRoute($security, $middleware, $resolvedMiddleware, $uri);
            } elseif (array_diff($resolvedMiddleware, [
                SubstituteBindings::class,
                VerifyServiceSignature::class,
                ValidateJsonBody::class,
                Language::class,
            ])) {
                throw new IntegrationException('Unsupported resolved middleware on '.$uri);
            }
            $requiresSignature = in_array(VerifyServiceSignature::class, $resolvedMiddleware, true);
            if (in_array('service.signature', $middleware, true) && ! $requiresSignature) {
                throw new IntegrationException('Signature alias does not resolve to the established VerifyServiceSignature middleware: '.$uri);
            }
            if ($this->infrastructure($route, $middleware) && ($route->uri() !== 'openapi.json' || $requiresSignature)) {
                $excluded[] = ['methods' => $methods, 'uri' => $uri, 'reason' => 'Private health/documentation infrastructure; never gateway business routes.'];

                continue;
            }
            if (array_diff($methods, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE'])) {
                throw new IntegrationException('Unsupported HTTP method on '.$uri);
            }
            if (! in_array('api', $middleware, true) || ($security === null && array_diff($middleware, ['api', 'service.signature', 'json.body', 'lang']))) {
                throw new IntegrationException('Unsupported API boundary/middleware on '.$uri.'; no middleware semantics are inferred.');
            }
            $name = $route->getName();
            if (! is_string($name) || $name === '' || isset($names[$name])) {
                throw new IntegrationException('Business routes need unique explicit names: '.$uri);
            }
            $names[$name] = true;
            if ($route->isFallback || $route->defaults !== []) {
                throw new IntegrationException('Fallback routes and route defaults are unsupported: '.$name);
            }
            $optionalSeen = false;
            foreach (array_filter(explode('/', trim($uri, '/')), fn ($segment) => $segment !== '') as $segment) {
                if ($optionalSeen) {
                    throw new IntegrationException('Only trailing optional parameters are supported: '.$name);
                }
                if (preg_match('/^\{[A-Za-z_][A-Za-z0-9_]*(\?)?\}$/D', $segment)) {
                    $optionalSeen = str_ends_with($segment, '?}');
                } elseif (! preg_match('#^[A-Za-z0-9._~-]+$#D', $segment) || in_array($segment, ['.', '..'], true)) {
                    throw new IntegrationException('Unsupported URI segment on '.$name.'; use literal segments and whole named parameters.');
                }
            }
            if (str_contains($uri, '//')) {
                throw new IntegrationException('Empty URI segments are unsupported: '.$name);
            }
            foreach ($route->wheres as $constraint) {
                if (! in_array($constraint, self::SINGLE_SEGMENT_CONSTRAINTS, true)) {
                    throw new IntegrationException('Unsupported parameter constraint/catch-all on '.$name.'; use a reviewed single-segment constraint from RouteDiscovery.');
                }
            }
            $parameters = [];
            foreach ($route->parameterNames() as $parameter) {
                if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $parameter)) {
                    throw new IntegrationException('Unsupported parameter identifier on '.$name);
                }
                $parameters[] = ['name' => $parameter, 'optional' => str_contains($uri, '{'.$parameter.'?}'), 'constraint' => $route->wheres[$parameter] ?? null];
            }
            foreach ($operations as $previous) {
                if (array_intersect($previous['methods'], $methods) && $this->mayShadow($previous['uri'], $uri)) {
                    throw new IntegrationException('Route shadowing/ambiguous templates: '.$previous['uri'].' precedes '.$uri.'; register specific paths first or separate ambiguous templates.');
                }
            }
            $operations[] = ['methods' => $methods, 'uri' => $uri, 'name' => $name,
                'action' => $route->getActionName(), 'middleware' => $middleware, 'resolvedMiddleware' => $resolvedMiddleware, 'requiresSignature' => $requiresSignature, 'parameters' => $parameters, ...($security === null ? [] : ['securityMiddleware' => $security])];
        }

        return ['routes' => $operations, 'excluded' => $excluded];
    }

    private function infrastructure(Route $route, array $middleware): bool
    {
        if ($route->methods() !== ['GET', 'HEAD']) {
            return false;
        }
        $identities = [
            'healthCheck/apiStatus' => ['healthCheck/apiStatus', HealthCheckController::class.'@getHealthCheck'],
            'healthCheck/postgresStatus' => ['healthCheck/postgresStatus', HealthCheckController::class.'@getPostgresCheck'],
            'openapi.json' => ['openapi', OpenApiController::class],
        ];
        $identity = $identities[$route->uri()] ?? null;

        return $identity !== null && $identity === [$route->getName(), $route->getAction('controller')]
            && in_array('api', $middleware, true)
            && ($route->uri() !== 'openapi.json' || in_array('service.signature', $middleware, true));
    }

    public function mayShadow(string $earlier, string $later): bool
    {
        $left = explode('/', trim($earlier, '/'));
        $right = explode('/', trim($later, '/'));
        // Conservative for constrained/optional parameters: ambiguous candidates fail closed.
        $minLeft = count(array_filter($left, fn ($part) => ! str_ends_with($part, '?}')));
        $minRight = count(array_filter($right, fn ($part) => ! str_ends_with($part, '?}')));
        if (count($left) < $minRight || count($right) < $minLeft) {
            return false;
        }
        $dynamicEarlier = false;
        foreach (array_slice($left, 0, min(count($left), count($right))) as $index => $part) {
            $dynamic = str_starts_with($part, '{');
            $otherDynamic = str_starts_with($right[$index], '{');
            if (! $dynamic && ! $otherDynamic && $part !== $right[$index]) {
                return false;
            }
            if (! $dynamic && $otherDynamic && ! $dynamicEarlier) {
                return false;
            } // Specific route before parameter route is intentional.
            $dynamicEarlier = $dynamicEarlier || $dynamic;
        }

        return $dynamicEarlier || count($left) > count($right);
    }
}
