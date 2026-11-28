#!/usr/bin/env python3
"""Isolated local production and generated-service acceptance (Python 3.10+)."""
from __future__ import annotations

import argparse
import base64
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
POSTGRES_IMAGE = "postgres:17@sha256:b8faeff7712663c2773a190181e1246e37e364b887611c04658c28b44e2408fe"


def run(*args: str, cwd: Path = ROOT, capture: bool = False, check: bool = True, timeout: int = 1800) -> subprocess.CompletedProcess:
    """Run without a shell, retaining exit codes; do not log env files or secrets."""
    result = subprocess.run(
        args, cwd=cwd, check=False, capture_output=capture, text=True, encoding="utf-8", errors="replace",
        env={**os.environ, "PYTHONDONTWRITEBYTECODE": "1", "PYTHONIOENCODING": "utf-8:backslashreplace", "PYTHONUTF8": "1"}, timeout=timeout,
    )
    if check and result.returncode:
        raise RuntimeError(f"Command failed ({result.returncode}): {args[0]} {args[1] if len(args) > 1 else ''}")
    return result


def cleanup_resource(kind: str, name: str) -> None:
    """Remove a run-owned resource and prove exact name/tag absence via Docker."""
    commands = {
        "container": (("rm", "-f", "-v"), ("container", "ls", "-a", "--format", "{{.Names}}")),
        "network": (("network", "rm"), ("network", "ls", "--format", "{{.Name}}")),
        "volume": (("volume", "rm"), ("volume", "ls", "--format", "{{.Name}}")),
        "image": (("image", "rm"), ("image", "ls", "--format", "{{.Repository}}:{{.Tag}}")),
    }
    remove, listing = commands[kind]
    # Removal may report not found (e.g. a failed build). A successful listing
    # is required regardless of the removal exit code.
    run("docker", *remove, name, capture=True, check=False)
    result = run("docker", *listing, capture=True)
    if name in (result.stdout or "").splitlines():
        raise RuntimeError(f"Cleanup failed: {kind} remains: {name}")
    print(f"EVIDENCE cleanup {kind} {name} absent", flush=True)


def cleanup_all(resources: list[tuple[str, str]]) -> None:
    failures = []
    for kind, name in resources:
        try:
            cleanup_resource(kind, name)
        except (OSError, RuntimeError, subprocess.SubprocessError) as exc:
            failures.append(f"{kind} {name}: {type(exc).__name__}")
    if failures:
        raise RuntimeError("Cleanup verification failed: " + "; ".join(failures))


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
        resources = []
        if self.database_created:
            resources.append(("container", self.db))
        if self.network_created:
            resources.append(("network", self.network))
        cleanup_all(resources)


def image_name(kind: str, tag: str) -> str:
    return f"ulaxys-local-{kind}:{tag}"


def smoke(image: str, runtime: DisposableRuntime, source_root: Path = ROOT) -> None:
    run(sys.executable, str(ROOT / "tests/Runtime/smoke-container.py"),
        image, str(runtime.environment), runtime.network, str(source_root))


def validate_source(kind: str) -> None:
    if kind == "generated" and not (ROOT / "scripts/generate-service.py").is_file():
        raise RuntimeError("Generated-service acceptance requires a Git checkout of the PHP service template")
    if not (ROOT / "tests/Runtime/smoke-container.py").is_file():
        raise RuntimeError("Missing production runtime smoke contract")


def remove_generated_dependencies(path: Path) -> None:
    """Remove only per-checkout Docker artifacts created by generated tools install."""
    key = hashlib.sha256(str(path).encode()).hexdigest()[:12]
    cleanup_all([("volume", f"ulaxys-php-vendor-{key}"), ("image", f"ulaxys-php-tools:{key}")])


def remove_image(image: str) -> None:
    # Unique per-run image tag; never prune shared Docker images or build cache.
    cleanup_resource("image", image)


def record_image(image: str) -> None:
    result = run("docker", "image", "inspect", image, "--format", "{{.Id}}", capture=True)
    image_id = (result.stdout or "").strip()
    if not re.fullmatch(r"sha256:[0-9a-f]{64}", image_id):
        raise RuntimeError("Missing valid production image ID")
    print(f"EVIDENCE image-id {image_id}", flush=True)


def network_matrix(image: str, runtime: DisposableRuntime) -> None:
    # All resources belong to this invocation, no host ports or external database.
    dual = runtime.network + "-dual"
    disabled = runtime.network + "-noipv6"
    dual_created = False
    disabled_created = False
    try:
        ipv4 = run("docker", "network", "inspect", runtime.network, "--format",
                   "{{.EnableIPv6}}", capture=True).stdout.strip()
        if ipv4 != "false":
            raise RuntimeError("Expected IPv4-only baseline network")
        run("docker", "network", "create", "--internal", "--ipv6", dual, capture=True)
        dual_created = True
        ipv6 = run("docker", "network", "inspect", dual, "--format",
                   "{{.EnableIPv6}}", capture=True).stdout.strip()
        if ipv6 != "true":
            raise RuntimeError("Expected dual-stack network")
        run("docker", "network", "connect", dual, runtime.db, capture=True)
        stage("Dual-stack production smoke")
        run(sys.executable, str(ROOT / "tests/Runtime/smoke-container.py"),
            image, str(runtime.environment), dual, str(ROOT))
        stage("Container startup with IPv6 disabled")
        run("docker", "run", "-d", "--name", disabled, "--network", runtime.network,
            "--env-file", str(runtime.environment), "--sysctl", "net.ipv6.conf.all.disable_ipv6=1",
            "--sysctl", "net.ipv6.conf.default.disable_ipv6=1", image, capture=True)
        disabled_created = True
        for _ in range(30):
            result = run("docker", "exec", disabled, "php", "/usr/local/bin/service-runtime-check.php",
                         "--health", capture=True, check=False, timeout=15)
            if result.returncode == 0:
                break
            time.sleep(2)
        else:
            raise RuntimeError("IPv6-disabled container did not become healthy")
        print("PASS network-matrix", flush=True)
    finally:
        resources = []
        if disabled_created:
            resources.append(("container", disabled))
        if dual_created:
            try:
                run("docker", "network", "disconnect", "-f", dual, runtime.db, capture=True, check=False)
            finally:
                resources.append(("network", dual))
                cleanup_all(resources)
        else:
            cleanup_all(resources)


def accept_smoke(runtime: DisposableRuntime, matrix: bool = False) -> None:
    image = image_name("template-production", runtime.tag)
    try:
        stage("Dockerfile preflight")
        run("docker", "build", "--check", ".", cwd=ROOT)
        stage("Build template production image")
        run("docker", "build", "--target", "production", "-t", image, ".", cwd=ROOT)
        record_image(image)
        stage("Production container smoke (isolated PostgreSQL)")
        smoke(image, runtime)
        if matrix:
            network_matrix(image, runtime)
    finally:
        remove_image(image)
    print("\n  PASS  Template production smoke", flush=True)


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
        if (path / ".env").exists() or (path / ".git").exists():
            raise RuntimeError("Generated service contains forbidden environment or Git state")
        stage("Verify immutable generation provenance")
        run(sys.executable, str(path / "scripts/verify-generation.py"), "verify", cwd=path)
        for file in ("tools.cmd", "tools.ps1", "tools.sh", "scripts/ulaxys-tools.py",
                     "scripts/verify-local.py", "scripts/clear-local-cache.py",
                     "scripts/acceptance-local.py", "scripts/runtime-local.py", "scripts/evidence-local.py"):
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
        stage("Generated service identity preflight and strict autoload")
        generated_tools(path, "check:identity")
        stage("Generated service inherited runtime recovery")
        generated_tools(path, "runtime", "--list")
        generated_tools(path, "runtime")
        stage("Build generated service production image")
        run("docker", "build", "--target", "production", "-t", image, ".", cwd=path)
        record_image(image)
        stage("Generated service production container smoke")
        smoke(image, runtime, path)
    finally:
        try:
            remove_image(image)
        finally:
            remove_generated_dependencies(path)
    print("\n  PASS  Generated service acceptance", flush=True)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("kind", choices=("smoke", "generated"))
    parser.add_argument("--network-matrix", action="store_true")
    args = parser.parse_args(argv)
    if args.network_matrix and args.kind != "smoke":
        parser.error("--network-matrix requires smoke")
    print(f"uLaxys  /  ACCEPTANCE  /  {args.kind.upper()}\n", flush=True)
    try:
        validate_source(args.kind)
        # All env credentials and generated service files are temporary.
        with tempfile.TemporaryDirectory(prefix="ulaxys-acceptance-") as temp:
            with DisposableRuntime(Path(temp)) as runtime:
                if args.kind == "smoke":
                    accept_smoke(runtime, matrix=args.network_matrix)
                else:
                    accept_generated(runtime, Path(temp))
        return 0
    except (OSError, RuntimeError, subprocess.SubprocessError) as exc:
        print(f"  FAIL  {exc}", file=sys.stderr, flush=True)
        return 1


if __name__ == "__main__":
    # Keep the caller's console encoding; escape only characters it cannot represent.
    # Python subprocess pipes use explicit UTF-8, independent of the Windows code page.
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, "reconfigure"):
            stream.reconfigure(errors="backslashreplace")
    sys.exit(main())
