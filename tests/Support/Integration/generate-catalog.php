<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Ulaxys\Identity\Integration\ObservedRouteCollection;

// Test-only process: replays an exported service catalog (export-catalog.php) through this
// template's generator. Controllers are action strings only; generation never dispatches them.
require dirname(__DIR__, 3).'/vendor/autoload.php';
$catalog = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$_SERVER['argv'] = ['artisan', ...array_slice($argv, 2)];
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$routes = new ObservedRouteCollection;
foreach ($app['router']->getRoutes() as $route) {
    if (! str_starts_with($route->uri(), 'api/v1/')) {
        $routes->add($route);
    }
}
$app['router']->setRoutes($routes);
foreach ($catalog['routes'] as $route) {
    Route::match($route['methods'], $route['uri'], $route['action'])->middleware($route['middleware'])
        ->where($route['wheres'])->name($route['name']);
}
$app['config']->set('integration', $catalog['integration']);
exit($kernel->handle(new ArgvInput($_SERVER['argv']), new ConsoleOutput));
