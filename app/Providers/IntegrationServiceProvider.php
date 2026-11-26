<?php

namespace Ulaxys\Identity\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Ulaxys\Identity\Integration\ObservedRouteCollection;

final class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->runningInConsole() || ! array_intersect([
            'ulaxys:generate-gateway', 'ulaxys:generate-permissions', 'ulaxys:generate-integration', 'ulaxys:generate-deploy',
        ], $_SERVER['argv'] ?? [])) {
            return;
        }
        $this->app->afterResolving('router', function (Router $router) {
            $collection = new ObservedRouteCollection;
            foreach ($router->getRoutes() as $route) {
                $collection->add($route);
            }
            $router->setRoutes($collection);
        });
    }
}
