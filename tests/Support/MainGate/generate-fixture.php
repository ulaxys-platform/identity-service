<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Ulaxys\Identity\Integration\ObservedRouteCollection;

// Test-only process bootstrap. This fixture is never loaded by source runtime routes.
require dirname(__DIR__, 3).'/vendor/autoload.php';
$_SERVER['argv'] = ['artisan', ...array_slice($argv, 1)];
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
// Keep this closed synthetic generator fixture independent of consumer business routes.
$fixtureRoutes = new ObservedRouteCollection;
foreach ($app['router']->getRoutes() as $route) {
    if (! str_starts_with($route->uri(), 'api/v1/')) {
        $fixtureRoutes->add($route);
    }
}
$app['router']->setRoutes($fixtureRoutes);
require dirname(__DIR__, 2).'/Fixtures/Integration/routes/api.php';
$metadata = require dirname(__DIR__, 2).'/Fixtures/Integration/'.(getenv('ULAXYS_TEST_LOCALIZED_METADATA') ? 'localized-metadata.php' : 'metadata.php');
if (getenv('ULAXYS_TEST_CHANGED_TRANSLATION')) {
    $metadata['modules'][0]['permissions']['actions'][0]['description']['tr'] = 'Değişen İzin';
}
if (getenv('ULAXYS_TEST_CHANGED_PREFIX')) {
    $metadata['gateway']['mainGate']['prefix'] = 'changed/fixture';
}
$app['config']->set('integration', $metadata);
exit($kernel->handle(new ArgvInput($_SERVER['argv']), new ConsoleOutput));
