<?php

namespace Ulaxys\Identity\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ulaxys\Identity\Integration\DeployPackageRenderer;
use Ulaxys\Identity\Integration\IntegrationException;
use Ulaxys\Identity\Integration\NativeDeployFile;

final class DeployPackageRendererTest extends TestCase
{
    public function test_deterministic_review_only_package_for_test_service(): void
    {
        $renderer = new DeployPackageRenderer;
        $files = $renderer->generate([], ['service' => 'test']);
        $this->assertEquals($files, $renderer->generate([], ['service' => 'test']));
        $this->assertCount(13, $files);
        $this->assertFalse($files['deploy/compatibility.json']['productionReady']);
        $this->assertFalse($files['deploy/compatibility.json']['automaticallyInstalled']);
        $this->assertFalse($files['deploy/compatibility.json']['templatePlaceholder']);
        $this->assertSame('service.json', $files['deploy/patches/service-catalog.json']['targetPath']);
        $catalog = $files['deploy/patches/service-catalog.json']['patch']['services'][0];
        $this->assertSame('test-service', $catalog['name']);
        $this->assertSame(['postgres'], $catalog['dependencies']);
        $this->assertSame('manual-review-merge', $files['deploy/patches/service-catalog.json']['mode']);

        $local = json_decode($files['deploy/compose/local.service.yaml']->contents, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['$'.'{TEST_SERVICE_BIND_IP}:$'.'{TEST_SERVICE_PORT}:8080'],
            $local['services']['test-service']['ports']);
        $this->assertSame('service_healthy', $local['services']['test-service']['depends_on']['postgres']['condition']);
        $this->assertSame(['platform'], $local['services']['test-service']['networks']);

        $production = json_decode($files['deploy/compose/production.service.yaml']->contents, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['$'.'{ULAXYS_BIND_IP}:$'.'{ULAXYS_PORT}:8080'], $production['services']['test-service']['ports']);
        $this->assertNull($files['deploy/patches/production-topology.json']['patch']['target']);
        $this->assertSame([], $files['deploy/patches/operator-capabilities.json']['patch']['installableRegistryEntries']);
        $this->assertSame(['APP_KEY', 'DB_PASSWORD', 'SERVICE_SIGNATURE'],
            json_decode($files['deploy/environments/secrets.required.json']->contents, true, 512, JSON_THROW_ON_ERROR)['required']);
        $this->assertStringContainsString('manually transfer', $files['deploy/README.md']->contents);
    }

    public function test_placeholder_and_invalid_native_paths_fail_closed(): void
    {
        $files = (new DeployPackageRenderer)->generate([], ['service' => 'service']);
        $this->assertTrue($files['deploy/compatibility.json']['templatePlaceholder']);
        $this->assertFalse($files['deploy/compatibility.json']['productionReady']);

        $this->expectException(IntegrationException::class);
        NativeDeployFile::fromRenderer('deploy/../../config/database.php', 'bad');
    }

    public function test_unsafe_identity_is_rejected(): void
    {
        $this->expectException(IntegrationException::class);
        (new DeployPackageRenderer)->generate([], ['service' => '../bad']);
    }
}
