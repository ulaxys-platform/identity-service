#!/usr/bin/env python3
"""Isolated local production and generated-service acceptance (Python 3.10+)."""
from __future__ import annotations

import argparse
import base64
import hashlib
import os
from pathlib import Path
import secrets
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parent.parent
POSTGRES_IMAGE = "postgres:17@sha256:b8faeff7712663c2773a190181e1246e37e364b887611c04658c28b44e2408fe"


def run(*args: str, cwd: Path = ROOT, capture: bool = False, check: bool = True) -> subprocess.CompletedProcess:
    """Run without a shell, retaining exit codes; do not log env files or secrets."""
    result = subprocess.run(
        args, cwd=cwd, check=False, capture_output=capture, text=True,
        env={**os.environ, "PYTHONDONTWRITEBYTECODE": "1"},
    )
    if check and result.returncode:
        raise RuntimeError(f"Command failed ({result.returncode}): {args[0]} {args[1] if len(args) > 1 else ''}")
    return result


def stage(name: str) -> None:
    print(f"  {name}", flush=True)


class DisposableRuntime:
    """Own only randomly named network/container resources created by this invocation."""

    def __init__(self, directory: Path) -> None:
        self.directory = directory
        self.tag = secrets.token_hex(7)
        self.network = f"ulaxys-smoke-net-{self.tag}"
        self.db = f"ulaxys-smoke-pg-{self.tag}"
        self.network_created = False
        self.database_created = False
        self.environment = directory / "runtime.env"

    def __enter__(self) -> "DisposableRuntime":
        password = secrets.token_urlsafe(36)
        db_env = self.directory / "postgres.env"
        db_env.write_text(
            f"POSTGRES_DB=service_smoke\nPOSTGRES_USER=service_smoke\nPOSTGRES_PASSWORD={password}\n",
            encoding="utf-8",
        )
        self.environment.write_text(
            "APP_ENV=production\nAPP_DEBUG=false\nLOG_CHANNEL=stderr\n"
            f"APP_KEY=base64:{base64.b64encode(secrets.token_bytes(32)).decode()}\n"
            f"SERVICE_SIGNATURE={secrets.token_hex(32)}\n"
            "DB_CONNECTION=pgsql\n"
            f"DB_HOST={self.db}\nDB_PORT=5432\nDB_DATABASE=service_smoke\n"
            f"DB_USERNAME=service_smoke\nDB_PASSWORD={password}\nDB_SSLMODE=disable\n",
            encoding="utf-8",
        )
        os.chmod(db_env, 0o600)
        os.chmod(self.environment, 0o600)
        try:
            run("docker", "network", "create", "--internal", self.network, capture=True)
            self.network_created = True
            run("docker", "run", "-d", "--name", self.db, "--network", self.network,
                "--env-file", str(db_env), POSTGRES_IMAGE, capture=True)
            self.database_created = True
            for _ in range(45):
                result = run("docker", "exec", self.db, "pg_isready", "-U", "service_smoke",
                             "-d", "service_smoke", capture=True, check=False)
                if result.returncode == 0:
                    return self
                time.sleep(1)
            raise RuntimeError("Disposable PostgreSQL did not become ready")
        except BaseException:
            self.__exit__(None, None, None)
            raise

    def __exit__(self, *_: object) -> None:
        if self.database_created:
            run("docker", "rm", "-f", self.db, capture=True, check=False)
        if self.network_created:
            run("docker", "network", "rm", self.network, capture=True, check=False)


def image_name(kind: str, tag: str) -> str:
    return f"ulaxys-local-{kind}:{tag}"


def smoke(image: str, runtime: DisposableRuntime, source_root: Path = ROOT) -> None:
    run(sys.executable, str(ROOT / "tests/Runtime/smoke-container.py"),
        image, str(runtime.environment), runtime.network, str(source_root))


def validate_source(kind: str) -> None:
    if kind == "generated" and not (ROOT / ".git").exists():
        raise RuntimeError("Generated-service acceptance requires a Git checkout of the PHP service template")
    if not (ROOT / "tests/Runtime/smoke-container.py").is_file():
        raise RuntimeError("Missing production runtime smoke contract")


def remove_generated_dependencies(path: Path) -> None:
    """Remove only per-checkout Docker artifacts created by generated tools install."""
    key = hashlib.sha256(str(path).encode()).hexdigest()[:12]
    run("docker", "volume", "rm", f"ulaxys-php-vendor-{key}", capture=True, check=False)
    remove_image(f"ulaxys-php-tools:{key}")


def remove_image(image: str) -> None:
    # Unique per-run image tag; never prune shared Docker images or build cache.
    run("docker", "image", "rm", "-f", image, capture=True, check=False)


def accept_smoke(runtime: DisposableRuntime) -> None:
    image = image_name("template-production", runtime.tag)
    try:
        stage("Dockerfile preflight")
        run("docker", "build", "--check", ".", cwd=ROOT)
        stage("Build template production image")
        run("docker", "build", "--target", "production", "-t", image, ".", cwd=ROOT)
        stage("Production container smoke (isolated PostgreSQL)")
        smoke(image, runtime)
        print("\n  PASS  Template production smoke", flush=True)
    finally:
        remove_image(image)


def generated_tools(path: Path, *arguments: str) -> None:
    """Exercise the actual inherited platform launcher, not its Python internals."""
    if os.name == "nt":
        run("powershell.exe", "-NoProfile", "-File", str(path / "tools.ps1"), *arguments, cwd=path)
    else:
        run("sh", str(path / "tools.sh"), *arguments, cwd=path)


def accept_generated(runtime: DisposableRuntime, directory: Path) -> None:
    path = directory / "acceptance-service"
    image = image_name("generated-production", runtime.tag)
    try:
        stage("Generate independent acceptance-service from committed HEAD")
        run(sys.executable, str(ROOT / "ulaxys"), "generate", "service", "acceptance-service",
            "--output", str(path), cwd=ROOT)
        stage("Verify immutable generation provenance")
        run(sys.executable, str(path / "scripts/verify-generation.py"), "verify", cwd=path)
        for file in ("tools.cmd", "tools.ps1", "tools.sh", "scripts/ulaxys-tools.py",
                     "scripts/verify-local.py", "scripts/clear-local-cache.py",
                     "scripts/acceptance-local.py"):
            if not (path / file).is_file():
                raise RuntimeError(f"Generated service is missing tooling: {file}")
        if (path / "tests/Generator").exists() or (path / "scripts/generate-service.py").exists():
            raise RuntimeError("Generator-only tests or generator leaked into consumer")
        stage("Generated service tooling installation")
        generated_tools(path, "install")
        stage("Generated service full test suite (isolated Database suite)")
        generated_tools(path, "test")
        stage("Generated service full quality gate")
        generated_tools(path, "verify")
        stage("Generated service local cache command")
        generated_tools(path, "cache:status")
        stage("Build generated service production image")
        run("docker", "build", "--target", "production", "-t", image, ".", cwd=path)
        stage("Generated service production container smoke")
        smoke(image, runtime, path)
        print("\n  PASS  Generated service acceptance", flush=True)
    finally:
        remove_image(image)
        remove_generated_dependencies(path)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("kind", choices=("smoke", "generated"))
    args = parser.parse_args(argv)
    print(f"uLaxys  /  ACCEPTANCE  /  {args.kind.upper()}\n", flush=True)
    try:
        validate_source(args.kind)
        # All env credentials and generated service files are temporary.
        with tempfile.TemporaryDirectory(prefix="ulaxys-acceptance-") as temp:
            with DisposableRuntime(Path(temp)) as runtime:
                if args.kind == "smoke":
                    accept_smoke(runtime)
                else:
                    accept_generated(runtime, Path(temp))
        return 0
    except (OSError, RuntimeError, subprocess.SubprocessError) as exc:
        print(f"  FAIL  {exc}", file=sys.stderr, flush=True)
        return 1


if __name__ == "__main__":
    sys.exit(main())
