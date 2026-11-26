<?php

namespace Ulaxys\Identity\Tests;

use Symfony\Component\Process\Process;

abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    /** Fresh-process HTTP registry (`route:list --json`), independent of routes a test registers. */
    protected function runtimeRoutes(): array
    {
        $process = new Process([PHP_BINARY, 'artisan', 'route:list', '--json'], base_path());
        $process->mustRun();

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Operations the OpenAPI release gate must find mounted: every method except Laravel's
     * implicit HEAD, without the document endpoint. Derived, so service routes need no test edits.
     */
    protected function mountedOperationCount(): int
    {
        $count = 0;
        foreach ($this->runtimeRoutes() as $route) {
            if ($route['uri'] !== 'openapi.json') {
                $methods = explode('|', $route['method']);
                $count += count(array_diff($methods, in_array('GET', $methods, true) ? ['HEAD'] : []));
            }
        }

        return $count;
    }
}
