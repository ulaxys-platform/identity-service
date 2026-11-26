<?php

namespace Ulaxys\Identity\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Ulaxys\Identity\Http\Controllers\Documentation\OpenApiController;
use Ulaxys\Identity\Tests\TestCase;

final class OpenApiRouteScopeTest extends TestCase
{
    #[DataProvider('registeredRoutes')]
    public function test_release_gate_checks_registered_api_boundaries(string $registration, ?string $failure): void
    {
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $directory = sys_get_temp_dir().'/openapi-scope-'.bin2hex(random_bytes(8));
        $files = new Filesystem;
        $files->makeDirectory($directory.'/.docker', 0755, true);
        $files->makeDirectory($directory.'/bootstrap');
        try {
            // Run the actual release script in a separate process. Its isolated bootstrap
            // injects registered routes after normal providers, without editing the checkout.
            $files->copy(base_path('.docker/verify-openapi.php'), $directory.'/.docker/verify-openapi.php');
            $files->link(base_path('vendor'), $directory.'/vendor');
            $bootstrap = var_export(base_path('bootstrap/app.php'), true);
            $files->put($directory.'/bootstrap/app.php', '<?php $app = require '.$bootstrap.';
                $app->afterBootstrapping(Illuminate\\Foundation\\Bootstrap\\BootProviders::class, function () {
                    '.$registration.'
                });
                return $app;');
            $process = new Process([PHP_BINARY, $directory.'/.docker/verify-openapi.php'], base_path());
            $process->run();
            if ($failure === null) {
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $this->assertStringContainsString($this->mountedOperationCount().' mounted operations', $process->getOutput());
            } else {
                $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput());
                $this->assertStringContainsString($failure, $process->getErrorOutput());
            }
        } finally {
            $files->deleteDirectory($directory);
        }
    }

    public static function registeredRoutes(): array
    {
        $route = '\\Illuminate\\Support\\Facades\\Route::';
        $openApiController = '\\'.OpenApiController::class;

        return [
            'undocumented API closure' => [
                $route."get('/test/undocumented', fn () => null)->middleware('api');",
                'mounted operation missing from artifact: get /test/undocumented',
            ],
            'controller outside application namespace' => [
                $route."post('/test/undocumented', \\Illuminate\\Routing\\RedirectController::class)->middleware('api');",
                'mounted operation missing from artifact: post /test/undocumented',
            ],
            'internal boundary without api group' => [
                $route."delete('/test/undocumented', fn () => null)->middleware('service.signature');",
                'mounted operation missing from artifact: delete /test/undocumented',
            ],
            'document route name cannot hide business path' => [
                $route."get('/test/undocumented', fn () => null)->middleware('api')->name('openapi');",
                'mounted operation missing from artifact: get /test/undocumented',
            ],
            'document URI cannot hide extra method' => [
                $route."post('/openapi.json', fn () => null)->middleware('api')->name('openapi');",
                'mounted operation missing from artifact: post /openapi.json',
            ],
            'document URI and name cannot hide replacement closure' => [
                $route."get('/openapi.json', fn () => null)->middleware('api')->name('openapi');",
                'mounted operation missing from artifact: get /openapi.json',
            ],
            'standalone HEAD must be documented' => [
                $route."match(['HEAD'], '/test/undocumented', fn () => null)->middleware('api');",
                'mounted operation missing from artifact: head /test/undocumented',
            ],
            'all methods on a documented path are checked' => [
                $route."match(['GET', 'POST'], '/healthCheck/apiStatus', fn () => null)->middleware('api');",
                'mounted operation missing from artifact: post /healthCheck/apiStatus',
            ],
            'Signature still required for a documented closure' => [
                $route."get('/healthCheck/apiStatus', fn () => null)->middleware(['api', 'service.signature']);",
                'missing Signature security: get /healthCheck/apiStatus',
            ],
            'unclassified closure cannot bypass API boundary' => [
                $route."get('/test/undocumented', fn () => null);",
                'registered route outside API boundaries: /test/undocumented',
            ],
            'exact infrastructure document route excluded' => [
                '',
                null,
            ],
            'document endpoint cannot lose Signature protection' => [
                $route."get('/openapi.json', {$openApiController}::class)->middleware('api')->name('openapi');",
                'mounted operation missing from artifact: get /openapi.json',
            ],
            'documented API closure accepted' => [
                $route."get('/healthCheck/apiStatus', fn () => null)->middleware('api');",
                null,
            ],
            'documented external controller accepted' => [
                $route."get('/healthCheck/apiStatus', \\Illuminate\\Routing\\RedirectController::class)->middleware('api');",
                null,
            ],
        ];
    }
}
