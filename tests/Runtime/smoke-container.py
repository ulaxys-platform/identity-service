#!/usr/bin/env python3
"""Exercise an already-built final image without publishing ports or writing DB data.

Usage: python3 tests/Runtime/smoke-container.py IMAGE /secure/runtime.env NETWORK [SERVICE_SOURCE_ROOT]
Use a private Docker network and a positively verified disposable PostgreSQL DB.
The env file is supplied only to Docker; credential values are never printed.
"""
import json
import pathlib
import subprocess
import sys
import tempfile
import time
import uuid


def docker(*args, input_text=None, check=True):
    result = subprocess.run(
        ["docker", *args], input=input_text, text=True, capture_output=True
    )
    if check and result.returncode:
        raise RuntimeError("Docker operation failed: " + " ".join(args[:2]))
    return result


def main(image, env_file, network, source_root=None):
    prefix = "service-runtime-" + uuid.uuid4().hex[:10]
    containers = []

    def start(suffix, *extra):
        name = prefix + "-" + suffix
        docker("run", "-d", "--name", name, "--network", network,
               "--env-file", str(pathlib.Path(env_file).resolve()), *extra, image)
        containers.append(name)
        return name

    def php(name, script, check=True):
        return docker("exec", "-i", name, "php", input_text="<?php\n" + script, check=check)

    def request(name, path, signature="none"):
        # Read Signature inside the container; never pass it as a command argument.
        program = r'''
$headers = ['Accept: application/json'];
if (__SIGNATURE_MODE__ === 'valid') { $headers[] = 'Signature: '.getenv('SERVICE_SIGNATURE'); }
if (__SIGNATURE_MODE__ === 'invalid') { $headers[] = 'Signature: invalid'; }
$context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 4, 'header' => implode("\r\n", $headers)]]);
$body = @file_get_contents('http://127.0.0.1:8080'.__REQUEST_PATH__, false, $context);
$status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
echo json_encode(['status' => $status, 'body' => $body]);
'''.replace("__SIGNATURE_MODE__", json.dumps(signature)).replace("__REQUEST_PATH__", json.dumps(path))
        result = php(name, program, check=False)
        try:
            return json.loads(result.stdout)
        except (json.JSONDecodeError, TypeError):
            return {"status": 0, "body": ""}

    def ready(name):
        deadline = time.monotonic() + 30
        while time.monotonic() < deadline:
            if request(name, "/healthCheck/apiStatus")["status"] == 200:
                return
            if docker("inspect", "--format", "{{.State.Running}}", name).stdout.strip() != "true":
                raise RuntimeError("Container exited before liveness succeeded")
            time.sleep(0.5)
        raise RuntimeError("Liveness deadline exceeded")

    try:
        main_container = start("healthy")
        ready(main_container)
        assert docker("exec", main_container, "id", "-u").stdout.strip() != "0"
        php(main_container, r'''
foreach (['.env', '.git', '.github', 'tests', 'docs', '.docker', '/usr/local/bin/composer', '/usr/bin/git'] as $path) {
    if (file_exists($path)) { exit(1); }
}
$installed = json_decode(file_get_contents('vendor/composer/installed.json'), true);
if (($installed['dev'] ?? true) || ($installed['dev-package-names'] ?? []) !== []) { exit(1); }
$lock = json_decode(file_get_contents('composer.lock'), true);
$actual = array_column($installed['packages'], 'version', 'name');
$expected = array_column($lock['packages'], 'version', 'name');
ksort($actual); ksort($expected);
if ($actual !== $expected) { exit(1); }
foreach ($lock['packages'] as $package) {
    if (($actual[$package['name']] ?? null) !== $package['version']) { exit(1); }
}
foreach (['redis', 'pdo_pgsql', 'fileinfo', 'mbstring', 'dom', 'curl', 'Zend OPcache'] as $extension) {
    if (!extension_loaded($extension)) { exit(1); }
}
if (is_writable('app') || is_writable('storage/api-docs/api-docs.json') || !is_writable('bootstrap/cache')) { exit(1); }
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.env') !== 'production' || config('app.debug') || config('logging.default') !== 'stderr') { exit(1); }
if ((fileperms('bootstrap/cache/config.php') & 0077) !== 0) { exit(1); }
''')
        assert docker("exec", main_container, "sh", "-c", "command -v unzip || command -v gcc", check=False).returncode != 0
        for path in ["/openapi.json"]:
            assert request(main_container, path)["status"] == 403
            assert request(main_container, path, "invalid")["status"] == 403
        document = request(main_container, "/openapi.json", "valid")
        assert document["status"] == 200
        spec = json.loads(document["body"])
        assert spec["openapi"].startswith("3.0.")
        assert request(main_container, "/healthCheck/postgresStatus")["status"] == 200
        docker("exec", main_container, "php", "/usr/local/bin/service-runtime-check.php", "--health")
        # docker exec has its own stdout/stderr attachment, outside Docker's
        # container log stream. Exercise Laravel logging through real Nginx/FPM
        # instead, using an isolated container with a test-only mounted route.
        with tempfile.TemporaryDirectory(prefix="service-log-route-") as temp:
            route = pathlib.Path(temp) / "web.php"
            route_source = pathlib.Path(source_root).resolve() if source_root else pathlib.Path(__file__).resolve().parents[2]
            route.write_text((route_source / "routes/health.php").read_text() + r"""

Illuminate\Support\Facades\Route::get('/runtime-log-smoke', function () {
    report(new RuntimeException('service-runtime-smoke-exception '.config('services.internal.signature')));
    return response()->json(['ok' => true]);
});
""")
            route.chmod(0o644)
            logging_container = start("logging", "--mount", f"type=bind,src={route},dst=/app/routes/health.php,readonly")
            ready(logging_container)
            assert request(logging_container, "/runtime-log-smoke")["status"] == 200
            output = docker("logs", logging_container)
            logs = output.stdout + output.stderr
        assert "service-runtime-smoke-exception" in logs
        assert "[REDACTED]" in logs
        # Check known injected credentials against logs without displaying any values.
        for line in pathlib.Path(env_file).read_text().splitlines():
            if "=" in line and line.split("=", 1)[0] in ["APP_KEY", "SERVICE_SIGNATURE", "DB_PASSWORD"]:
                secret = line.split("=", 1)[1]
                assert not secret or secret not in logs
        php(main_container, r'''
$size = filesize('storage/api-docs/api-docs.json');
$mtime = filemtime('storage/api-docs/api-docs.json');
file_put_contents('/tmp/openapi-state', json_encode([$size, $mtime]));
''')
        assert request(main_container, "/openapi.json", "valid")["status"] == 200
        php(main_container, r'''
if (json_decode(file_get_contents('/tmp/openapi-state'), true) !== [filesize('storage/api-docs/api-docs.json'), filemtime('storage/api-docs/api-docs.json')]) { exit(1); }
''')
        failing_db = start("database-unavailable", "-e", "DATABASE_URL=", "-e", "DB_HOST=127.0.0.1", "-e", "DB_PORT=1")
        ready(failing_db)
        assert request(failing_db, "/healthCheck/postgresStatus")["status"] == 503
        unsafe = start("debug-refused", "-e", "APP_DEBUG=true")
        assert docker("wait", unsafe).stdout.strip() == "1"
        with tempfile.TemporaryDirectory(prefix="service-nginx-invalid-") as temp:
            config = pathlib.Path(temp) / "nginx.conf"
            config.write_text("invalid_service_directive;\n")
            config.chmod(0o644)
            broken = start("required-process-failure", "--mount", f"type=bind,src={config},dst=/etc/nginx/nginx.conf,readonly")
            waited = subprocess.run(["docker", "wait", broken], capture_output=True, text=True, timeout=30, check=True)
            assert waited.stdout.strip() == "1"
        docker("stop", "--time", "170", main_container)
        state = json.loads(docker("inspect", "--format", "{{json .State}}", main_container).stdout)
        assert state["ExitCode"] == 0 and not state["OOMKilled"]
        print("PASS: final-image runtime, dependency isolation, Signature, probes, OpenAPI, logs, fatal-child exit and graceful shutdown")
    finally:
        for name in containers:
            docker("rm", "-f", name, check=False)


if __name__ == "__main__":
    if len(sys.argv) not in (4, 5):
        sys.exit(__doc__)
    main(*sys.argv[1:])
