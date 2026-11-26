<?php

declare(strict_types=1);

/**
 * Read-only, opt-in preflight for a repository created from this template.
 * No Laravel bootstrap, database access, secret inspection or file writes.
 */
$root = dirname(__DIR__);
foreach (array_slice($argv, 1) as $argument) {
    if (! str_starts_with($argument, '--root=')) {
        fwrite(STDERR, "Usage: php scripts/check-new-service.php [--root=path]\n");
        exit(2);
    }

    $root = substr($argument, strlen('--root='));
}

$root = realpath($root);
if ($root === false || ! is_dir($root)) {
    fwrite(STDERR, "FAIL: project root does not exist.\n");
    exit(2);
}

$failures = [];
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (! $condition) {
        $failures[] = $label;
    }
};

$composerPath = $root.'/composer.json';
$composer = is_file($composerPath) ? json_decode((string) file_get_contents($composerPath), true) : null;
$name = is_array($composer) ? ($composer['name'] ?? null) : null;
$check(is_string($name) && preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/D', $name) === 1 && $name !== 'ulaxys/php-template-service', 'Composer package name must be service-specific.');

$integrationPath = $root.'/config/integration.php';
$integration = is_file($integrationPath) ? require $integrationPath : null;
$service = is_array($integration) ? ($integration['service'] ?? null) : null;
$mainGate = is_array($integration) ? ($integration['gateway']['mainGate']['prefix'] ?? null) : null;
$serviceGateway = is_array($integration) ? ($integration['gateway']['serviceGateway']['prefix'] ?? null) : null;
$check(is_string($service) && preg_match('/^[a-z][a-z0-9_-]*$/D', $service) === 1 && $service !== 'service', 'Integration service identifier must be service-specific.');
$check(is_string($mainGate) && $mainGate !== 'services/service' && str_starts_with($mainGate, 'services/') && strlen($mainGate) > strlen('services/'), 'MainGate prefix must be configured for this service.');
$check(is_string($serviceGateway) && $serviceGateway !== 'service' && $serviceGateway !== '', 'Service Gateway metadata prefix must be service-specific (even when unused).');

$examplePath = $root.'/.env.example';
$example = is_file($examplePath) ? (string) file_get_contents($examplePath) : '';
$readExample = static function (string $key) use ($example): ?string {
    if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $example, $matches) !== 1) {
        return null;
    }

    return trim(trim($matches[1]), "\"'");
};
$appName = $readExample('APP_NAME');
$redisPrefix = $readExample('REDIS_PREFIX');
$check($appName !== null && $appName !== '' && $appName !== 'uLaxys Service', '.env.example APP_NAME must identify the new service.');
$check($redisPrefix !== null && $redisPrefix !== '' && $redisPrefix !== 'service_', '.env.example REDIS_PREFIX must be unique when Redis is enabled.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    fwrite(STDERR, "Service identity preflight failed. No files changed.\n");
    exit(1);
}

echo "Service identity preflight passed (tracked identifiers only).\n";
echo "Still verify runtime secrets, PostgreSQL, OpenAPI, real routes and external MainGate/Identity installation separately.\n";
