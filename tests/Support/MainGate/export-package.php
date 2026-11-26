<?php

// Isolated gateway recording double; never loaded by the template API router.
require dirname(__DIR__, 3).'/vendor/autoload.php';
require __DIR__.'/RecordingServiceProxy.php';

// This standalone simulator tests generated code that targets MainGate's
// native App namespace. A consumer service may have a namespaced app root.
// Alias only within this isolated PHP process; never rewrite the generated
// MainGate output, and never register these aliases in the actual service.
$composer = json_decode(file_get_contents(dirname(__DIR__, 3).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$serviceRoot = array_search('app/', $composer['autoload']['psr-4'], true);
if (! is_string($serviceRoot)) {
    throw new RuntimeException('Missing service app namespace for MainGate test simulator.');
}
foreach ([
    'Http\\Controllers\\Controller',
    'Services\\Gateway\\ServiceProxy',
] as $relativeClass) {
    $original = 'App'.'\\'.$relativeClass;
    $source = $serviceRoot.$relativeClass;
    if ($source !== $original && ! class_exists($original, false)) {
        if (! class_exists($source) || ! class_alias($source, $original)) {
            throw new RuntimeException('Cannot configure isolated MainGate class alias.');
        }
    }
}

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ulaxys\Identity\Services\Gateway\ServiceProxy;

// These doubles test generated middleware placement, not MainGate's real authorization.
final class RecordingAuth
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $request->headers->get('X-Test-User') === 'yes' ? $next($request) : new Response('', 401);
    }
}

final class RecordingPermission
{
    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        return $request->headers->get('X-Test-Permission') === $permission ? $next($request) : new Response('', 403);
    }
}

// One routable value per reviewed RouteDiscovery constraint (numeric, letters, ULID, UUID).
function sampleSegment(?string $constraint): string
{
    foreach (['1', 'abc', 'ABC', '01ARZ3NDEKTSV4RRFFQ69G5FAV', '12345678-1234-4234-8234-123456789abc'] as $value) {
        if ($constraint === null || preg_match('#^(?:'.$constraint.')$#D', $value)) {
            return $value;
        }
    }
    throw new RuntimeException('No routable sample for constraint '.$constraint);
}

$container = new Container;
Container::setInstance($container);
$router = new Router(new Dispatcher($container), $container);
$container->instance('router', $router);
$container->alias('request', Request::class);
$router->aliasMiddleware('auth', RecordingAuth::class);
$router->aliasMiddleware('permission', RecordingPermission::class);
Facade::setFacadeApplication($container);
$proxy = new ServiceProxy;
$container->instance(ServiceProxy::class, $proxy);
$mainGateProxyKey = 'App'.'\\Services\\Gateway\\ServiceProxy';
if ($mainGateProxyKey !== ServiceProxy::class) {
    // The independent generated MainGate controller still resolves its own
    // App-namespaced dependency. Both names must point to the SAME test double.
    $container->instance($mainGateProxyKey, $proxy);
}
require $argv[2]; // Service-specific controller.
require $argv[1]; // Entry applies auth/prefix exactly once.
$operations = [];
foreach ($router->getRoutes() as $route) {
    $methods = array_values(array_diff($route->methods(), in_array('GET', $route->methods(), true) ? ['HEAD'] : []));
    $operations[] = ['methods' => $methods, 'uri' => '/'.$route->uri(), 'action' => $route->getActionName(),
        'name' => $route->getName(), 'middleware' => $route->gatherMiddleware(), 'constraints' => $route->wheres,
        'parameters' => $route->parameterNames()];
    if (($argv[3] ?? '') !== '--contract') {
        continue;
    }
    [$class, $action] = explode('@', $route->getActionName());
    $controller = $container->make($class);
    foreach ($methods as $method) {
        $body = "{broken JSON\x00";
        $query = 'repeated=a&repeated=b&empty=&encoded=%252F+%26';
        $request = Request::create('/?'.$query, $method, [], [], [], ['HTTP_SIGNATURE' => 'untrusted', 'HTTP_LANGUAGE' => 'tr'], $body);
        $request->headers->set('Signature', 'untrusted');
        $arguments = [$request];
        $values = [];
        foreach ($route->parameterNames() as $name) {
            $values[$name] = '0001Code-Ö +%?#';
            $arguments[] = $values[$name];
        }
        $response = $controller->{$action}(...$arguments);
        $call = end($proxy->calls);
        $expected = substr('/'.$route->uri(), strlen('/'.trim($argv[4], '/')) + 1);
        foreach ($values as $name => $value) {
            $expected = str_replace('{'.$name.'}', rawurlencode($value), $expected);
        }
        if ($call['service'] !== $argv[5] || $call['request'] !== $request || $call['path'] !== $expected
            || $response !== $proxy->response || $request->headers->has('Signature') || $request->server->has('HTTP_SIGNATURE')
            || $request->getContent() !== $body || $request->server->get('QUERY_STRING') !== $query
            || $request->headers->get('Language') !== 'tr' || $request->getMethod() !== $method) {
            throw new RuntimeException('Proxy contract mismatch: '.$action);
        }
    }
    if ($route->parameterNames() !== []) {
        foreach (['', '.', '..', '...', '../x', 'a/b', 'a\\b', "a\x00", "a\r\n", "a\x7f"] as $invalid) {
            $before = count($proxy->calls);
            $arguments = [Request::create('/'), ...array_fill(0, count($route->parameterNames()), $invalid)];
            try {
                $controller->{$action}(...$arguments);
                throw new RuntimeException('Unsafe segment accepted: '.$action);
            } catch (BadRequestHttpException) {
                if ($before !== count($proxy->calls)) {
                    throw new RuntimeException('Unsafe segment reached proxy.');
                }
            }
        }
        foreach (['00001', '%2F..%2Fsecret', '%', '?', '#', '+', ' ', 'Ö'] as $valid) {
            $arguments = [Request::create('/'), ...array_fill(0, count($route->parameterNames()), $valid)];
            $controller->{$action}(...$arguments);
            $call = end($proxy->calls);
            if (! str_contains($call['path'], rawurlencode($valid))) {
                throw new RuntimeException('Segment lexeme/encoding changed.');
            }
        }
    }
}
if (($argv[3] ?? '') === '--contract') {
    $sourceCollection = $router->getRoutes();
    $registrations = iterator_to_array($sourceCollection);
    $router->setCompiledRoutes($router->getRoutes()->compile());
    foreach ($registrations as $route) {
        $uri = '/'.$route->uri();
        foreach ($route->parameterNames() as $name) {
            $uri = str_replace('{'.$name.'}', sampleSegment($route->wheres[$name] ?? null), $uri);
        }
        $method = $route->methods()[0];
        $permission = substr($route->gatherMiddleware()[1], strlen('permission:'));
        foreach ([[[], 401], [['HTTP_X_TEST_USER' => 'yes'], 403], [['HTTP_X_TEST_USER' => 'yes', 'HTTP_X_TEST_PERMISSION' => $permission], 409]] as [$headers, $status]) {
            $before = count($proxy->calls);
            $request = Request::create($uri, $method, [], [], [], $headers);
            $container->instance('request', $request);
            $matched = $router->getRoutes()->match($request);
            if ($matched->getName() !== $route->getName()) {
                throw new RuntimeException('Compiled route ordering changed: '.$route->getName());
            }
            $response = $router->dispatch($request);
            if ($response->getStatusCode() !== $status || count($proxy->calls) !== $before + ($status === 409 ? 1 : 0)
                || ($status === 409 && end($proxy->calls)['request'] !== $request)) {
                throw new RuntimeException('Generated auth/permission boundary failed.');
            }
        }
        if (in_array('GET', $route->methods(), true)) {
            $head = Request::create($uri, 'HEAD');
            $expectedHead = $sourceCollection->match($head);
            $actualHead = $router->getRoutes()->match($head);
            if ($expectedHead->getName() !== $actualHead->getName()) {
                throw new RuntimeException('HEAD alias/explicit override changed during compilation.');
            }
            $head->headers->set('X-Test-User', 'yes');
            $head->headers->set('X-Test-Permission', substr($actualHead->gatherMiddleware()[1], strlen('permission:')));
            $container->instance('request', $head);
            $before = count($proxy->calls);
            $router->dispatch($head);
            if (count($proxy->calls) !== $before + 1 || end($proxy->calls)['request']->getMethod() !== 'HEAD') {
                throw new RuntimeException('HEAD request method was converted.');
            }
        }
        foreach ($route->wheres as $name => $constraint) {
            $invalid = str_replace('{'.$name.'}', 'invalid!', '/'.$route->uri());
            if (count($route->parameterNames()) === 1 && ! preg_match('#^(?:'.$constraint.')$#D', 'invalid!')) {
                try {
                    $router->getRoutes()->match(Request::create($invalid, $method));
                    throw new RuntimeException('Compiled constraint was lost.');
                } catch (NotFoundHttpException) {
                    // Expected: invalid value cannot reach the controller.
                }
            }
        }
    }
}
echo json_encode($operations, JSON_THROW_ON_ERROR);
