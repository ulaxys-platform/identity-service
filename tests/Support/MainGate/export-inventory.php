<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;

// Optional reference checkout is read-only; all Laravel cache/storage writes go to tmp.
require dirname(__DIR__, 3).'/vendor/autoload.php';
$root = $argv[1];
spl_autoload_register(function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, true, true);
$tmp = sys_get_temp_dir().'/inventory-export-'.bin2hex(random_bytes(8));
mkdir($tmp.'/bootstrap/cache', 0700, true);
mkdir($tmp.'/storage/framework/views', 0700, true);
try {
    $app = require $root.'/bootstrap/app.php';
    $app->useBootstrapPath($tmp.'/bootstrap');
    $app->useStoragePath($tmp.'/storage');
    $app->make(Kernel::class)->bootstrap();
    $routes = [];
    foreach ($app['router']->getRoutes() as $route) {
        $routes[] = ['methods' => array_values(array_diff($route->methods(), in_array('GET', $route->methods(), true) ? ['HEAD'] : [])),
            'uri' => '/'.$route->uri(), 'name' => $route->getName(), 'action' => $route->getActionName(),
            'middleware' => $route->gatherMiddleware(), 'constraints' => $route->wheres, 'parameters' => $route->parameterNames()];
    }
    echo json_encode($routes, JSON_THROW_ON_ERROR);
} finally {
    (new Filesystem)->deleteDirectory($tmp);
}
