<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;

// Read-only export of a template-derived service's integration catalog: its resolved
// config('integration') plus business routes in registration order (raw methods, so
// GET keeps Laravel's HEAD alias on replay). Laravel cache/storage writes go to tmp.
require dirname(__DIR__, 3).'/vendor/autoload.php';
$root = rtrim($argv[1], '/');
spl_autoload_register(function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, true, true);
$tmp = sys_get_temp_dir().'/catalog-export-'.bin2hex(random_bytes(8));
mkdir($tmp.'/bootstrap/cache', 0700, true);
mkdir($tmp.'/storage/framework/views', 0700, true);
try {
    $app = require $root.'/bootstrap/app.php';
    $app->useBootstrapPath($tmp.'/bootstrap');
    $app->useStoragePath($tmp.'/storage');
    $app->make(Kernel::class)->bootstrap();
    $routes = [];
    foreach ($app['router']->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue; // Template-owned infrastructure is registered by the replaying template.
        }
        $routes[] = ['methods' => $route->methods(), 'uri' => $route->uri(), 'name' => $route->getName(),
            'action' => $route->getActionName(), 'middleware' => $route->gatherMiddleware(), 'wheres' => $route->wheres];
    }
    echo json_encode(['integration' => $app['config']->get('integration'), 'routes' => $routes],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
} finally {
    (new Filesystem)->deleteDirectory($tmp);
}
