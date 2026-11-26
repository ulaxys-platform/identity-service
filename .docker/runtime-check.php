<?php

use Illuminate\Encryption\Encrypter;

// Runs before Laravel bootstrap so stale cached configuration cannot bypass guards.
$root = getcwd();
require $root.'/vendor/autoload.php';

if (($argv[1] ?? null) === '--health') {
    $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $body = @file_get_contents('http://127.0.0.1:8080/healthCheck/apiStatus', false, $context);
    $response = is_string($body) ? json_decode($body, true) : null;
    exit(($response['status'] ?? null) === 200 ? 0 : 1);
}

$fail = static function (string $message): never {
    fwrite(STDERR, 'Service startup refused: '.$message.PHP_EOL);
    exit(1);
};

if (getenv('APP_ENV') !== 'production' || ! in_array(getenv('APP_DEBUG'), ['false', '0'], true)) {
    $fail('APP_ENV must be production and APP_DEBUG must be false or 0.');
}
if (getenv('LOG_CHANNEL') !== 'stderr') {
    $fail('LOG_CHANNEL must be stderr in this production container.');
}
$key = (string) getenv('APP_KEY');
$key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
if (! is_string($key) || ! Encrypter::supported($key, 'AES-256-CBC')) {
    $fail('inject a valid AES-256-CBC APP_KEY; keys are never generated at startup.');
}
if (trim((string) getenv('SERVICE_SIGNATURE')) === '') {
    $fail('inject a nonempty SERVICE_SIGNATURE.');
}
if (getenv('DB_CONNECTION') !== 'pgsql') {
    $fail('DB_CONNECTION must be pgsql.');
}
if (getenv('DATABASE_URL')) {
    $scheme = parse_url(getenv('DATABASE_URL'), PHP_URL_SCHEME);
    if (! in_array($scheme, ['postgres', 'postgresql', 'pgsql'], true)) {
        $fail('DATABASE_URL must identify PostgreSQL.');
    }
} else {
    foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME'] as $name) {
        if (trim((string) getenv($name)) === '') {
            $fail('inject '.$name.' or a PostgreSQL DATABASE_URL.');
        }
    }
}
foreach (['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $path) {
    if (! is_dir($root.'/'.$path) || ! is_writable($root.'/'.$path)) {
        $fail($path.' must be writable by the container user.');
    }
}
if (! is_readable($root.'/storage/api-docs/api-docs.json')) {
    $fail('the mandatory build-generated OpenAPI artifact is missing.');
}
