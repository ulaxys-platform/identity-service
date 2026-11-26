<?php

declare(strict_types=1);

namespace Ulaxys\Identity\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class NewServicePreflightTest extends TestCase
{
    private string $fixtureDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDirectory = sys_get_temp_dir().'/ulaxys-onboarding-'.bin2hex(random_bytes(8));
        mkdir($this->fixtureDirectory.'/config', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (['composer.json', '.env.example', 'config/integration.php'] as $file) {
            if (is_file($this->fixtureDirectory.'/'.$file)) {
                unlink($this->fixtureDirectory.'/'.$file);
            }
        }
        rmdir($this->fixtureDirectory.'/config');
        rmdir($this->fixtureDirectory);
        parent::tearDown();
    }

    private function setupFiles(bool $customized): void
    {
        file_put_contents($this->fixtureDirectory.'/composer.json', json_encode([
            'name' => $customized ? 'ulaxys/example-catalog' : 'ulaxys/php-template-service',
        ], JSON_THROW_ON_ERROR));
        $metadata = [
            'service' => $customized ? 'example-catalog' : 'service',
            'gateway' => [
                'mainGate' => ['prefix' => $customized ? 'services/example-catalog' : 'services/service'],
                'serviceGateway' => ['prefix' => $customized ? 'example-catalog' : 'service'],
            ],
        ];
        file_put_contents($this->fixtureDirectory.'/config/integration.php', '<?php return '.var_export($metadata, true).';');
        file_put_contents($this->fixtureDirectory.'/.env.example', $customized
            ? "APP_NAME=\"Example Catalog\"\nREDIS_PREFIX=example_catalog_\n"
            : "APP_NAME=\"uLaxys Service\"\nREDIS_PREFIX=service_\n");
    }

    private function check(): Process
    {
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 2).'/scripts/check-new-service.php',
            '--root='.$this->fixtureDirectory,
        ]);
        $process->run();

        return $process;
    }

    public function test_unmodified_template_identity_fails_without_leaking_values(): void
    {
        $this->setupFiles(false);
        $process = $this->check();
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('Composer package name', $process->getErrorOutput());
        self::assertStringContainsString('Integration service identifier', $process->getErrorOutput());
        self::assertStringContainsString('MainGate prefix', $process->getErrorOutput());
        self::assertStringContainsString('Service Gateway metadata prefix', $process->getErrorOutput());
        self::assertStringContainsString('APP_NAME', $process->getErrorOutput());
        self::assertStringContainsString('REDIS_PREFIX', $process->getErrorOutput());
        self::assertStringNotContainsString('example-catalog', $process->getErrorOutput());
    }

    public function test_custom_service_identifiers_pass_without_writes(): void
    {
        $this->setupFiles(true);
        $before = hash_file('sha256', $this->fixtureDirectory.'/config/integration.php');
        $process = $this->check();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('preflight passed', $process->getOutput());
        self::assertSame($before, hash_file('sha256', $this->fixtureDirectory.'/config/integration.php'));
    }

    public function test_missing_inputs_fail_closed(): void
    {
        $process = $this->check();
        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('preflight failed', $process->getErrorOutput());
    }
}
