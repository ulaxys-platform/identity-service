#!/usr/bin/env python3
"""Run repository-local CI checks without touching a running service or database."""
from __future__ import annotations

import argparse
import hashlib
import os
import re
from pathlib import Path
import secrets
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parent.parent
KEY = hashlib.sha256(str(ROOT).encode()).hexdigest()[:12]
DEV_IMAGE = f"ulaxys-php-tools:{KEY}"
VENDOR_VOLUME = "ulaxys-php-vendor-" + KEY
NPM_CACHE_VOLUME = "ulaxys-prettier-cache-v1"
NODE_IMAGE = "node:22-bookworm-slim"
PRETTIER_VERSION = "3.6.2"
POSTGRES_IMAGE = "postgres:17@sha256:b8faeff7712663c2773a190181e1246e37e364b887611c04658c28b44e2408fe"


VERBOSE = False
_ACTIVE_STAGE: str | None = None


def call(*argv: str, check: bool = True, quiet: bool = False) -> subprocess.CompletedProcess:
    """Capture normal output; show complete diagnostics on failure or in verbose mode."""
    env = {**os.environ, "PYTHONDONTWRITEBYTECODE": "1"}
    result = subprocess.run(argv, cwd=ROOT, check=False, env=env, capture_output=True,
                            text=True, encoding="utf-8", errors="replace")
    if VERBOSE and not quiet:
        if result.stdout:
            print(result.stdout, end="", flush=True)
        if result.stderr:
            print(result.stderr, end="", file=sys.stderr, flush=True)
    if result.returncode and check:
        finish_stage(failed=True)
        if not VERBOSE:
            if result.stdout:
                print(result.stdout.rstrip(), file=sys.stderr, flush=True)
            if result.stderr:
                print(result.stderr.rstrip(), file=sys.stderr, flush=True)
        raise subprocess.CalledProcessError(result.returncode, argv)
    return result


def stage(name: str) -> None:
    global _ACTIVE_STAGE
    finish_stage()
    _ACTIVE_STAGE = name
    # A single fixed-width status column. Long labels wrap rather than
    # letting PASS/FAIL run into the description on narrow terminals.
    if len(name) > 58:
        print(f"  {name}", flush=True)
        print("  " + " " * 58, end="", flush=True)
    else:
        print(f"  {name:<58}", end="", flush=True)


def finish_stage(failed: bool = False) -> None:
    global _ACTIVE_STAGE
    if _ACTIVE_STAGE is not None:
        print("FAIL" if failed else "PASS", flush=True)
        _ACTIVE_STAGE = None


def test_count(result: subprocess.CompletedProcess) -> int:
    output = (result.stderr or "") + (result.stdout or "")
    match = re.search(r"Ran (\d+) tests? in ", output)
    return int(match.group(1)) if match else 0


def php(*args: str, network: str | None = None, env_file: Path | None = None) -> None:
    command = [
        "docker", "run", "--rm", "--mount", f"type=bind,source={ROOT},target=/app",
        "-v", f"{VENDOR_VOLUME}:/app/vendor", "-w", "/app",
        "-e", "HOME=/tmp", "-e", "COMPOSER_HOME=/tmp/composer",
    ]
    if network:
        command += ["--network", network]
    if env_file:
        command += ["--env-file", str(env_file)]
    call(*(command + [DEV_IMAGE, *args]))


def prettier_check() -> None:
    call("docker", "run", "--rm", "--mount", f"type=bind,source={ROOT},target=/app",
         "-v", f"{NPM_CACHE_VOLUME}:/root/.npm", "-w", "/app",
         NODE_IMAGE, "npm", "exec", "--yes",
         f"--package=prettier@{PRETTIER_VERSION}", "--",
         "prettier", "--check", ".")


def safe_preflight() -> None:
    if (ROOT / "bootstrap/cache/config.php").exists():
        raise RuntimeError("Cached Laravel config detected. Refusing database verification; remove bootstrap/cache/config.php after reviewing it.")
    if not (ROOT / "composer.lock").is_file():
        raise RuntimeError("Missing composer.lock")
    php("sh", "-ec", "test -x vendor/bin/phpunit && test -x vendor/bin/pint || { echo 'Run tools install first.' >&2; exit 2; }")


def with_disposable_database() -> None:
    # Unique, unexposed PostgreSQL on an internal Docker network; never use
    # the machine's existing DB connection or service names.
    suffix = secrets.token_hex(6)
    name = f"ulaxys-verify-pg-{suffix}"
    network = f"ulaxys-verify-net-{suffix}"
    password = secrets.token_urlsafe(36)
    network_created = False
    container_created = False
    try:
        with tempfile.TemporaryDirectory(prefix="ulaxys-verify-") as directory:
            root = Path(directory)
            postgres_env = root / "postgres.env"
            app_env = root / "testing.env"
            postgres_env.write_text(
                f"POSTGRES_DB=service_test\nPOSTGRES_USER=service_test\nPOSTGRES_PASSWORD={password}\n",
                encoding="utf-8",
            )
            app_env.write_text(
                "APP_ENV=testing\nSERVICE_ALLOW_DESTRUCTIVE_TESTS=true\n"
                "DB_CONNECTION=pgsql\n"
                f"DB_HOST={name}\nDB_PORT=5432\nDB_DATABASE=service_test\n"
                f"DB_USERNAME=service_test\nDB_PASSWORD={password}\nDB_SSLMODE=disable\n",
                encoding="utf-8",
            )
            os.chmod(postgres_env, 0o600)
            os.chmod(app_env, 0o600)
            call("docker", "network", "create", "--internal", network, quiet=True)
            network_created = True
            call("docker", "run", "-d", "--name", name, "--network", network,
                 "--env-file", str(postgres_env), POSTGRES_IMAGE, quiet=True)
            container_created = True
            for _ in range(45):
                ready = call("docker", "exec", name, "pg_isready", "-U",
                             "service_test", "-d", "service_test", check=False, quiet=True)
                if ready.returncode == 0:
                    break
                time.sleep(1)
            else:
                raise RuntimeError("Disposable PostgreSQL failed readiness check")
            php("php", "vendor/bin/phpunit", "--testsuite", "Database",
                network=network, env_file=app_env)
    finally:
        if container_created:
            call("docker", "rm", "-f", name, check=False, quiet=True)
        if network_created:
            call("docker", "network", "rm", network, check=False, quiet=True)



def run_generator_tests(tooling_only: bool = False) -> int:
    """Run template-only regression suites; count tests without leaking test logs."""
    source = ROOT / "tests/Generator"
    if not source.is_dir():
        raise RuntimeError("Template generator tests are unavailable in this service")
    patterns = ("test_developer_tooling.py", "test_local_verification.py", "test_cache_tooling.py", "test_acceptance_tooling.py") if tooling_only else ("test_*.py",)
    total = 0
    for pattern in patterns:
        if "*" not in pattern and not (source / pattern).is_file():
            raise RuntimeError(f"Missing tooling regression: {pattern}")
        result = call(sys.executable, "-m", "unittest", "discover", "-s",
                      "tests/Generator", "-p", pattern)
        total += test_count(result)
    return total


def run_tests(suite: str) -> None:
    print(f"uLaxys  /  TEST  /  {suite.upper()}\n", flush=True)
    count = 0
    if suite in ("generator", "tooling"):
        stage(f"{suite.capitalize()} regression tests")
        count = run_generator_tests(tooling_only=(suite == "tooling"))
        finish_stage()
        print(f"\n  PASS  {count} tests passed", flush=True)
        return
    if suite == "all" and (ROOT / "scripts/generate-service.py").exists():
        stage("Generator and tooling regression tests")
        count += run_generator_tests()
    stage("PHP test preflight")
    safe_preflight()
    if suite in ("all", "foundation"):
        stage("PHPUnit Foundation")
        php("php", "vendor/bin/phpunit", "--testsuite", "Foundation")
    if suite in ("all", "database"):
        stage("PHPUnit Database (isolated PostgreSQL)")
        with_disposable_database()
    finish_stage()
    print(f"\n  PASS  {suite} tests complete"
          + (f" ({count} Python regressions)" if count else ""), flush=True)


def verify(skip_database: bool = False) -> None:
    print("uLaxys  /  VERIFY  /  LOCAL\n", flush=True)
    stage("Preflight (dependencies, cached configuration)")
    safe_preflight()
    stage("Python generator/tooling unit tests (template only)")
    if (ROOT / "scripts/generate-service.py").exists():
        run_generator_tests()
    stage("Composer validate, audit and platform requirements")
    php("composer", "validate", "--strict")
    php("composer", "audit", "--locked")
    php("composer", "check-platform-reqs")
    stage("PHP syntax")
    php("sh", "-ec", "find app bootstrap config routes database tests public scripts .docker -name '*.php' -print0 | xargs -0 -r -n1 php -l > /dev/null")
    stage("Laravel Pint")
    php("php", "vendor/bin/pint", "--test")
    stage("Prettier")
    prettier_check()
    stage("PHPUnit Foundation")
    php("php", "vendor/bin/phpunit", "--testsuite", "Foundation")
    if skip_database:
        stage("Database tests SKIPPED by explicit --skip-database (not full CI parity)")
    else:
        stage("PHPUnit Database (isolated PostgreSQL)")
        with_disposable_database()
    stage("OpenAPI release gate")
    php("composer", "openapi")
    stage("Integration generator dry-runs")
    php("php", "artisan", "ulaxys:generate-integration", "--all", "--strict", "--dry-run")
    php("php", "artisan", "ulaxys:generate-deploy", "--strict", "--dry-run")
    finish_stage()
    print("\n  PASS  Local source checks complete (production smoke is separate)", flush=True)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--verbose", action="store_true", help="Show raw test and tool output")
    parser.add_argument("--tests", choices=("all", "generator", "tooling", "foundation", "database"),
                        help="Run only the selected test suite, skipping lint and OpenAPI")
    parser.add_argument("--skip-database", action="store_true",
                        help="Skip disposable DB tests (reduced acceptance, not full CI parity)")
    args = parser.parse_args(argv)
    if args.tests and args.skip_database:
        parser.error("--skip-database is supported only by verify")
    global VERBOSE
    VERBOSE = args.verbose
    try:
        if args.tests:
            run_tests(args.tests)
        else:
            verify(skip_database=args.skip_database)
        return 0
    except subprocess.CalledProcessError as exc:
        finish_stage(failed=True)
        print(f"  FAIL  {exc.cmd[0]} exited {exc.returncode}", file=sys.stderr)
        return exc.returncode or 1
    except (OSError, RuntimeError) as exc:
        finish_stage(failed=True)
        print(f"  FAIL  {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
