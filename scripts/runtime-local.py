#!/usr/bin/env python3
"""Isolated runtime recovery and full acceptance orchestration (Python 3.10+)."""
from __future__ import annotations

import argparse
import importlib.util
import json
import math
import re
from pathlib import Path
import secrets
import statistics
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parent.parent
SPEC = importlib.util.spec_from_file_location("acceptance_local", ROOT / "scripts/acceptance-local.py")
acceptance = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(acceptance)

CASES = ("postgres-recovery", "application-restart")
BENCHMARK_SAMPLES = 30

# Request only fixed local health endpoints. No arbitrary remote URL or host port.
PROBE = r"""
$start = hrtime(true);
$ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 4]]);
@file_get_contents('http://127.0.0.1:8080'.$argv[1], false, $ctx);
$status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
echo json_encode(['status' => $status, 'milliseconds' => (hrtime(true) - $start) / 1000000]);
"""


def probe(container: str, path: str) -> dict:
    result = acceptance.run("docker", "exec", container, "php", "-r", PROBE, path,
                            capture=True, check=False, timeout=10)
    if result.returncode:
        return {"status": 0, "milliseconds": 0}
    try:
        return json.loads(result.stdout)
    except (ValueError, TypeError):
        return {"status": 0, "milliseconds": 0}


def expect_status(container: str, path: str, status: int, timeout: float = 60) -> None:
    deadline = time.monotonic() + timeout
    observed = None
    while time.monotonic() < deadline:
        observed = probe(container, path)["status"]
        if observed == status:
            return
        time.sleep(0.5)
    raise RuntimeError(f"{path}: expected HTTP {status}, last observed {observed}")


def healthy(container: str) -> None:
    expect_status(container, "/healthCheck/apiStatus", 200)
    expect_status(container, "/healthCheck/postgresStatus", 200)


def postgres_recovery(container: str, runtime) -> None:
    acceptance.run("docker", "stop", "--time", "15", runtime.db, capture=True)
    try:
        expect_status(container, "/healthCheck/apiStatus", 200)
        expect_status(container, "/healthCheck/postgresStatus", 503)
    finally:
        acceptance.run("docker", "start", runtime.db, capture=True)
    healthy(container)


def application_restart(container: str, runtime) -> None:
    acceptance.run("docker", "restart", "--time", "170", container, capture=True)
    healthy(container)


def benchmark(container: str) -> None:
    for path in ("/healthCheck/apiStatus", "/healthCheck/postgresStatus"):
        samples = []
        for _ in range(BENCHMARK_SAMPLES):
            sample = probe(container, path)
            if sample["status"] != 200:
                raise RuntimeError(f"Benchmark probe failed: {path}")
            value = sample.get("milliseconds")
            if type(value) not in (int, float) or not math.isfinite(value) or value < 0:
                raise RuntimeError(f"Invalid benchmark latency: {path}")
            samples.append(float(value))
        ordered = sorted(samples)
        p95 = ordered[(95 * len(ordered) + 99) // 100 - 1]
        record = {"endpoint": path, "sampleCount": len(samples),
                  "medianMs": statistics.median(samples), "p95Ms": p95, "maxMs": max(samples)}
        print("EVIDENCE benchmark " + json.dumps(record, separators=(",", ":"), allow_nan=False), flush=True)
        print(f"BENCHMARK {path}: n={len(samples)} median={record['medianMs']:.3f} ms "
              f"p95={p95:.3f} ms max={max(samples):.3f} ms", flush=True)


def registry() -> dict:
    checks = {"postgres-recovery": postgres_recovery, "application-restart": application_restart}
    # Optional trusted repository code; this is not a sandbox or remote plugin loader.
    extension = ROOT / "tests/Runtime/service_cases.py"
    if extension.exists():
        spec = importlib.util.spec_from_file_location("service_runtime_cases", extension)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        extra = module.CASES
        if not isinstance(extra, dict) or not extra:
            raise RuntimeError("Service runtime CASES must be a nonempty dictionary")
        for name, check in extra.items():
            if (not isinstance(name, str) or not re.fullmatch(r"service-[a-z0-9]+(?:-[a-z0-9]+)*", name)
                    or name in checks or not callable(check)):
                raise RuntimeError("Invalid service runtime case declaration")
            checks[name] = check
    return checks


def runtime_iteration(case: str | None, benchmarks: bool) -> None:
    checks = registry()
    tag = secrets.token_hex(7)
    image = acceptance.image_name("runtime-acceptance", tag)
    container = f"ulaxys-runtime-app-{tag}"
    created = False
    try:
        acceptance.run("docker", "build", "--target", "production", "-t", image, ".")
        acceptance.record_image(image)
        with tempfile.TemporaryDirectory(prefix="ulaxys-runtime-") as directory:
            with acceptance.DisposableRuntime(Path(directory)) as runtime:
                try:
                    acceptance.run("docker", "run", "-d", "--name", container,
                                   "--network", runtime.network, "--env-file",
                                   str(runtime.environment), image, capture=True)
                    created = True
                    healthy(container)
                    if benchmarks:
                        benchmark(container)
                    else:
                        for name, check in checks.items():
                            if case is None or case == name:
                                started = time.monotonic()
                                print(f"EVIDENCE case-start {name}", flush=True)
                                try:
                                    result = check(container, runtime)
                                    if result is not None:
                                        raise RuntimeError(f"Runtime case {name} must return None or raise")
                                except BaseException:
                                    print(f"EVIDENCE case-end {name} FAIL {time.monotonic() - started:.6f}", flush=True)
                                    raise
                                print(f"EVIDENCE case-end {name} PASS {time.monotonic() - started:.6f}", flush=True)
                                print(f"PASS {name}", flush=True)
                finally:
                    if created:
                        acceptance.cleanup_resource("container", container)
    finally:
        acceptance.remove_image(image)


def full_iteration(verbose: bool) -> None:
    acceptance.run(sys.executable, str(ROOT / "scripts/verify-local.py"),
                   *(["--verbose"] if verbose else []))
    acceptance.run(sys.executable, str(ROOT / "scripts/acceptance-local.py"), "smoke", "--network-matrix")
    runtime_iteration(None, False)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=("runtime", "full"))
    parser.add_argument("--repeat", type=int, default=1)
    parser.add_argument("--filter")
    parser.add_argument("--list", action="store_true")
    parser.add_argument("--benchmarks", action="store_true")
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args(argv)
    try:
        checks = registry()
    except Exception as exc:
        print(f"FAIL runtime registry: {type(exc).__name__}", file=sys.stderr)
        return 1
    if args.filter is not None and args.filter not in checks:
        parser.error("unknown runtime case")
    if args.list:
        if args.mode != "runtime" or args.filter or args.benchmarks or args.repeat != 1 or args.verbose:
            parser.error("--list requires runtime without execution options")
        print("\n".join(checks))
        return 0
    if not 1 <= args.repeat <= 1000:
        parser.error("--repeat must be from 1 to 1000")
    if args.mode == "full" and (args.filter or args.benchmarks):
        parser.error("full acceptance cannot filter or replace runtime with benchmarks")
    if args.filter and args.benchmarks:
        parser.error("filter and benchmarks are mutually exclusive")

    completed = 0
    try:
        for iteration in range(1, args.repeat + 1):
            print(f"uLaxys / {args.mode.upper()} / iteration {iteration}/{args.repeat}", flush=True)
            started = time.monotonic()
            print(f"EVIDENCE iteration-start {args.mode} {iteration} {args.repeat}", flush=True)
            try:
                if args.mode == "full":
                    full_iteration(args.verbose)
                else:
                    runtime_iteration(args.filter, args.benchmarks)
            except BaseException:
                print(f"EVIDENCE iteration-end {args.mode} {iteration} FAIL {time.monotonic() - started:.6f}", flush=True)
                raise
            print(f"EVIDENCE iteration-end {args.mode} {iteration} PASS {time.monotonic() - started:.6f}", flush=True)
            completed += 1
    except (OSError, RuntimeError, subprocess.SubprocessError) as exc:
        print(f"FAIL iteration {completed + 1}/{args.repeat}: {exc}", file=sys.stderr, flush=True)
        return 1
    label = "BENCHMARK COMPLETE (no performance threshold)" if args.benchmarks else (
        "TARGETED RUNTIME PASS" if args.filter else "PASS")
    print(f"{label}: {completed}/{args.repeat} iterations", flush=True)
    return 0


if __name__ == "__main__":
    # Keep the caller's console encoding; escape only characters it cannot represent.
    # Python subprocess pipes use explicit UTF-8, independent of the Windows code page.
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, "reconfigure"):
            stream.reconfigure(errors="backslashreplace")
    sys.exit(main())
