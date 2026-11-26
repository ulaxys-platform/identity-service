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
    env = {**os.environ, "PYTHONDONTWRITEBYTECODE": "1"}
    result = subprocess.run(args, check=False, cwd=ROOT, env=env)
    if result.returncode:
        raise SystemExit(result.returncode)


def build_php() -> None:
    run("docker", "build", "--target", "development", "-t", DEV_IMAGE, ".")


def php(*cmd: str) -> None:
    run("docker", "run", "--rm", "-v", f"{ROOT}:/app",
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
    parser.add_argument("action", choices=("install", "lint", "format", "test", "verify", "smoke", "acceptance",
        "clear:cache", "clear:config", "clear:routes", "clear:events", "clear:compiled", "clear:views", "clear:tests", "clear:all", "cache:status"))
    parser.add_argument("tool", nargs="?", choices=("php", "prettier", "syntax", "generator", "tooling", "foundation", "database", "all", "generated"))
    args = parser.parse_args(argv)
    if args.skip_database and args.action != "verify":
        parser.error("--skip-database is supported only by verify")
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
            "smoke" if args.action == "smoke" else "generated")
        return 0
    if args.action == "test":
        suite = args.tool or "all"
        if suite not in ("generator", "tooling", "foundation", "database", "all"):
            parser.error("test requires generator, tooling, foundation, database or all")
        run(sys.executable, str(ROOT / "scripts/verify-local.py"), "--tests", suite,
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
    sys.exit(main())
