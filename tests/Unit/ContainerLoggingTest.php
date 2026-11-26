<?php

namespace Ulaxys\Identity\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Ulaxys\Identity\Logging\RedactSecrets;

final class ContainerLoggingTest extends TestCase
{
    public function test_structured_application_errors_redact_credentials_and_keep_diagnostics(): void
    {
        $config = new Repository([
            'app' => ['key' => 'base64:private-key'],
            'services' => ['internal' => ['signature' => 'private-signature']],
            'database' => [
                'connections' => ['pgsql' => ['password' => 'private-password', 'url' => 'postgres://user:url-password@db/service']],
                'redis' => ['default' => ['password' => 'private-redis']],
            ],
        ]);
        $handler = new TestHandler;
        $logger = new Logger(new Monolog('production', [$handler]));
        (new RedactSecrets($config))($logger);
        $logger->error('Operation failed: private-signature private-password private-redis base64:private-key url-password Bearer opaque-credential', [
            'exception' => new RuntimeException('Failed for private-signature'),
            'headers' => ['Authorization' => 'Bearer hidden-header', 'Signature' => 'another-signature', 'Cookie' => 'session-secret'],
            'DB_PASSWORD' => 'another-password',
            'object' => (object) ['SERVICE_SIGNATURE' => 'object-secret'],
            'operation' => 'readiness',
            'attempt' => 3,
        ]);
        $record = $handler->getRecords()[0];
        $json = (new JsonFormatter)->format($record);
        foreach (['private-signature', 'private-password', 'private-redis', 'private-key', 'url-password', 'opaque-credential', 'hidden-header', 'another-signature', 'session-secret', 'another-password', 'object-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame('readiness', $record['context']['operation']);
        $this->assertSame(3, $record['context']['attempt']);
        $this->assertSame(RuntimeException::class, $record['context']['exception']['class']);
        $this->assertArrayHasKey('trace', $record['context']['exception']);
        $this->assertSame('ERROR', json_decode($json, true, 512, JSON_THROW_ON_ERROR)['level_name']);
    }
}
