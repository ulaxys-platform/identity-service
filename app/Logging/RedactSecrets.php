<?php

namespace Ulaxys\Identity\Logging;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Throwable;

/** Container log hygiene: preserve diagnostics while removing credentials. */
final class RedactSecrets
{
    public function __construct(private Repository $config) {}

    public function __invoke(Logger $logger): void
    {
        $secrets = array_filter([
            $this->config->get('app.key'),
            $this->config->get('services.internal.signature'),
            $this->config->get('database.connections.pgsql.password'),
            $this->config->get('database.redis.default.password'),
        ], fn ($value) => is_string($value) && $value !== '');
        foreach (['database.connections.pgsql.url', 'database.redis.default.url', 'database.redis.cache.url'] as $urlKey) {
            $url = $this->config->get($urlKey);
            if (is_string($url) && $url !== '') {
                $secrets[] = $url;
                $password = parse_url($url, PHP_URL_PASS);
                if (is_string($password) && $password !== '') {
                    $secrets[] = rawurldecode($password);
                }
            }
        }
        // Replace longer secrets first, including when one contains another.
        usort($secrets, fn ($left, $right) => strlen($right) <=> strlen($left));
        $redact = function (mixed $value) use (&$redact, $secrets): mixed {
            if ($value instanceof Throwable) {
                return $redact([
                    'class' => $value::class,
                    'message' => $value->getMessage(),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                    'trace' => $value->getTraceAsString(),
                    'previous' => $value->getPrevious(),
                ]);
            }
            if (is_object($value)) {
                // Never serialize arbitrary request/connection objects into logs.
                return ['class' => $value::class];
            }
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $sensitive = is_string($key) && preg_match('/authorization|signature|password|passwd|secret|token|cookie|app[_-]?key/i', $key);
                    $value[$key] = $sensitive ? '[REDACTED]' : $redact($item);
                }
            } elseif (is_string($value)) {
                $value = str_replace($secrets, '[REDACTED]', $value);
                $value = preg_replace('/\b(Bearer|Basic)\s+[^\s,;]+/i', '$1 [REDACTED]', $value);
            }

            return $value;
        };
        $logger->pushProcessor(static function (LogRecord $record) use ($redact): LogRecord {
            return $record->with(
                message: $redact($record->message),
                context: $redact($record->context),
                extra: $redact($record->extra),
            );
        });
    }
}
