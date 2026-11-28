#!/usr/bin/env python3
"""Run repository-local CI checks without touching a running service or database."""
from __future__ import annotations

import argparse
import hashlib
import json
import math
import xml.etree.ElementTree as ET
import os
import re
from pathlib import Path
import secrets
import subprocess
import sys
import tempfile
import tarfile
import shutil
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
_STAGE_SKIPS = 0
_STAGE_NOT_RUN = False


def call(*argv: str, check: bool = True, quiet: bool = False) -> subprocess.CompletedProcess:
    """Capture normal output; show complete diagnostics on failure or in verbose mode."""
    env = {**os.environ, "PYTHONDONTWRITEBYTECODE": "1", "PYTHONIOENCODING": "utf-8:backslashreplace", "PYTHONUTF8": "1"}
    if len(argv) > 1 and argv[0] == sys.executable and (argv[1] == "-m" or argv[1] == "tests/Tooling/run_junit.py"):
        env.pop("ULAXYS_TEST_EVIDENCE", None)
    result = subprocess.run(argv, cwd=ROOT, check=False, env=env, capture_output=True,
                            text=True, encoding="utf-8", errors="replace", timeout=1800)
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
    global _ACTIVE_STAGE, _STAGE_SKIPS, _STAGE_NOT_RUN
    if _ACTIVE_STAGE is not None:
        status = "FAIL" if failed else ("SKIP" if _STAGE_NOT_RUN else (
            f"PASS WITH SKIPS ({_STAGE_SKIPS})" if _STAGE_SKIPS else "PASS"))
        print(status, flush=True)
        _ACTIVE_STAGE = None
    _STAGE_SKIPS = 0
    _STAGE_NOT_RUN = False


def test_count(result: subprocess.CompletedProcess) -> int:
    output = (result.stderr or "") + (result.stdout or "")
    match = re.search(r"Ran (\d+) tests? in ", output)
    return int(match.group(1)) if match else 0


def skipped_count(result: subprocess.CompletedProcess) -> int:
    output = (result.stderr or "") + "\n" + (result.stdout or "")
    matches = re.findall(r"(?im)(?:\bskipped\s*[=:]\s*)(\d+)", output)
    return max(map(int, matches), default=0)


def test_records(path: Path, suite: str) -> list[dict]:
    """Retain only test identifiers, actual outcomes, timings and classified skip reasons."""
    if not path.is_file() or path.stat().st_size > 32 * 1024 * 1024:
        raise RuntimeError("Missing or oversized test result XML")
    try:
        root = ET.parse(path).getroot()
    except ET.ParseError:
        raise RuntimeError("Invalid test result XML") from None
    records = []
    for index, node in enumerate(root.iter("testcase"), 1):
        raw_name = node.get("name", "")
        raw_class = node.get("class", node.get("classname", ""))
        name = re.match(r"[A-Za-z_][A-Za-z0-9_.\\\\:]{0,239}", raw_name)
        cls = re.fullmatch(r"[A-Za-z_][A-Za-z0-9_.\\\\:]{0,239}", raw_class)
        identifier = ((cls[0] + "::") if cls else "") + (name[0] if name else "testcase")
        # Data-provider values and arbitrary diagnostic messages are never exported.
        if raw_name != (name[0] if name else ""):
            identifier += f"#row-{index}"
        status = "PASS"
        reason = None
        if node.find("error") is not None:
            status = "ERROR"
        elif node.find("failure") is not None:
            status = "FAIL"
        elif node.find("skipped") is not None:
            status = "SKIP"
            skipped = node.find("skipped")
            diagnostic = (skipped.get("message", "") + " " + (skipped.text or "")).lower()
            # Fixed reason codes, not arbitrary test output or possibly secret data.
            if "symlink" in diagnostic:
                reason = "SYMLINK_UNAVAILABLE"
            elif "ulaxys_hs_code_reference" in diagnostic:
                reason = "HS_CODE_REFERENCE_REQUIRED"
            elif "ulaxys_inventory_reference" in diagnostic:
                reason = "INVENTORY_REFERENCE_REQUIRED"
            elif "expected failure" in diagnostic:
                reason = "EXPECTED_FAILURE"
            # PHPUnit native JUnit can emit an empty <skipped/>. These exact
            # source-reviewed test methods have one reference-required skip path.
            elif suite == "Foundation" and raw_class == "Tests\\Feature\\HsCodeReferenceParityTest" and raw_name in (
                "test_reference_runs_this_template_generator_engine_unmodified",
                "test_frozen_catalog_fixture_matches_reference_registry_and_metadata",
            ):
                reason = "HS_CODE_REFERENCE_REQUIRED"
            elif suite == "Foundation" and (raw_class, raw_name) in (
                ("Tests\\Feature\\InventoryIdentityParityTest", "test_latest_inventory_catalog_manifest_registered_operations_and_generated_packages_match"),
                ("Tests\\Feature\\InventoryMainGateParityTest", "test_actual_inventory_registry_manifest_and_native_package_semantic_parity"),
            ):
                reason = "INVENTORY_REFERENCE_REQUIRED"
            else:
                reason = "OTHER_SKIP"
        try:
            duration = float(node.get("time", "0"))
        except ValueError:
            raise RuntimeError("Invalid test duration") from None
        if not math.isfinite(duration) or duration < 0:
            raise RuntimeError("Invalid test duration")
        records.append({"suite": suite, "name": identifier, "status": status,
                        "durationSeconds": duration, "skipReason": reason})
    if not records:
        raise RuntimeError("Test runner produced no test cases")
    return records


def emit_test_records(path: Path, suite: str) -> list[dict]:
    records = test_records(path, suite)
    # Compact stage headings may have no trailing newline.
    print(f"\nEVIDENCE test-batch-start {suite} {len(records)}", flush=True)
    for record in records:
        print("EVIDENCE test " + json.dumps(record, separators=(",", ":")), flush=True)
    print(f"EVIDENCE test-batch-end {suite} {len(records)}", flush=True)
    return records


def php(*args: str, network: str | None = None, env_file: Path | None = None, references: dict[str, Path] | None = None) -> subprocess.CompletedProcess:
    command = [
        "docker", "run", "--rm",
        *(["--user", f"{os.getuid()}:{os.getgid()}"] if sys.platform.startswith("linux") else []),
        "--mount", f"type=bind,source={ROOT},target=/app",
        "-v", f"{VENDOR_VOLUME}:/app/vendor", "-w", "/app",
        "-e", "HOME=/tmp", "-e", "COMPOSER_HOME=/tmp/composer",
    ]
    if references:
        if network or env_file:
            raise RuntimeError("Reference tests must run without network or database credentials")
        command += ["--network", "none"]
        for name, path in references.items():
            target = "/references/" + name.lower()
            command += ["--mount", f"type=bind,source={path},target={target},readonly",
                        "-e", f"ULAXYS_{name}_REFERENCE={target}"]
    if network:
        command += ["--network", network]
    if env_file:
        command += ["--env-file", str(env_file)]
    global _STAGE_SKIPS
    if args[:2] != ("php", "vendor/bin/phpunit") or (not references and not os.environ.get("ULAXYS_TEST_EVIDENCE")):
        result = call(*(command + [DEV_IMAGE, *args]))
        if args[:2] == ("php", "vendor/bin/phpunit"):
            _STAGE_SKIPS += skipped_count(result)
        return result
    suite = args[args.index("--testsuite") + 1] if "--testsuite" in args else "PHPUnit"
    if "--filter" in args:
        suite = "References"
    with tempfile.TemporaryDirectory(prefix="ulaxys-junit-") as tmp:
        path = Path(tmp) / "results.xml"
        result = call(*(command + ["--mount", f"type=bind,source={tmp},target=/test-evidence",
                                  DEV_IMAGE, *args, "--log-junit", "/test-evidence/results.xml"]), check=False)
        records = emit_test_records(path, suite) if os.environ.get("ULAXYS_TEST_EVIDENCE") else test_records(path, suite)
        if references:
            expected = next((method for method in historical_contract()["methods"] if method.split("::")[1] == args[args.index("--filter") + 1]), None)
            if len(records) != 1 or records[0]["name"] != expected or records[0]["status"] != "PASS":
                print((result.stdout or "") + (result.stderr or ""), file=sys.stderr, flush=True)
                raise RuntimeError("Reference gate requires exactly the selected test to pass")
        _STAGE_SKIPS += sum(row["status"] == "SKIP" for row in records)
        if result.returncode or any(row["status"] in ("FAIL", "ERROR") for row in records):
            if not VERBOSE:
                print((result.stdout or "") + (result.stderr or ""), file=sys.stderr, flush=True)
            raise subprocess.CalledProcessError(result.returncode or 1, args)
        return result


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
                "--display-skipped", "--fail-on-skipped", network=network, env_file=app_env)
    finally:
        failures = []
        resources = []
        if container_created:
            resources.append(("container", name, ("rm", "-f", "-v"), ("container", "ls", "-a", "--format", "{{.Names}}")))
        if network_created:
            resources.append(("network", network, ("network", "rm"), ("network", "ls", "--format", "{{.Name}}")))
        for kind, resource, remove, listing in resources:
            try:
                call("docker", *remove, resource, check=False, quiet=True)
                result = call("docker", *listing, quiet=True)
                if resource in (result.stdout or "").splitlines():
                    raise RuntimeError("Resource remains")
                print(f"EVIDENCE cleanup {kind} {resource} absent", flush=True)
            except (OSError, RuntimeError, subprocess.SubprocessError):
                failures.append(f"{kind} {resource}")
        if failures:
            raise RuntimeError("Cleanup verification failed: " + "; ".join(failures))



def run_generator_tests(tooling_only: bool = False) -> int:
    """Run template-only regression suites; count tests without leaking test logs."""
    source = ROOT / "tests/Tooling"
    if not source.is_dir():
        raise RuntimeError("Template generator tests are unavailable in this service")
    patterns = ("test_*.py",)
    global _STAGE_SKIPS
    total = 0
    for pattern in patterns:
        if "*" not in pattern and not (source / pattern).is_file():
            raise RuntimeError(f"Missing tooling regression: {pattern}")
        if os.environ.get("ULAXYS_TEST_EVIDENCE"):
            with tempfile.TemporaryDirectory(prefix="ulaxys-python-junit-") as tmp:
                path = Path(tmp) / "results.xml"
                result = call(sys.executable, "tests/Tooling/run_junit.py", "--pattern", pattern,
                              "--output", str(path), check=False)
                records = emit_test_records(path, "Python")
                _STAGE_SKIPS += sum(row["status"] == "SKIP" for row in records)
                total += sum(row["status"] == "PASS" for row in records)
                if result.returncode or any(row["status"] in ("FAIL", "ERROR") for row in records):
                    if not VERBOSE:
                        print((result.stdout or "") + (result.stderr or ""), file=sys.stderr, flush=True)
                    raise subprocess.CalledProcessError(result.returncode or 1, result.args)
        else:
            result = call(sys.executable, "-m", "unittest", "discover", "-s",
                          "tests/Tooling", "-p", pattern, "-v", "-b")
            skipped = skipped_count(result)
            _STAGE_SKIPS += skipped
            total += test_count(result) - skipped
    return total


def historical_contract() -> dict:
    path = ROOT / "tests/Fixtures/ReferenceParity/contract.json"
    if not path.is_file():
        raise RuntimeError("Historical parity contract is not installed in this checkout")
    return json.loads(path.read_text(encoding="utf-8"))


def validate_checkout(checkout: Path, repository: str, commit: str) -> None:
    if checkout == ROOT.resolve() or Path(reference_git(checkout, "rev-parse", "--show-toplevel")).resolve() != checkout:
        raise RuntimeError("Reference must be an independent Git checkout root")
    origin = reference_git(checkout, "remote", "get-url", "origin")
    if not re.fullmatch(r"(?:https://github\.com/|ssh://git@github\.com/|git@github\.com:/?)" + re.escape(repository) + r"(?:\.git)?/?", origin, re.IGNORECASE):
        raise RuntimeError("Unexpected reference origin")
    if not re.fullmatch(r"[0-9a-f]{40}", commit) or reference_git(checkout, "rev-parse", commit + "^{commit}") != commit:
        raise RuntimeError("Reference commit mismatch")
    if reference_git(checkout, "status", "--porcelain", "--untracked-files=normal"):
        raise RuntimeError("Dirty reference checkout")


def reference_git(checkout: Path, *args: str) -> str:
    result = subprocess.run(["git", "-C", str(checkout), *args], check=True,
                            capture_output=True, text=True, encoding="utf-8", timeout=60)
    return result.stdout.strip()


def resolve_references(args: argparse.Namespace) -> dict:
    resolved = {}
    for name, repository in historical_contract()["repositories"].items():
        prefix = name.lower()
        supplied = getattr(args, prefix + "_reference", None)
        pin = getattr(args, prefix + "_commit", None)
        checkout = Path(supplied or ROOT.parent / repository.split("/")[-1]).resolve(strict=True)
        commit = pin or historical_contract()["commits"][name]
        validate_checkout(checkout, repository, commit)
        branch = reference_git(checkout, "rev-parse", "--abbrev-ref", "HEAD")
        resolved[name] = (checkout, commit, branch)
    return resolved


def reference_status(args: argparse.Namespace) -> None:
    for name, (checkout, commit, branch) in resolve_references(args).items():
        print(f"{name}: READY | {checkout} | {branch} | {commit} | CLEAN", flush=True)


def reference_snapshot(checkout: Path, commit: str, destination: Path) -> None:
    """Export only committed regular files from an explicitly trusted, pinned checkout."""
    if not re.fullmatch(r"[0-9a-f]{40}", commit):
        raise RuntimeError("Reference requires a full lowercase Git commit SHA")
    checkout = checkout.resolve(strict=True)
    def git(*args: str) -> str:
        result = subprocess.run(["git", "-C", str(checkout), *args], check=True,
                                capture_output=True, text=True, encoding="utf-8", timeout=60)
        return result.stdout.strip()
    if Path(git("rev-parse", "--show-toplevel")).resolve() != checkout:
        raise RuntimeError("Reference path must be a Git checkout root")
    if checkout == ROOT.resolve():
        raise RuntimeError("Reference must be an independent checkout")
    if git("rev-parse", commit + "^{commit}") != commit or git("status", "--porcelain", "--untracked-files=normal"):
        raise RuntimeError("Reference checkout must be clean and contain the requested commit")
    # Archive from the object database, not the working tree (ignored secrets excluded).
    archive = destination.with_suffix(".tar")
    subprocess.run(["git", "-C", str(checkout), "archive", "--format=tar",
                    "-o", str(archive), commit], check=True, timeout=120)
    destination.mkdir()
    with tarfile.open(archive, "r:") as source:
        for member in source:
            parts = member.name.split("/")
            if (not parts or any(p in ("", ".", "..") or ":" in p or "\\" in p for p in parts)
                    or any(p.lower() == ".git" for p in parts)):
                raise RuntimeError("Unsafe reference archive path")
            if any(p.lower() == ".env" or (p.lower().startswith(".env.") and not p.lower().endswith(".example")) for p in parts):
                raise RuntimeError("Reference archive contains an environment file")
            target = destination.joinpath(*parts)
            if member.isdir():
                target.mkdir(parents=True, exist_ok=True)
            elif member.isfile():
                target.parent.mkdir(parents=True, exist_ok=True)
                with source.extractfile(member) as inp, target.open("xb") as out:
                    shutil.copyfileobj(inp, out)
            else:
                raise RuntimeError("Reference archive contains a link or unsupported entry")
    archive.unlink()


def run_references(args: argparse.Namespace) -> None:
    if not (ROOT / "scripts/generate-service.py").is_file():
        raise RuntimeError("Reference acceptance is template-only")
    print("uLaxys  /  TEST  /  REFERENCES", flush=True)
    with tempfile.TemporaryDirectory(prefix="ulaxys-references-") as tmp:
        mounts = {}
        for name, (path, commit, branch) in resolve_references(args).items():
            print(f"{name}: READY | {branch} | {commit} | CLEAN", flush=True)
            destination = Path(tmp) / name.lower()
            reference_snapshot(Path(path), commit, destination)
            mounts[name] = destination
            print(f"EVIDENCE reference {name} {commit}", flush=True)
        stage("Reference test preflight")
        safe_preflight()
        stage("Pinned external reference parity")
        failures = []
        for method in historical_contract()["methods"]:
            try:
                php("php", "vendor/bin/phpunit", "tests/Feature/" + method.split("::")[0].split("\\")[-1] + ".php",
                    "--filter", method.split("::")[1], "--display-skipped", references=mounts)
            except (RuntimeError, subprocess.CalledProcessError) as exc:
                failures.append(method)
                print(f"Reference test failed: {method}: {exc}", file=sys.stderr, flush=True)
        if failures:
            raise RuntimeError("Reference gate requires all four tests to execute and pass without skips")
        finish_stage()
        print("PASS reference parity (4/4)", flush=True)


def run_tests(suite: str) -> None:
    print(f"uLaxys  /  TEST  /  {suite.upper()}\n", flush=True)
    count = 0
    if suite == "generator":
        raise RuntimeError("Service generation is template-only")
    if suite == "tooling":
        stage(f"{suite.capitalize()} regression tests")
        count = run_generator_tests(tooling_only=(suite == "tooling"))
        skipped = _STAGE_SKIPS
        finish_stage()
        print(f"\n  {'PASS WITH SKIPS' if skipped else 'PASS'}  {count} tests passed; {skipped} skipped", flush=True)
        return
    if suite == "all":
        stage("Generator and tooling regression tests")
        count += run_generator_tests()
    stage("PHP test preflight")
    safe_preflight()
    if suite in ("all", "foundation"):
        stage("PHPUnit Foundation")
        php("php", "vendor/bin/phpunit", "--testsuite", "Foundation", "--display-skipped")
    if suite in ("all", "database"):
        stage("PHPUnit Database (isolated PostgreSQL)")
        with_disposable_database()
    finish_stage()
    print(f"\n  PASS  {suite} tests complete"
          + (f" ({count} Python regressions)" if count else ""), flush=True)


def verify_integration() -> None:
    result = php("php", "-r", 'echo json_encode((require "config/integration.php")["schemaVersion"] ?? null);')
    version = (result.stdout or "").strip()
    if version == "1":
        php("php", "artisan", "ulaxys:generate-integration", "--all", "--strict", "--dry-run")
        php("php", "artisan", "ulaxys:generate-deploy", "--strict", "--dry-run")
    elif version == "2":
        php("php", "artisan", "ulaxys:generate-integration", "--all", "--strict", "--dry-run",
            "--gateway-format=json", "--permission-format=json", "--output=security-review")
        php("php", "artisan", "ulaxys:generate-deploy", "--strict", "--dry-run", "--output=security-review")
        print("\n  REVIEW ONLY: schema v2 native publication remains unsupported", flush=True)
    else:
        raise RuntimeError("Unsupported integration schema; no fallback allowed")


def verify(skip_database: bool = False) -> None:
    global _STAGE_NOT_RUN
    print("uLaxys  /  VERIFY  /  LOCAL\n", flush=True)
    stage("Preflight (dependencies, cached configuration)")
    safe_preflight()
    stage("Identity tooling regression tests")
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
    php("php", "vendor/bin/phpunit", "--testsuite", "Foundation", "--display-skipped")
    if skip_database:
        stage("Database tests skipped by explicit --skip-database (not full CI parity)")
        _STAGE_NOT_RUN = True
    else:
        stage("PHPUnit Database (isolated PostgreSQL)")
        with_disposable_database()
    stage("OpenAPI release gate")
    php("composer", "openapi")
    stage("Integration generator dry-runs")
    verify_integration()
    finish_stage()
    print("\n  " + ("REDUCED PASS" if skip_database else "PASS") +
          "  Local source checks complete (production smoke is separate)", flush=True)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--verbose", action="store_true", help="Show raw test and tool output")
    parser.add_argument("--tests", choices=("all", "generator", "tooling", "foundation", "database", "references"),
                        help="Run only the selected test suite, skipping lint and OpenAPI")
    parser.add_argument("--skip-database", action="store_true",
                        help="Skip disposable DB tests (reduced acceptance, not full CI parity)")
    for option in ("hs-code-reference", "hs-code-commit", "inventory-reference", "inventory-commit"):
        parser.add_argument("--" + option)
    args = parser.parse_args(argv)
    pins = (args.hs_code_reference, args.hs_code_commit, args.inventory_reference, args.inventory_commit)
    if (any(pins) and not all(pins)) or (any(pins) and args.tests != "references"):
        parser.error("Reference acceptance requires both checkout paths and both commit SHAs")
    if args.tests and args.skip_database:
        parser.error("--skip-database is supported only by verify")
    global VERBOSE
    VERBOSE = args.verbose
    try:
        if args.tests == "references":
            raise RuntimeError("Historical reference audits are template-only")
        elif args.tests:
            run_tests(args.tests)
        else:
            verify(skip_database=args.skip_database)
        return 0
    except subprocess.CalledProcessError as exc:
        finish_stage(failed=True)
        print(f"  FAIL  {exc.cmd[0]} exited {exc.returncode}", file=sys.stderr)
        return exc.returncode or 1
    except (OSError, RuntimeError, subprocess.TimeoutExpired) as exc:
        finish_stage(failed=True)
        print(f"  FAIL  {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    # Keep the caller's console encoding; escape only characters it cannot represent.
    # Python subprocess pipes use explicit UTF-8, independent of the Windows code page.
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, "reconfigure"):
            stream.reconfigure(errors="backslashreplace")
    sys.exit(main())
