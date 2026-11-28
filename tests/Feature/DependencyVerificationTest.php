<?php

namespace Ulaxys\Identity\Tests\Feature;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Ulaxys\Identity\Contracts\DependencyProbe;
use Ulaxys\Identity\Support\DependencyVerification;
use Ulaxys\Identity\Tests\TestCase;

final class DependencyVerificationTest extends TestCase
{
    public function test_declared_checks_use_consumer_probe_and_never_contact_unrelated_services(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://provider.invalid/*' => Http::response(['version' => 1], 200)]);
        $probe = new class implements DependencyProbe
        {
            public function check(string $check): bool
            {
                $response = Http::withToken('fixture-token')->timeout(1)->get('https://provider.invalid/'.$check);

                return $response->successful() && $response->json('version') === 1;
            }
        };
        $this->app->instance($probe::class, $probe);
        $config = ['provider' => ['probe' => $probe::class, 'required' => true, 'checks' => ['authentication', 'authorization', 'contract']]];
        $lines = [];
        $output = static function (string $line) use (&$lines): void {
            $lines[] = $line;
        };
        $runner = new DependencyVerification;
        $this->assertSame(0, $runner->run($this->app, $config, 'list', $output));
        Http::assertNothingSent();
        $this->assertSame(0, $runner->run($this->app, $config, 'test', $output));
        Http::assertSentCount(3);
        $this->assertStringNotContainsString('fixture-token', implode('', $lines));
    }

    public function test_failed_checks_do_not_stop_remaining_checks_or_claim_acceptance(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://provider.invalid/authentication' => Http::response([], 401),
            'https://provider.invalid/authorization' => Http::response([], 403),
            'https://provider.invalid/contract' => Http::response(['version' => 2], 200),
            'https://provider.invalid/health' => Http::response([], 503),
            'https://provider.invalid/capability' => Http::response(['version' => 1], 200),
        ]);
        $probe = new class implements DependencyProbe
        {
            public function check(string $check): bool
            {
                $response = Http::timeout(1)->get('https://provider.invalid/'.$check);

                return $response->successful() && $response->json('version') === 1;
            }
        };
        $this->app->instance($probe::class, $probe);
        $lines = [];
        $exit = (new DependencyVerification)->run($this->app, [
            'provider' => ['probe' => $probe::class, 'required' => false, 'checks' => ['authentication', 'authorization', 'contract', 'health', 'capability']],
        ], 'test', static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->assertSame(1, $exit);
        Http::assertSentCount(5);
        $events = array_filter($lines, fn ($line) => str_starts_with($line, 'EVIDENCE test '));
        $this->assertCount(5, $events);
        $this->assertSame(['FAIL', 'FAIL', 'FAIL', 'FAIL', 'PASS'], array_values(array_map(
            fn ($line) => json_decode(substr($line, 14), true, 512, JSON_THROW_ON_ERROR)['status'], $events)));
    }

    public function test_invalid_configuration_is_rejected_before_any_probe_runs(): void
    {
        Http::fake();
        $this->expectException(RuntimeException::class);
        try {
            (new DependencyVerification)->run($this->app, [
                'provider' => ['probe' => 'not-a-probe', 'required' => true, 'checks' => ['health']],
            ], 'test', static function (string $line): void {});
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_empty_configuration_cannot_claim_runtime_acceptance(): void
    {
        $runner = new DependencyVerification;
        $output = static function (string $line): void {};
        $this->assertSame(0, $runner->run($this->app, [], 'list', $output));
        $this->assertSame(2, $runner->run($this->app, [], 'test', $output));
    }
}
