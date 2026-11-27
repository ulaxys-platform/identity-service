<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Ulaxys\Identity\Tests\TestCase;

/**
 * Opt-in read-only verification against an actual hs-code-service checkout.
 *
 * Template-defect adjustments (docs/template-defects.md):
 * 1. Byte parity hashes namespace-normalized content. The generator
 *    legitimately rewrites the root namespace per consumer service
 *    (composer.json maps Ulaxys\Identity\ to app/), so raw byte hashing of
 *    namespace-bearing engine files can never match across services.
 * 2. The frozen catalog fixture test skips with the exact blocker when the
 *    fixture is absent from this checkout (excluded during git-archive
 *    generation) instead of failing on a missing file; the strict comparison
 *    is preserved when the fixture exists. The fixture contents are never
 *    invented here.
 */
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

    /** Hash content with the generated service namespace normalized away. */
    private function normalizedHash(string $path): string
    {
        $content = (string) file_get_contents($path);
        // Namespace generation also reorders the top-level imports. Keep the
        // complete import set, aliases and all other source bytes in the comparison.
        $content = preg_replace('/^(namespace|use) (?:App|Ulaxys\\\\Identity)\\\\/m', '$1 {service}\\', $content);
        $content = preg_replace_callback('/(?:^use [^\n]+;\n)+/m', static function (array $matches): string {
            $imports = explode("\n", rtrim($matches[0], "\n"));
            sort($imports, SORT_STRING);

            return implode("\n", $imports)."\n";
        }, $content);

        return hash('sha256', $content);
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
            $this->assertSame($this->normalizedHash(base_path($path)), $this->normalizedHash($reference.'/'.$path), 'Generator engine diverged (namespace-normalized comparison): '.$path);
        }
        $this->assertSame($engine, array_values(array_filter($engine, fn ($path) => is_file($reference.'/'.$path))));
        $extra = array_diff(array_map(fn ($file) => 'app/Integration/'.str_replace('\\', '/', $file->getRelativePathname()),
            (new Filesystem)->allFiles($reference.'/app/Integration', true)), $engine);
        $this->assertSame([], array_values($extra), 'Reference has generator files this template does not own.');
    }

    public function test_frozen_catalog_fixture_matches_reference_registry_and_metadata(): void
    {
        $fixture = base_path('tests/Fixtures/Integration/hs-code/catalog.json');
        if (! is_file($fixture)) {
            $this->markTestSkipped('Frozen hs-code catalog fixture is absent from this checkout (excluded by the git-archive generation snapshot; see docs/template-defects.md). Blocker: regenerate it by running tests/Support/Integration/export-catalog.php against a read-only hs-code-service checkout and commit the result; the fixture contents are not invented here.');
        }
        $reference = $this->reference();
        $export = new Process([PHP_BINARY, 'tests/Support/Integration/export-catalog.php', $reference], base_path());
        $export->mustRun();
        $live = json_decode($export->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $fixtureData = json_decode((string) file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($fixtureData, $live, 'Reference catalog moved; refresh tests/Fixtures/Integration/hs-code/ per docs/integration-generators.md.');
    }
}
