<?php

namespace Ulaxys\Identity\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Ulaxys\Identity\Tests\TestCase;

/** Opt-in read-only verification against an actual hs-code-service checkout. */
final class HsCodeReferenceParityTest extends TestCase
{
    private function reference(): string
    {
        $reference = getenv('ULAXYS_HS_CODE_REFERENCE');
        if (! $reference) {
            $this->markTestSkipped('Set ULAXYS_HS_CODE_REFERENCE to a read-only hs-code-service checkout for actual reference parity.');
        }

        return rtrim($reference, '/');
    }

    public function test_reference_runs_this_template_generator_engine_unmodified(): void
    {
        $reference = $this->reference();
        $engine = ['app/Console/Commands/GenerateGateway.php', 'app/Console/Commands/GenerateIntegration.php',
            'app/Console/Commands/GeneratePermissions.php', 'app/Console/Commands/IntegrationCommand.php',
            'app/Providers/IntegrationServiceProvider.php', 'bootstrap/app.php', 'routes/health.php', 'routes/openapi.php', '.docker/verify-openapi.php'];
        foreach ((new Filesystem)->allFiles(app_path('Integration'), true) as $file) {
            $engine[] = 'app/Integration/'.str_replace('\\', '/', $file->getRelativePathname());
        }
        sort($engine);
        foreach ($engine as $path) {
            $this->assertFileExists($reference.'/'.$path);
            $this->assertSame(hash_file('sha256', base_path($path)), hash_file('sha256', $reference.'/'.$path), 'Generator engine diverged: '.$path);
        }
        $this->assertSame($engine, array_values(array_filter($engine, fn ($path) => is_file($reference.'/'.$path))));
        $extra = array_diff(array_map(fn ($file) => 'app/Integration/'.str_replace('\\', '/', $file->getRelativePathname()),
            (new Filesystem)->allFiles($reference.'/app/Integration', true)), $engine);
        $this->assertSame([], array_values($extra), 'Reference has generator files this template does not own.');
    }

    public function test_frozen_catalog_fixture_matches_reference_registry_and_metadata(): void
    {
        $reference = $this->reference();
        $export = new Process([PHP_BINARY, 'tests/Support/Integration/export-catalog.php', $reference], base_path());
        $export->mustRun();
        $live = json_decode($export->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/Integration/hs-code/catalog.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($fixture, $live, 'Reference catalog moved; refresh tests/Fixtures/Integration/hs-code/ per docs/integration-generators.md.');
    }
}
