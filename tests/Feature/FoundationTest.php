<?php

namespace Ulaxys\Identity\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Ulaxys\Identity\Http\Requests\ApiFormRequest;
use Ulaxys\Identity\Repositories\RepositoryInterface\HealthRepositoryInterface;
use Ulaxys\Identity\Tests\TestCase;

final class FoundationTest extends TestCase
{
    public function test_liveness_does_not_touch_persistence(): void
    {
        $repo = $this->mock(HealthRepositoryInterface::class);
        $repo->shouldNotReceive('probe');
        $this->getJson('/healthCheck/apiStatus')->assertOk()->assertExactJson(['size' => 0, 'status' => 200, 'message' => 'Service is available']);
    }

    public function test_readiness_checks_repository_and_returns_safe_outage(): void
    {
        $repo = $this->mock(HealthRepositoryInterface::class);
        $repo->shouldReceive('probe')->once()->andReturnNull();
        $this->getJson('/healthCheck/postgresStatus')->assertOk()->assertJsonPath('message', 'Database is available');
        $repo->shouldReceive('probe')->once()->andThrow(new \RuntimeException('private-host password'));
        $this->getJson('/healthCheck/postgresStatus')->assertStatus(503)->assertExactJson(['status' => 503, 'code' => 503, 'message' => 'Database is unavailable.']);
    }

    public function test_signature_fails_closed_and_accepts_only_expected_header(): void
    {
        Route::middleware(['api', 'service.signature', 'lang'])->get('/test/protected', fn () => response()->json(['locale' => app()->getLocale()]));
        foreach (['', 'wrong'] as $provided) {
            config(['services.internal.signature' => 'expected']);
            $this->withHeader('Signature', $provided)->getJson('/test/protected')->assertForbidden()->assertExactJson(['status' => 403, 'code' => 403, 'message' => 'Forbidden.']);
        }
        config(['services.internal.signature' => '']);
        $this->withHeader('Signature', 'expected')->getJson('/test/protected')->assertForbidden();
        config(['services.internal.signature' => 'expected']);
        $this->withHeaders(['Signature' => 'expected', 'Language' => 'TR-tr'])->getJson('/test/protected')->assertOk()->assertJsonPath('locale', 'tr-tr');
    }

    public function test_invalid_language_and_unknown_fields_use_400_contract(): void
    {
        $this->withHeader('Language', 'not valid!')->getJson('/healthCheck/apiStatus')->assertStatus(400)->assertJsonPath('code', 400);
        $this->withHeader('Language', 'en')->getJson('/healthCheck/apiStatus?unexpected=1')->assertStatus(400)->assertJsonPath('message', 'Unsupported request field.');
    }

    public function test_dedicated_form_request_validates_required_fields_and_text(): void
    {
        Route::middleware(['api', 'json.body'])->post('/test/validation', fn (FoundationRequest $request) => response()->json($request->validated()));
        $this->call('POST', '/test/validation', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{broken')->assertStatus(400);
        $this->call('POST', '/test/validation', [], [], [], ['CONTENT_TYPE' => 'application/json'], '[]')->assertStatus(400);
        $this->postJson('/test/validation', [])->assertStatus(400)->assertJsonPath('code', 400);
        $this->postJson('/test/validation', ['name' => "bad\0text"])->assertStatus(400);
        $this->postJson('/test/validation', ['name' => 'valid'])->assertOk()->assertExactJson(['name' => 'valid']);
    }

    public function test_exceptions_never_expose_details_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        Route::get('/test/failure', fn () => throw new \RuntimeException('secret-stack'));
        $this->getJson('/test/failure')->assertStatus(500)->assertExactJson(['status' => 500, 'code' => 500, 'message' => 'Internal server error.']);
        $this->getJson('/missing')->assertNotFound()->assertJsonPath('message', 'Resource not found.');
        $this->postJson('/healthCheck/apiStatus')->assertStatus(405)->assertJsonPath('code', 405);
    }

    public function test_registered_routes_keep_boundaries_and_names(): void
    {
        $routes = Route::getRoutes();
        $this->assertNotNull($routes->getByName('healthCheck/apiStatus'));
        $this->assertNotContains('service.signature', $routes->getByName('healthCheck/postgresStatus')->gatherMiddleware());
        $this->assertContains('service.signature', $routes->getByName('openapi')->gatherMiddleware());
        $this->assertStringContainsString('/api/v1/', file_get_contents(base_path('routes/api.php')));
    }

    public function test_openapi_generation_and_release_gate_and_stale_detection(): void
    {
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $process = new Process([PHP_BINARY, '.docker/verify-openapi.php'], base_path());
        $process->mustRun();
        $this->assertStringContainsString($this->mountedOperationCount().' mounted operations', $process->getOutput());
        $path = storage_path('api-docs/api-docs.json');
        $original = file_get_contents($path);
        try {
            $spec = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
            $spec['info']['version'] = 'stale';
            file_put_contents($path, json_encode($spec));
            $process->run();
            $this->assertNotSame(0, $process->getExitCode());
        } finally {
            file_put_contents($path, $original);
        }
        config(['services.internal.signature' => 'expected']);
        $this->getJson('/openapi.json')->assertForbidden();
        $this->withHeader('Signature', 'expected')->get('/openapi.json')->assertOk()->assertHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
final class FoundationRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:50']];
    }
}
