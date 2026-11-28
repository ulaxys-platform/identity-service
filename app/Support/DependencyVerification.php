<?php

namespace Ulaxys\Identity\Support;

use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Throwable;
use Ulaxys\Identity\Contracts\DependencyProbe;

final class DependencyVerification
{
    public function run(Container $app, mixed $dependencies, string $mode, callable $output): int
    {
        if (is_array($dependencies) === false || in_array($mode, ['list', 'status', 'test'], true) === false) {
            throw new RuntimeException('Invalid dependency configuration or mode.');
        }
        $checks = ['connectivity', 'health', 'authentication', 'authorization', 'contract', 'capability', 'integration'];
        foreach ($dependencies as $id => $entry) {
            if (is_string($id) === false || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $id) !== 1
                || is_array($entry) === false || isset($entry['probe'], $entry['checks'], $entry['required']) === false
                || is_string($entry['probe']) === false || is_bool($entry['required']) === false
                || is_array($entry['checks']) === false || $entry['checks'] === []
                || array_filter($entry['checks'], fn ($check) => is_string($check) === false || in_array($check, $checks, true) === false)
                || count(array_unique($entry['checks'])) !== count($entry['checks'])
                || is_a($entry['probe'], DependencyProbe::class, true) === false) {
                throw new RuntimeException('Invalid dependency declaration.');
            }
        }
        if ($dependencies === []) {
            $output("NO DEPENDENCIES DECLARED — no runtime acceptance claimed.\n");

            return $mode === 'test' ? 2 : 0;
        }
        $failed = false;
        foreach ($dependencies as $id => $entry) {
            if ($mode === 'list') {
                $output($id.' | '.($entry['required'] ? 'required' : 'optional').' | '.implode(', ', $entry['checks']).PHP_EOL);

                continue;
            }
            foreach ($entry['checks'] as $check) {
                $started = hrtime(true);
                try {
                    $passed = $app->make($entry['probe'])->check($check) === true;
                } catch (Throwable) {
                    $passed = false;
                }
                $output($id.' | '.$check.' | '.($passed ? 'PASS' : 'FAIL').PHP_EOL);
                if ($mode === 'test') {
                    $output('EVIDENCE test '.json_encode([
                        'suite' => 'Dependencies',
                        'name' => $id.'::'.$check,
                        'status' => $passed ? 'PASS' : 'FAIL',
                        'durationSeconds' => (hrtime(true) - $started) / 1e9,
                        'skipReason' => null,
                    ], JSON_THROW_ON_ERROR).PHP_EOL);
                }
                // Optional failures still prevent an unqualified acceptance PASS.
                $failed = $failed || $passed === false;
            }
        }

        return $failed ? 1 : 0;
    }
}
