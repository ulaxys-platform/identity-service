<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use OpenApi\Generator;
use Ulaxys\Identity\Http\Controllers\Documentation\OpenApiController;
use Ulaxys\Identity\Integration\IntegrationMetadata;
use Ulaxys\Identity\Integration\SecurityContract;

try {
    // Build/release gate; no database connection, credentials or HTTP generation.
    require __DIR__.'/../vendor/autoload.php';
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $fail = static function (string $message): never {
        throw new RuntimeException('OpenAPI release validation failed: '.$message);
    };
    $path = $argv[1] ?? storage_path('api-docs/api-docs.json');
    if (! is_file($path) || filesize($path) === 0) {
        $fail('generated artifact is missing or empty.');
    }
    $spec = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (! preg_match('/^3\.0\.\d+$/', $spec['openapi'] ?? '')) {
        $fail('expected OpenAPI 3.0.x.');
    }
    if (! (new Generator)->setVersion('3.0.0')->generate([app_path()])->validate()) {
        $fail('annotation/schema validation failed.');
    }
    $walk = function (array $node) use (&$walk, $spec, $fail): void {
        foreach ($node as $key => $value) {
            if ($key === '$ref') {
                if (! is_string($value) || ! str_starts_with($value, '#/')) {
                    $fail('only resolvable local references are allowed.');
                }
                $target = $spec;
                foreach (explode('/', substr($value, 2)) as $part) {
                    $part = str_replace(['~1', '~0'], ['/', '~'], $part);
                    if (! is_array($target) || ! array_key_exists($part, $target)) {
                        $fail('broken reference '.$value);
                    }
                    $target = $target[$part];
                }
            } elseif (is_array($value)) {
                $walk($value);
            }
        }
    };
    $walk($spec);
    $fresh = json_decode((new Generator)->setVersion('3.0.0')->generate([app_path()])->toJson(), true, 512, JSON_THROW_ON_ERROR);
    if ($fresh !== $spec) {
        $fail('artifact is stale; regenerate it.');
    }
    $methods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];
    $documented = [];
    $ids = [];
    foreach ($spec['paths'] ?? [] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if (! in_array($method, $methods, true)) {
                continue;
            }
            $id = $operation['operationId'] ?? null;
            if (! is_string($id) || $id === '' || in_array($id, $ids, true)) {
                $fail('missing or duplicate operationId.');
            }
            $ids[] = $id;
            $documented[$method.' '.$path] = $operation;
        }
    }
    $integration = config('integration', []);
    if (($integration['schemaVersion'] ?? 1) === 2) {
        $integration = (new IntegrationMetadata)->validate($integration);
    }
    if (($integration['schemaVersion'] ?? 1) === 2) {
        $app->make(HttpKernel::class);
    }
    $mounted = [];
    foreach ($app['router']->getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        $contract = SecurityContract::forRoute($integration, $route->getName());
        if ($contract !== null && $route->excludedMiddleware() !== []) {
            $fail('excluded middleware on security-aware route: '.$route->uri());
        }
        if ($contract !== null) {
            SecurityContract::matchRoute($contract, array_values(array_unique($middleware)), $app['router']->gatherRouteMiddleware($route), '/'.ltrim($route->uri(), '/'));
        }
        // Exclude only the document delivery endpoint, not names or URI prefixes.
        if ($route->uri() === 'openapi.json' && $route->getName() === 'openapi'
            && $route->getDomain() === null && $route->methods() === ['GET', 'HEAD']
            && $route->getAction('controller') === OpenApiController::class
            && in_array('service.signature', $middleware, true)) {
            continue;
        }
        // bootstrap/app.php defines an API-only HTTP surface, including public health.
        // Fail closed for unclassified routes instead of assuming they are framework routes.
        if (! array_intersect(['api', 'service.signature'], $middleware)) {
            $fail('registered route outside API boundaries: /'.ltrim($route->uri(), '/'));
        }
        // Laravel adds HEAD to GET routes; a standalone HEAD route must be documented.
        $implicit = in_array('GET', $route->methods(), true) ? ['HEAD'] : [];
        foreach (array_diff($route->methods(), $implicit) as $method) {
            $key = strtolower($method).' /'.ltrim($route->uri(), '/');
            $mounted[] = $key;
            if (! isset($documented[$key])) {
                $fail('mounted operation missing from artifact: '.$key);
            }
            if (($integration['schemaVersion'] ?? 1) === 2 && $contract === null && ! in_array($route->uri(), ['healthCheck/apiStatus', 'healthCheck/postgresStatus'], true)) {
                $fail('missing security contract: '.$key);
            }
            if ($contract !== null) {
                SecurityContract::verifyOpenApi($contract, in_array('service.signature', $middleware, true), $documented[$key], $spec['components']['securitySchemes'] ?? []);
            } elseif (in_array('service.signature', $middleware, true)
                && ! in_array(['serviceSignature' => []], $documented[$key]['security'] ?? [], true)) {
                $fail('missing Signature security: '.$key);
            }
        }
    }
    $expected = array_keys($documented);
    sort($mounted);
    sort($expected);
    if ($mounted === [] || $mounted !== $expected) {
        $fail('artifact operations and mounted application routes differ.');
    }
    $security = $spec['components']['securitySchemes']['serviceSignature'] ?? [];
    if (($security['type'] ?? null) !== 'apiKey' || ($security['in'] ?? null) !== 'header'
        || ($security['name'] ?? null) !== 'Signature') {
        $fail('invalid Signature security scheme.');
    }
    // The artifact must be portable and must not carry deployment URLs or credentials.
    if (($spec['servers'] ?? []) !== [['url' => '/', 'description' => 'Current Service']]) {
        $fail('expected the current-service relative server URL.');
    }
    echo 'OpenAPI release gate passed: '.count($mounted).' mounted operations; unique IDs and local references verified.'.PHP_EOL;

} catch (Throwable $error) {
    fwrite(STDERR, 'OpenAPI release validation failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
