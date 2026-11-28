#!/usr/bin/env python3
"""Reproducible local code-quality commands, isolated from running services.

Requires only Python 3.10+ and Docker. Uses the repository's development
stage for PHP and a pinned Prettier package for text configuration.
"""
from __future__ import annotations

import argparse
import hashlib
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parent.parent
CHECKOUT_KEY = hashlib.sha256(str(ROOT).encode()).hexdigest()[:12]
DEV_IMAGE = f"ulaxys-php-tools:{CHECKOUT_KEY}"
NODE_IMAGE = "node:22-bookworm-slim"
PRETTIER_VERSION = "3.6.2"
PHP_VENDOR_VOLUME = "ulaxys-php-vendor-" + CHECKOUT_KEY
NPM_CACHE_VOLUME = "ulaxys-prettier-cache-v1"


def run(*args: str) -> None:
    # Generator regressions should not create Python bytecode inside the checkout.
    env = {**os.environ, "PYTHONDONTWRITEBYTECODE": "1", "PYTHONIOENCODING": "utf-8:backslashreplace", "PYTHONUTF8": "1"}
    result = subprocess.run(args, check=False, cwd=ROOT, env=env)
    if result.returncode:
        raise SystemExit(result.returncode)


def host_user() -> list[str]:
    return ["--user", f"{os.getuid()}:{os.getgid()}"] if sys.platform.startswith("linux") else []


def build_php() -> None:
    run("docker", "build", "--target", "development", "-t", DEV_IMAGE, ".")
    if host_user():
        # Initialize only this checkout's named vendor volume; never chown the host checkout.
        run("docker", "run", "--rm", "--network", "none", "-v", f"{PHP_VENDOR_VOLUME}:/vendor",
            DEV_IMAGE, "chown", "-R", "--no-dereference", f"{os.getuid()}:{os.getgid()}", "/vendor")


def php(*cmd: str) -> None:
    run("docker", "run", "--rm", *host_user(), "-v", f"{ROOT}:/app",
        "-v", f"{PHP_VENDOR_VOLUME}:/app/vendor", "-w", "/app",
        "-e", "HOME=/tmp", "-e", "COMPOSER_HOME=/tmp/composer",
        DEV_IMAGE, *cmd)


def prettier(*cmd: str) -> None:
    run("docker", "run", "--rm", "-v", f"{ROOT}:/app",
        "-v", f"{NPM_CACHE_VOLUME}:/root/.npm", "-w", "/app",
        NODE_IMAGE, "npm", "exec", "--yes", f"--package=prettier@{PRETTIER_VERSION}",
        "--", "prettier", *cmd)


def require_vendor() -> None:
    # Explicit install command avoids silently invoking the network on lint.
    php("sh", "-ec", "test -x vendor/bin/pint || { echo 'Run tools install first.' >&2; exit 2; }")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--verbose", action="store_true", help="Show full tool and test logs for test/verify")
    parser.add_argument("--skip-database", action="store_true", help="Reduced verify without Database tests")
    parser.add_argument("--repeat", type=int, default=None, help="Number of independent acceptance iterations (1-1000)")
    parser.add_argument("--filter", help="Exact runtime case name")
    parser.add_argument("--network-matrix", action="store_true", help="Include IPv4, dual-stack and IPv6-disabled smoke")
    parser.add_argument("--evidence", action="store_true", help="Write sanitized command-level JSON/JUnit evidence")
    parser.add_argument("--list", action="store_true", help="List runtime cases or reference repositories without Docker")
    parser.add_argument("--discover", action="store_true", help="Discover local reference repositories")
    parser.add_argument("--status", action="store_true", help="Validate local reference repositories")
    parser.add_argument("--benchmarks", action="store_true", help="Run runtime performance measurements only")
    parser.add_argument("action", choices=("install", "lint", "format", "test", "verify", "smoke", "runtime", "acceptance",
        "dependencies", "check:identity", "clear:cache", "clear:config", "clear:routes", "clear:events", "clear:compiled", "clear:views", "clear:tests", "clear:all", "cache:status"))
    parser.add_argument("tool", nargs="?", choices=("php", "prettier", "syntax", "generator", "tooling", "foundation", "database", "all", "generated", "full", "references", "dependencies"))
    parser.allow_abbrev = False
    for option in ("hs-code-reference", "hs-code-commit", "inventory-reference", "inventory-commit"):
        parser.add_argument("--" + option)
    args = parser.parse_args(argv)
    reference_options = ("hs-code-reference", "hs-code-commit", "inventory-reference", "inventory-commit")
    pins = [getattr(args, name.replace("-", "_")) for name in reference_options]
    reference_mode = args.action == "test" and args.tool == "references"
    if (any(pins) and not all(pins)) or (not reference_mode and any(pins)):
        parser.error("Reference acceptance requires test references and both checkout paths and commit SHAs")
    if args.action == "dependencies":
        if args.tool or args.evidence or args.verbose or args.skip_database or args.repeat is not None or args.filter or args.network_matrix or args.benchmarks or args.discover or sum((args.list, args.status)) != 1:
            parser.error("dependencies requires exactly one of --list or --status")
        php("php", "scripts/check-dependencies.php", "list" if args.list else "status")
        return 0
    if args.discover or args.status:
        parser.error("--status requires dependencies")
    if args.evidence:
        if args.action not in ("install", "test", "verify", "smoke", "runtime", "acceptance") or args.list:
            parser.error("--evidence requires an execution command")
        arguments = list(sys.argv[1:] if argv is None else argv)
        run(sys.executable, str(ROOT / "scripts/evidence-local.py"),
            *[argument for argument in arguments if argument != "--evidence"])
        return 0
    if args.network_matrix and args.action != "smoke":
        parser.error("--network-matrix requires smoke")
    runtime_mode = args.action == "runtime"
    full_mode = args.action == "acceptance" and args.tool == "full"
    if args.repeat is not None and (not (runtime_mode or full_mode) or not 1 <= args.repeat <= 1000):
        parser.error("--repeat requires runtime or acceptance full and a value from 1 to 1000")
    if args.list and (not runtime_mode or args.filter or args.benchmarks or args.repeat is not None or args.verbose):
        parser.error("--list requires runtime without execution options")
    if (args.filter is not None or args.benchmarks) and not runtime_mode:
        parser.error("--filter and --benchmarks require runtime")
    if args.filter is not None and args.benchmarks:
        parser.error("--filter and --benchmarks cannot be combined")
    if runtime_mode or full_mode:
        if runtime_mode and args.tool is not None:
            parser.error("runtime accepts no selector")
        if args.skip_database:
            parser.error("runtime and full acceptance cannot skip database checks")
        run(sys.executable, str(ROOT / "scripts/runtime-local.py"),
            "full" if full_mode else "runtime", "--repeat", str(args.repeat or 1),
            *(["--filter", args.filter] if args.filter is not None else []),
            *(["--benchmarks"] if args.benchmarks else []),
            *(["--list"] if args.list else []),
            *(["--verbose"] if args.verbose else []))
        return 0
    if args.skip_database and args.action != "verify":
        parser.error("--skip-database is supported only by verify")
    if args.action == "check:identity":
        if args.tool is not None or args.verbose:
            parser.error("check:identity accepts no selector or --verbose")
        require_vendor()
        php("php", "scripts/check-new-service.php")
        php("composer", "dump-autoload", "--optimize", "--strict-psr", "--no-interaction")
        php("php", "artisan", "route:list", "--json")
        return 0
    if args.action.startswith("clear:") or args.action == "cache:status":
        if args.tool is not None or args.verbose:
            parser.error("cache actions do not accept tool selectors or --verbose")
        run(sys.executable, str(ROOT / "scripts/clear-local-cache.py"), args.action)
        return 0
    if args.action in ("smoke", "acceptance"):
        if args.action == "smoke" and args.tool is not None:
            parser.error("smoke accepts no selector")
        if args.action == "acceptance" and args.tool != "generated":
            parser.error("acceptance requires the 'generated' selector")
        if args.verbose or args.skip_database:
            parser.error("smoke and acceptance do not accept --verbose or --skip-database")
        run(sys.executable, str(ROOT / "scripts/acceptance-local.py"),
            "smoke" if args.action == "smoke" else "generated",
            *(["--network-matrix"] if args.network_matrix else []))
        return 0
    if args.action == "test":
        suite = args.tool or "all"
        if suite == "dependencies":
            php("php", "scripts/check-dependencies.php", "test")
            return 0
        if suite not in ("generator", "tooling", "foundation", "database", "all", "references"):
            parser.error("test requires generator, tooling, foundation, database or all")
        run(sys.executable, str(ROOT / "scripts/verify-local.py"), "--tests", suite,
            *([part for name, value in zip(reference_options, pins) for part in ("--" + name, value)] if reference_mode and all(pins) else []),
            *(["--verbose"] if args.verbose else []))
        return 0
    if args.action == "verify":
        if args.tool is not None:
            parser.error("verify does not accept a suite selector; use verify --skip-database for reduced checks")
        run(sys.executable, str(ROOT / "scripts/verify-local.py"),
            *(["--verbose"] if args.verbose else []),
            *(["--skip-database"] if args.skip_database else []))
        return 0
    if args.action in ("lint", "format") and args.tool not in (None, "php", "prettier", "syntax"):
        parser.error("lint/format require php, prettier or syntax")
    if args.action == "install":
        if args.tool is not None:
            parser.error("install does not accept a tool selector")
        build_php()
        php("composer", "install", "--no-interaction", "--no-progress", "--prefer-dist", "--no-scripts")
        prettier("--version")
        return 0
    if args.tool == "syntax":
        if args.action != "lint":
            parser.error("syntax supports lint only")
        php("sh", "-ec", "find app bootstrap config routes database tests public scripts .docker -name '*.php' -print0 | xargs -0 -r -n1 php -l > /dev/null")
        return 0
    if args.tool in (None, "php"):
        require_vendor()
        php("php", "vendor/bin/pint", *(['--test'] if args.action == "lint" else []))
    if args.tool in (None, "prettier"):
        prettier("--check" if args.action == "lint" else "--write", ".")
    return 0


if __name__ == "__main__":
    # Keep the caller's console encoding; escape only characters it cannot represent.
    # Python subprocess pipes use explicit UTF-8, independent of the Windows code page.
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, "reconfigure"):
            stream.reconfigure(errors="backslashreplace")
    sys.exit(main())
