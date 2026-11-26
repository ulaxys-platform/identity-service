<?php

namespace Ulaxys\Identity\Integration;

use Illuminate\Support\Str;
use Ulaxys\Identity\Http\Controllers\Controller;

/** Inventory-style native PHP, rendered from the same validated snapshot as JSON. */
final class NativeMainGateRenderer
{
    public function __construct(private RouteDiscovery $discovery) {}

    public function generate(array $snapshot, array $config): array
    {
        $service = $config['service'];
        $settings = $config['gateway']['mainGate'];
        $controller = IntegrationMetadata::phpIdentifier($settings['controller'] ?? Str::studly($service).'Controller');
        if (strcasecmp($controller, 'Controller') === 0) {
            throw new IntegrationException('Native controller cannot replace the MainGate base Controller.');
        }
        $destination = $settings['destination'] ?? $service;
        // Laravel's uncached HEAD index can override a GET alias while compiled
        // Symfony matching keeps the GET route first. Refuse this ambiguous form.
        $exposed = array_values(array_filter($snapshot['routes'], fn ($route) => $route['expose']));
        foreach ($exposed as $index => $earlier) {
            foreach (array_slice($exposed, $index + 1) as $later) {
                $headConflict = (in_array('GET', $earlier['methods'], true) && in_array('HEAD', $later['methods'], true))
                    || (in_array('HEAD', $earlier['methods'], true) && in_array('GET', $later['methods'], true));
                if ($headConflict && ($earlier['uri'] === $later['uri'] || $this->discovery->mayShadow($earlier['uri'], $later['uri']) || $this->discovery->mayShadow($later['uri'], $earlier['uri']))) {
                    throw new IntegrationException('Explicit HEAD overlaps a GET alias; native cached/uncached semantics cannot be guaranteed: '.$earlier['name'].' / '.$later['name']);
                }
            }
        }
        $catalog = [];
        foreach ($snapshot['permissions'] as $subject) {
            foreach ($subject['actions'] as $action) {
                $key = $action['action'].'-'.$subject['subject'];
                if (! preg_match('/^[A-Za-z][A-Za-z0-9-]*$/D', $key)) {
                    throw new IntegrationException('Permission names must support Inventory permission:<action>-<subject> syntax: '.$key);
                }
                $identity = [$subject['subject'], $action['action']];
                if (isset($catalog[$key]) && $catalog[$key] !== $identity) {
                    throw new IntegrationException('Ambiguous canonical permission name: '.$key);
                }
                $catalog[$key] = $identity;
            }
        }
        $modules = $actions = [];
        $reserved = array_map('strtolower', [...get_class_methods(Controller::class), '__construct', '__destruct', 'encodePathSegment']);
        foreach ($snapshot['routes'] as $route) {
            if (! $route['expose']) {
                continue;
            }
            if (! $route['requiresSignature'] || $route['permission'] === null) {
                throw new IntegrationException('Native exposed operations require Signature and an explicit permission; exemptions have no reviewed MainGate representation: '.$route['name']);
            }
            if (array_diff($route['methods'], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS']) || $route['methods'] === []) {
                throw new IntegrationException('Unsupported native HTTP method: '.$route['name']);
            }
            $action = IntegrationMetadata::phpIdentifier($route['gatewayAction'] ?? Str::camel($route['module']).'_'.Str::camel($route['operation']));
            $identity = strtolower($action);
            if (isset($actions[$identity]) || in_array($identity, $reserved, true) || str_starts_with($action, '__')) {
                throw new IntegrationException('Native action collision/reserved method; configure a unique gatewayAction: '.$action);
            }
            $actions[$identity] = true;
            $permission = $route['permission']['action'].'-'.$route['permission']['subject'];
            if (($catalog[$permission] ?? null) !== [$route['permission']['subject'], $route['permission']['action']]) {
                throw new IntegrationException('Native permission is not declared in the permission catalog: '.$permission);
            }
            $parameterNames = array_column($route['parameters'], 'name');
            if (count($parameterNames) !== count(array_unique($parameterNames))) {
                throw new IntegrationException('Repeated route parameter cannot be represented natively: '.$route['name']);
            }
            foreach ($route['parameters'] as $parameter) {
                if ($parameter['optional']) {
                    throw new IntegrationException('Optional parameters have no reviewed native forwarding semantics; use explicit routes: '.$route['name']);
                }
                if (in_array($parameter['name'], ['request', 'this', 'GLOBALS'], true)) {
                    throw new IntegrationException('Parameter conflicts with native transport variables: '.$route['name']);
                }
            }
            $modules[$route['module']][] = [...$route, 'nativeAction' => $action, 'nativePermission' => $permission];
        }
        $modules = $this->orderedModules($modules, $snapshot['routes']);
        $files = [];
        $add = function (string $path, string $contents) use (&$files): void {
            $files[$path] = NativeMainGateFile::fromRenderer($path, $contents);
        };
        $entry = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::prefix(".$this->literal($settings['prefix']).")\n    ->middleware('auth')\n    ->group(function (): void {\n";
        $methods = [];
        $hasParameters = false;
        foreach ($modules as $module => $routes) {
            $entry .= "        require __DIR__.'/".$service.'/'.$module.".php';\n";
            $php = "<?php\n\nuse App\\Http\\Controllers\\{$controller};\nuse Illuminate\\Support\\Facades\\Route;\n";
            foreach ($routes as $route) {
                $verb = count($route['methods']) === 1 && ! in_array($route['methods'][0], ['HEAD', 'OPTIONS'], true)
                    ? strtolower($route['methods'][0]).'(' : 'match('.$this->literal($route['methods']).', ';
                $php .= "\nRoute::".$verb.$this->literal($route['uri']).", [{$controller}::class, ".$this->literal($route['nativeAction'])."])\n"
                    .'    ->middleware('.$this->literal('permission:'.$route['nativePermission']).")\n"
                    .'    ->name('.$this->literal($service.'.'.$route['nativeAction']).");\n";
                $constraints = [];
                foreach ($route['parameters'] as $parameter) {
                    if ($parameter['constraint'] !== null) {
                        $constraints[$parameter['name']] = $parameter['constraint'];
                    }
                }
                if ($constraints !== []) {
                    $php = substr($php, 0, -2)."\n    ->where(".$this->literal($constraints).");\n";
                }
                $methods[] = $this->method($route, $destination);
                $hasParameters = $hasParameters || $route['parameters'] !== [];
            }
            $add('gateway/maingate/routes/'.$service.'/'.$module.'.php', $php);
        }
        $entry .= "    });\n";
        $add('gateway/maingate/routes/'.$service.'.php', $entry);
        $php = "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\Gateway\\ServiceProxy;\nuse Illuminate\\Http\\Request;\n";
        if ($hasParameters) {
            $php .= "use Symfony\\Component\\HttpKernel\\Exception\\BadRequestHttpException;\n";
        }
        $php .= "\nclass {$controller} extends Controller\n{\n    public function __construct(private ServiceProxy \$proxy) {}\n".implode('', $methods);
        if ($hasParameters) {
            $php .= <<<'CODE'

    /** Encode decoded Laravel parameter values once; preserve leading zeroes and literal %. */
    private function encodePathSegment(string|int $value): string
    {
        $value = (string) $value;
        if ($value === '' || preg_match('/^\.+$/D', $value) || preg_match('/[\\\\\/\x00-\x1f\x7f]/', $value)) {
            throw new BadRequestHttpException('Invalid upstream path segment.');
        }

        return rawurlencode($value);
    }
CODE;
            $php .= "\n";
        }
        $add('gateway/maingate/controllers/'.$controller.'.php', $php."}\n");
        $add('gateway/maingate/README.md', $this->readme($service, $controller, $destination, $settings['prefix'], array_keys($modules)));

        return $files;
    }

    private function method(array $route, string $destination): string
    {
        $arguments = 'Request $request';
        foreach ($route['parameters'] as $parameter) {
            $arguments .= ', int|string $'.$parameter['name'];
        }
        $parts = [];
        foreach (preg_split('/(\{[A-Za-z_][A-Za-z0-9_]*\})/', ltrim($route['uri'], '/'), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $part) {
            $parts[] = str_starts_with($part, '{') ? '$this->encodePathSegment($'.trim($part, '{}').')' : $this->literal($part);
        }
        $path = $parts === [] ? "''" : implode('.', $parts);

        return "\n    public function {$route['nativeAction']}({$arguments}): mixed\n    {\n"
            ."        \$request->headers->remove('Signature');\n        \$request->server->remove('HTTP_SIGNATURE');\n\n"
            .'        return $this->proxy->forward('.$this->literal($destination).", \$request, {$path});\n    }\n";
    }

    /** Group files without changing precedence of any potentially overlapping source routes. */
    private function orderedModules(array $modules, array $source): array
    {
        $edges = [];
        $routes = array_values(array_filter($source, fn ($route) => $route['expose']));
        foreach ($routes as $index => $earlier) {
            foreach (array_slice($routes, $index + 1) as $later) {
                $left = in_array('GET', $earlier['methods'], true) ? [...$earlier['methods'], 'HEAD'] : $earlier['methods'];
                $right = in_array('GET', $later['methods'], true) ? [...$later['methods'], 'HEAD'] : $later['methods'];
                if ($earlier['module'] !== $later['module'] && array_intersect($left, $right)
                    && ($earlier['uri'] === $later['uri'] || $this->discovery->mayShadow($earlier['uri'], $later['uri']) || $this->discovery->mayShadow($later['uri'], $earlier['uri']))) {
                    $edges[$later['module']][$earlier['module']] = true;
                }
            }
        }
        $ordered = [];
        while ($modules !== []) {
            $next = null;
            foreach ($modules as $module => $routes) {
                if (array_diff(array_keys($edges[$module] ?? []), array_keys($ordered)) === []) {
                    $next = $module;
                    break;
                }
            }
            if ($next === null) {
                throw new IntegrationException('Module grouping would change significant route order; regroup operations into compatible modules.');
            }
            $ordered[$next] = $modules[$next];
            unset($modules[$next]);
        }

        return $ordered;
    }

    private function literal(mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = (array_is_list($value) ? '' : $this->literal($key).' => ').$this->literal($item);
            }

            return '['.implode(', ', $parts).']';
        }

        return var_export($value, true);
    }

    private function readme(string $service, string $controller, string $destination, string $prefix, array $modules): string
    {
        return <<<MD
# {$service} — native PHP MainGate package

Generated from registered Laravel routes and explicit integration metadata. Modules:
MD
            .' '.implode(', ', $modules).".\n\n"
            .<<<MD
1. Copy `controllers/{$controller}.php` to MainGate's `app/Http/Controllers/`.
2. Copy `routes/{$service}.php` to MainGate's `routes/`.
3. Copy `routes/{$service}/` to MainGate's `routes/{$service}/` (if modules exist).
4. Require the entry **once** from MainGate's existing `routes/api.php`: `require __DIR__.'/{$service}.php';`. The entry applies `{$prefix}` and `auth`; do not repeat either boundary.
5. Register destination key `{$destination}` in the existing `Ulaxys\Identity\Services\Gateway\ServiceProxy` runtime configuration; hosts and credentials belong there.
6. Install and verify declared permissions separately. This package supplies no Identity seeder, roles or grants. JSON declarations are review input, not executable integration.
7. Verify MainGate's existing `auth` and `permission:<action>-<subject>` middleware. No business operation is permission-free.
8. Refresh MainGate route caches and inspect `php artisan route:list`; verify module ordering and constraints.
9. Test forwarding, status codes, raw query/body, Language, parameter encoding and trusted Signature injection against the real MainGate application.
10. Copy only these controller/route PHP files. Do not copy fixtures, generator internals or intermediate JSON as executable PHP.

The controller removes incoming Signature from headers and HTTP_SIGNATURE before forwarding the original Request via ServiceProxy::forward(). MainGate's trusted proxy must inject its configured internal Signature and enforce its existing identity-header policy. The generated controller alone does not implement that security boundary. It does not rebuild bodies, queries or responses.

Parameters are decoded Laravel segment values, encoded once with rawurlencode. Separators, empty/dot-only segments and controls are rejected; literal percent is data. Route constraints remain on the generated routes. Explicit HEAD/OPTIONS use Route::match with the original request method; GET retains Laravel's automatic HEAD alias. Optional parameters and exposed exemptions are refused.

Regenerate in the source service; never edit owned output. Use --dry-run before publication and --check to detect stale output. Installation is manual. Recording-double tests and Inventory semantic parity do not establish end-to-end integration with the unavailable MainGate runtime. Review destination configuration, credentials and permission installation before use.
MD
            ."\n";
    }
}
