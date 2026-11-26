<?php

namespace Ulaxys\Identity\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RuntimeConfigurationTest extends TestCase
{
    public function test_production_checker_rejects_missing_secrets_and_debug(): void
    {
        foreach ([['APP_DEBUG' => 'true'], ['APP_DEBUG' => 'false', 'APP_KEY' => ''], ['APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'SERVICE_SIGNATURE' => '']] as $env) {
            $process = new Process([PHP_BINARY, '.docker/runtime-check.php'], dirname(__DIR__, 2), array_merge(['APP_ENV' => 'production', 'LOG_CHANNEL' => 'stderr'], $env));
            $process->run();
            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('startup refused', $process->getErrorOutput());
            if (! empty($env['APP_KEY'])) {
                $this->assertStringNotContainsString($env['APP_KEY'], $process->getErrorOutput());
            }
        }
    }
}
