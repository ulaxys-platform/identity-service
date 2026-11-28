#!/usr/bin/env python3
"""Write command-level acceptance evidence, never raw subprocess logs or secrets."""
from __future__ import annotations

import hashlib
import json
import math
import os
from pathlib import Path
import platform
import re
import secrets
import subprocess
import sys
import time
from datetime import datetime, timezone
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent.parent


def git(*args: str) -> str | None:
    try:
        result = subprocess.run(["git", *args], cwd=ROOT, capture_output=True,
                                text=True, encoding="utf-8", errors="replace", check=False, timeout=15)
        return result.stdout.strip() if result.returncode == 0 else None
    except (OSError, subprocess.SubprocessError):
        return None


def identity() -> dict:
    head = git("rev-parse", "HEAD")
    status = git("status", "--porcelain", "--untracked-files=all")
    lock = ROOT / "composer.lock"
    return {
        "commit": head if head and re.fullmatch(r"[0-9a-f]{40,64}", head) else None,
        "dirty": None if status is None else bool(status),
        "composerLockSha256": hashlib.sha256(lock.read_bytes()).hexdigest() if lock.is_file() else None,
    }


def write_evidence(directory: Path, report: dict) -> None:
    # One command, not fabricated per-test JUnit results.
    directory.mkdir(parents=True, exist_ok=False)
    (directory / "acceptance.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    failed = report["exitCode"] != 0
    suite = ET.Element("testsuite", name="ulaxys-command", tests="1",
                       failures=str(int(failed)), time=str(report["durationSeconds"]))
    case = ET.SubElement(suite, "testcase", name=report["command"], classname="ulaxys.cli",
                         time=str(report["durationSeconds"]))
    if failed:
        ET.SubElement(case, "failure", message="Command failed; inspect console output")
    ET.ElementTree(suite).write(directory / "acceptance.xml", encoding="utf-8", xml_declaration=True)


def parse_test_event(line: str) -> dict | None:
    if not line.startswith("EVIDENCE test "):
        return None
    try:
        row = json.loads(line[len("EVIDENCE test "):])
        if set(row) != {"suite", "name", "status", "durationSeconds", "skipReason"}:
            return None
        if row["suite"] not in ("Python", "Foundation", "Database", "References", "PHPUnit", "Dependencies"):
            return None
        if not re.fullmatch(r"[A-Za-z0-9_.:\\\\#-]{1,520}", row["name"]):
            return None
        if row["status"] not in ("PASS", "FAIL", "ERROR", "SKIP"):
            return None
        duration = row["durationSeconds"]
        if type(duration) not in (int, float) or not math.isfinite(duration) or duration < 0:
            return None
        reasons = ("SYMLINK_UNAVAILABLE", "HS_CODE_REFERENCE_REQUIRED", "INVENTORY_REFERENCE_REQUIRED", "EXPECTED_FAILURE", "OTHER_SKIP")
        if (row["skipReason"] not in reasons if row["status"] == "SKIP" else row["skipReason"] is not None):
            return None
        return row
    except (ValueError, TypeError, KeyError, OverflowError):
        return None


def write_test_junit(directory: Path, records: list[dict]) -> None:
    root = ET.Element("testsuites")
    for name in dict.fromkeys(row["suite"] for row in records):
        rows = [row for row in records if row["suite"] == name]
        suite = ET.SubElement(root, "testsuite", name=name, tests=str(len(rows)),
                              failures=str(sum(row["status"] == "FAIL" for row in rows)),
                              errors=str(sum(row["status"] == "ERROR" for row in rows)),
                              skipped=str(sum(row["status"] == "SKIP" for row in rows)),
                              time=str(round(sum(row["durationSeconds"] for row in rows), 6)))
        for row in rows:
            case = ET.SubElement(suite, "testcase", name=row["name"], classname=name,
                                 time=str(row["durationSeconds"]))
            if row["status"] == "SKIP":
                ET.SubElement(case, "skipped", message=row["skipReason"])
            elif row["status"] in ("FAIL", "ERROR"):
                ET.SubElement(case, "failure" if row["status"] == "FAIL" else "error",
                              message="Test did not pass; inspect console output")
    ET.ElementTree(root).write(directory / "tests.xml", encoding="utf-8", xml_declaration=True)


BENCHMARK_ENDPOINTS = ("/healthCheck/apiStatus", "/healthCheck/postgresStatus")


def parse_benchmark_event(line: str) -> dict | None:
    try:
        row = json.loads(line[len("EVIDENCE benchmark "):])
        if not isinstance(row, dict) or set(row) != {"endpoint", "sampleCount", "medianMs", "p95Ms", "maxMs"}:
            return None
        if row["endpoint"] not in BENCHMARK_ENDPOINTS or type(row["sampleCount"]) is not int or row["sampleCount"] != 30:
            return None
        values = [row[key] for key in ("medianMs", "p95Ms", "maxMs")]
        if any(type(value) not in (int, float) or not math.isfinite(value) or value < 0 for value in values):
            return None
        if not values[0] <= values[1] <= values[2]:
            return None
        return row
    except (ValueError, TypeError, KeyError, OverflowError):
        return None


class RuntimeEvidence:
    """Parse only fixed, bounded event fields; no arbitrary log text is retained."""
    def __init__(self):
        self.iterations = []
        self.cleanup = []
        self.benchmarks = []
        self.current = None
        self.active_case = None
        self.invalid = False

    def consume(self, line: str) -> None:
        line = line.strip()
        match = re.fullmatch(r"EVIDENCE iteration-start (runtime|full) ([0-9]{1,4}) ([0-9]{1,4})", line)
        if match:
            mode, index, total = match.groups()
            if self.current is not None or not 1 <= int(index) <= int(total) <= 1000:
                self.invalid = True
                return
            self.current = {"mode": mode, "index": int(index), "requested": int(total),
                            "status": "INCOMPLETE", "durationSeconds": None, "cases": [], "cleanup": [], "imageIds": [], "benchmarks": []}
            self.iterations.append(self.current)
            return
        match = re.fullmatch(r"EVIDENCE iteration-end (runtime|full) ([0-9]{1,4}) (PASS|FAIL) ([0-9]{1,10}\.[0-9]{6})", line)
        if match:
            mode, index, status, duration = match.groups()
            if self.current is None or self.current["mode"] != mode or self.current["index"] != int(index) or self.active_case is not None:
                self.invalid = True
                return
            self.current.update(status=status, durationSeconds=float(duration))
            self.current = None
            return
        if line.startswith("EVIDENCE benchmark "):
            record = parse_benchmark_event(line)
            if (record is None or self.current is None or self.current["mode"] != "runtime"
                    or any(row["endpoint"] == record["endpoint"] for row in self.current["benchmarks"])):
                self.invalid = True
                return
            self.current["benchmarks"].append(record)
            self.benchmarks.append({**record, "iteration": self.current["index"]})
            return
        match = re.fullmatch(r"EVIDENCE case-start ([a-z0-9-]{1,100})", line)
        if match:
            if self.current is None or self.active_case is not None:
                self.invalid = True
                return
            self.active_case = {"name": match[1], "status": "INCOMPLETE", "durationSeconds": None}
            self.current["cases"].append(self.active_case)
            return
        match = re.fullmatch(r"EVIDENCE case-end ([a-z0-9-]{1,100}) (PASS|FAIL) ([0-9]{1,10}\.[0-9]{6})", line)
        if match:
            if self.active_case is None or self.active_case["name"] != match[1]:
                self.invalid = True
                return
            self.active_case.update(status=match[2], durationSeconds=float(match[3]))
            self.active_case = None
            return
        match = re.fullmatch(r"EVIDENCE cleanup (container|network|volume|image) ([a-zA-Z0-9_.:-]{1,128}) absent", line)
        if match:
            record = {"kind": match[1], "name": match[2], "status": "ABSENT"}
            self.cleanup.append(record)
            if self.current is not None:
                self.current["cleanup"].append(record)
        match = re.fullmatch(r"EVIDENCE image-id (sha256:[0-9a-f]{64})", line)
        if match and self.current is not None:
            self.current["imageIds"].append(match[1])


def main(argv: list[str]) -> int:
    before = identity()
    started = datetime.now(timezone.utc).isoformat()
    clock = time.monotonic()
    # Argument values are deliberately not persisted. Only recognized command tokens.
    allowed = {"install", "test", "verify", "smoke", "runtime", "acceptance", "full", "generated", "references", "dependencies"}
    command = " ".join(arg for arg in argv if arg in allowed)
    def option(name: str) -> str | None:
        for index, argument in enumerate(argv):
            if argument == name and index + 1 < len(argv):
                return argv[index + 1]
            if argument.startswith(name + "="):
                return argument.split("=", 1)[1]
        return None

    repeat = option("--repeat")
    selected = option("--filter")
    code = 1
    image_ids = []
    skipped_stages = []
    references = {}
    runtime = RuntimeEvidence()
    test_results = []
    invalid_test_event = False
    active_batch = None
    completed_batches = []
    # Offline unittest output is diagnostic, never live cleanup evidence.
    offline = len(argv) >= 2 and argv[0] == "test" and argv[1] in ("tooling", "generator")
    try:
        with subprocess.Popen([sys.executable, str(ROOT / "scripts/ulaxys-tools.py"), *argv],
                              cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                              text=True, encoding="utf-8", errors="replace",
                              env={**os.environ, "PYTHONDONTWRITEBYTECODE": "1", "PYTHONUNBUFFERED": "1", "ULAXYS_TEST_EVIDENCE": "1",
                                   "PYTHONIOENCODING": "utf-8:backslashreplace", "PYTHONUTF8": "1"}) as process:
            for line in process.stdout:
                print(line, end="", flush=True)
                marker = re.fullmatch(r"EVIDENCE test-batch-(start|end) (Python|Foundation|Database|References|PHPUnit) ([1-9][0-9]{0,6})", line.strip())
                if marker:
                    action, suite, count = marker.groups()
                    iteration = runtime.current["index"] if runtime.current else None
                    if action == "start":
                        if active_batch is not None:
                            invalid_test_event = True
                        active_batch = (suite, int(count), iteration, len(test_results))
                    elif active_batch is None:
                        invalid_test_event = True
                    else:
                        batch_suite, batch_count, batch_iteration, offset = active_batch
                        rows = test_results[offset:]
                        if ((suite, int(count), iteration) != (batch_suite, batch_count, batch_iteration)
                                or len(rows) != batch_count
                                or any(row["suite"] != suite or row["iteration"] != iteration for row in rows)
                                or len({row["name"] for row in rows}) != len(rows)):
                            invalid_test_event = True
                        completed_batches.append((suite, iteration))
                        active_batch = None
                elif line.startswith("EVIDENCE test-batch-"):
                    invalid_test_event = True
                if line.startswith("EVIDENCE test "):
                    record = parse_test_event(line.strip())
                    if record is None:
                        invalid_test_event = True
                    else:
                        if record["suite"] != "Dependencies" and active_batch is None:
                            invalid_test_event = True
                        # Repeated runs preserve occurrence order and outer iteration.
                        record["iteration"] = runtime.current["index"] if runtime.current else None
                        test_results.append(record)
                if not offline:
                    runtime.consume(line)
                image = re.fullmatch(r"EVIDENCE image-id (sha256:[0-9a-f]{64})", line.strip())
                if image:
                    image_ids.append(image.group(1))
                reference = re.fullmatch(r"EVIDENCE reference (HS_CODE|INVENTORY) ([0-9a-f]{40})", line.strip())
                if reference:
                    references[reference.group(1)] = reference.group(2)
                skipped = re.search(r"PASS WITH SKIPS \((\d+)\)\s*$", line)
                if skipped:
                    skipped_stages.append(int(skipped.group(1)))
            code = process.wait()
            if active_batch is not None:
                invalid_test_event = True
            if not code:
                expected_suites = set()
                if command in ("verify", "acceptance full", "acceptance generated") or argv == ["test"]:
                    expected_suites = {"Foundation", "Database"}
                    if "--skip-database" in argv:
                        expected_suites.discard("Database")
                    if (ROOT / "tests/Tooling").is_dir():
                        expected_suites.add("Python")
                elif argv[:2] in (["test", "tooling"], ["test", "generator"]):
                    expected_suites = {"Python"}
                elif argv[:2] in (["test", "foundation"], ["test", "database"], ["test", "references"]):
                    expected_suites = {{"foundation": "Foundation", "database": "Database", "references": "References"}[argv[1]]}
                elif argv[:2] == ["test", "all"] or (argv and argv[0] == "test" and all(arg.startswith("--") for arg in argv[1:])):
                    expected_suites = {"Foundation", "Database"}
                    if (ROOT / "tests/Tooling").is_dir():
                        expected_suites.add("Python")
                # Generated acceptance runs the consumer's suites, not template Python tests.
                if command == "acceptance generated":
                    expected_suites.discard("Python")
                if command == "acceptance full":
                    for iteration in runtime.iterations:
                        actual = [suite for suite, index in completed_batches if index == iteration["index"]]
                        if set(actual) != expected_suites or len(actual) != len(expected_suites):
                            invalid_test_event = True
                elif not expected_suites.issubset({suite for suite, _ in completed_batches}):
                    invalid_test_event = True
            if not code and (invalid_test_event or any(row["status"] in ("FAIL", "ERROR") for row in test_results)):
                code = 1
            if not code and command == "test dependencies":
                names = [row["name"] for row in test_results if row["suite"] == "Dependencies" and row["status"] == "PASS"]
                if not names or len(names) != len(test_results) or len(set(names)) != len(names):
                    invalid_test_event = True
                    code = 1
            if not code and "--benchmarks" in argv:
                if not runtime.iterations or any(
                    {row["endpoint"] for row in iteration["benchmarks"]} != set(BENCHMARK_ENDPOINTS)
                    or iteration["cases"] for iteration in runtime.iterations
                ):
                    runtime.invalid = True
            elif runtime.benchmarks:
                runtime.invalid = True
            if not code and (runtime.invalid or runtime.current is not None):
                code = 1
            if not code and command in ("runtime", "acceptance full"):
                expected = int(repeat) if repeat and re.fullmatch(r"[0-9]{1,4}", repeat) else 1
                mode = "full" if command == "acceptance full" else "runtime"
                if (len(runtime.iterations) != expected or
                        any(row["mode"] != mode or row["index"] != index or row["requested"] != expected or row["status"] != "PASS"
                            or any(case["status"] != "PASS" for case in row["cases"])
                            for index, row in enumerate(runtime.iterations, 1))):
                    code = 1
    except (OSError, KeyboardInterrupt):
        code = 1
    finally:
        report = {
            "schemaVersion": 4,
            "scope": "command-level",
            "reduced": "--skip-database" in argv or selected is not None,
            "repeatRequested": int(repeat) if repeat and re.fullmatch(r"[0-9]{1,4}", repeat) else 1,
            "runtimeFilter": selected if selected and re.fullmatch(r"[a-z0-9-]{1,100}", selected) else None,
            "networkMatrix": "--network-matrix" in argv,
            "benchmarksOnly": "--benchmarks" in argv,
            "benchmarkResults": runtime.benchmarks,
            "benchmarkPolicy": "diagnostic-only; no latency threshold" if "--benchmarks" in argv else None,
            "imageIds": image_ids,
            "iterations": runtime.iterations,
            "iterationsCompleted": sum(row["status"] == "PASS" for row in runtime.iterations),
            "cleanup": runtime.cleanup,
            "runtimeEvidenceValid": not runtime.invalid and runtime.current is None,
            "referenceCommits": references,
            "testResults": test_results,
            "testEvidenceValid": not invalid_test_event,
            "testSummary": {status: sum(row["status"] == status for row in test_results)
                            for status in ("PASS", "FAIL", "ERROR", "SKIP")},
            "reportedSkippedStageCounts": skipped_stages,
            "command": command,
            "startedAt": started,
            "durationSeconds": round(time.monotonic() - clock, 3),
            "platform": platform.system(),
            "python": platform.python_version(),
            "sourceBefore": before,
            "sourceAfter": identity(),
            "exitCode": code,
            "status": ("FAIL" if code else "PASS WITH SKIPS" if skipped_stages else
                       "REDUCED PASS" if "--skip-database" in argv or selected is not None else
                       "BENCHMARK COMPLETE" if "--benchmarks" in argv else "PASS"),
            "qualification": "Zero exit status may include skipped tests; see console output. Not a signed attestation.",
        }
        directory = ROOT / "test-results" / "acceptance" / secrets.token_hex(12)
        write_evidence(directory, report)
        if test_results:
            write_test_junit(directory, test_results)
        print(f"Evidence: {directory.relative_to(ROOT)}", flush=True)
    return code if code >= 0 else 1


if __name__ == "__main__":
    # Keep the caller's console encoding; escape only characters it cannot represent.
    # Python subprocess pipes use explicit UTF-8, independent of the Windows code page.
    for stream in (sys.stdout, sys.stderr):
        if hasattr(stream, "reconfigure"):
            stream.reconfigure(errors="backslashreplace")
    sys.exit(main(sys.argv[1:]))
