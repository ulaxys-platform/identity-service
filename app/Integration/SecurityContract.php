<?php

namespace Ulaxys\Identity\Integration;

use Illuminate\Cache\RateLimiter;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use ReflectionClass;
use Ulaxys\Identity\Http\Middleware\Language;
use Ulaxys\Identity\Http\Middleware\ValidateJsonBody;
use Ulaxys\Identity\Http\Middleware\VerifyServiceSignature;

final class SecurityContract
{
    private const BASE = [
        SubstituteBindings::class,
        VerifyServiceSignature::class,
        ValidateJsonBody::class,
        Language::class,
    ];

    /** Validate explicit per-operation additional middleware, in execution order. */
    public static function validate(mixed $chain): array
    {
        if (! is_array($chain) || ! array_is_list($chain)) {
            throw new IntegrationException('securityMiddleware must be an ordered list.');
        }
        $seen = [];
        $actor = false;
        foreach ($chain as $entry) {
            if (! is_array($entry)) {
                throw new IntegrationException('Invalid security middleware entry.');
            }
            IntegrationMetadata::keys($entry, ['kind', 'middleware']);
            $kind = $entry['kind'];
            $middleware = $entry['middleware'];
            if (! is_string($middleware) || ! is_string($kind) || isset($seen[$middleware])) {
                throw new IntegrationException('Invalid or duplicate security middleware.');
            }
            $seen[$middleware] = true;
            if ($kind === 'rateLimit') {
                if (! preg_match('/^throttle:[A-Za-z][A-Za-z0-9_-]{0,79}$/D', $middleware)) {
                    throw new IntegrationException('Rate limit must name a bounded Laravel limiter.');
                }

                continue;
            }
            if (! in_array($kind, ['actor', 'authorization', 'assurance'], true)
                || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/D', $middleware)
                || ! class_exists($middleware)) {
                throw new IntegrationException('Unsupported security middleware contract.');
            }
            $reflection = new ReflectionClass($middleware);
            $attributes = $reflection->getAttributes(SecurityMiddleware::class);
            if ($reflection->getName() !== $middleware || ! $reflection->isInstantiable()
                || ! $reflection->hasMethod('handle') || count($attributes) !== 1
                || $attributes[0]->getArguments() !== [$kind] || in_array($middleware, self::BASE, true)) {
                throw new IntegrationException('Security middleware lacks the exact source declaration.');
            }
            if ($kind === 'actor') {
                if ($actor) {
                    throw new IntegrationException('Multiple actor authentication mechanisms are unsupported.');
                }
                $actor = true;
            } elseif (! $actor) {
                throw new IntegrationException('Authorization and assurance must follow actor authentication.');
            }
        }

        return $chain;
    }

    public static function forRoute(array $config, ?string $name): ?array
    {
        if (($config['schemaVersion'] ?? 1) !== 2) {
            return null;
        }
        foreach ($config['modules'] as $module) {
            if (isset($module['operations'][$name])) {
                return self::validate($module['operations'][$name]['securityMiddleware']);
            }
        }

        return null;
    }

    public static function matchRoute(array $chain, array $declared, array $resolved, string $uri): void
    {
        self::validate($chain);
        if (in_array('service.signature', $declared, true) !== in_array(VerifyServiceSignature::class, $resolved, true)
            || in_array('lang', $declared, true) !== in_array(Language::class, $resolved, true)) {
            throw new IntegrationException('Base security alias resolution mismatch on '.$uri);
        }
        $extra = array_values(array_diff($declared, ['api', 'service.signature', 'json.body', 'lang']));
        $expected = array_column($chain, 'middleware');
        if ($extra !== $expected) {
            throw new IntegrationException('Declared security middleware mismatch on '.$uri);
        }
        $effective = array_values(array_diff($resolved, self::BASE));
        if (count($effective) !== count($chain)) {
            throw new IntegrationException('Resolved security middleware mismatch on '.$uri);
        }
        if ($chain !== [] && ! in_array(VerifyServiceSignature::class, $resolved, true)) {
            throw new IntegrationException('Security-aware routes require the established service Signature: '.$uri);
        }
        foreach ($chain as $index => $entry) {
            $middleware = $entry['middleware'];
            if ($entry['kind'] === 'rateLimit') {
                $name = substr($middleware, strlen('throttle:'));
                if (! in_array($effective[$index], [ThrottleRequests::class.':'.$name, ThrottleRequestsWithRedis::class.':'.$name], true)
                    || app(RateLimiter::class)->limiter($name) === null) {
                    throw new IntegrationException('Unknown or mismatched named rate limiter on '.$uri);
                }
            } elseif ($effective[$index] !== $middleware) {
                throw new IntegrationException('Resolved security middleware order/class mismatch on '.$uri);
            }
        }
    }

    /** P4.5 supports conjunctive Signature and optional actor bearer requirements only. */
    public static function verifyOpenApi(array $chain, bool $signature, array $operation, array $schemes): void
    {
        $expected = $signature ? ['serviceSignature'] : [];
        if (in_array('actor', array_column($chain, 'kind'), true)) {
            $expected[] = 'bearerAuth';
            $bearer = $schemes['bearerAuth'] ?? [];
            if (($bearer['type'] ?? null) !== 'http' || strtolower($bearer['scheme'] ?? '') !== 'bearer') {
                throw new IntegrationException('Missing or invalid bearerAuth security scheme.');
            }
        }
        sort($expected);
        $security = $operation['security'] ?? [];
        if ($expected === [] && ($security === [] || $security === [[]])) {
            return;
        }
        if (! is_array($security) || count($security) !== 1 || ! isset($security[0]) || ! is_array($security[0])) {
            throw new IntegrationException('Expected one conjunctive OpenAPI security requirement.');
        }
        $actual = array_keys($security[0]);
        sort($actual);
        if ($actual !== $expected || array_filter($security[0], fn ($scopes) => $scopes !== [])) {
            throw new IntegrationException('OpenAPI security differs from route security contract.');
        }
    }
}
