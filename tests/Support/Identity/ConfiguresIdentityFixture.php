<?php

namespace Ulaxys\Identity\Tests\Support\Identity;

use Illuminate\Filesystem\Filesystem;
use Ulaxys\Identity\Integration\IntegrationMetadata;
use Ulaxys\Identity\Integration\NativeIdentityRenderer;
use Ulaxys\Identity\Integration\ObservedRouteCollection;
use Ulaxys\Identity\Integration\OutputWriter;
use Ulaxys\Identity\Integration\PermissionMapper;
use Ulaxys\Identity\Integration\RouteDiscovery;

trait ConfiguresIdentityFixture
{
    protected string $bundle;

    protected string $directory;

    protected function configureIdentityFixture(): void
    {
        $this->bundle = 'identity-test-'.bin2hex(random_bytes(8));
        $this->directory = base_path('generated/integration/'.$this->bundle);
        $routes = new ObservedRouteCollection;
        foreach ($this->app['router']->getRoutes() as $route) {
            // Consumer routes have independent parity coverage; retain infrastructure plus this suite's fixture.
            if (str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $routes->add($route);
        }
        $this->app['router']->setRoutes($routes);
        require base_path('tests/Fixtures/Integration/routes/api.php');
        config(['integration' => require base_path('tests/Fixtures/Integration/localized-metadata.php')]);
    }

    protected function nativeFiles(): array
    {
        $config = app(IntegrationMetadata::class)->validate(config('integration'));
        $snapshot = app(PermissionMapper::class)->map(app(RouteDiscovery::class)->collect($this->app['router']), $config, true);

        return app(NativeIdentityRenderer::class)->generate($snapshot, $config);
    }

    protected function publishNative(): void
    {
        app(OutputWriter::class)->apply($this->nativeFiles(), $this->bundle, false, false);
    }

    protected function removeIdentityFixture(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
    }
}
