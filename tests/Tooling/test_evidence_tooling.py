"""Offline checks for command evidence; no real Docker or credentials."""
import importlib.util
import json
import os
import subprocess
import sys
from pathlib import Path
import tempfile
import unittest
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("evidence_local", ROOT / "scripts/evidence-local.py")
evidence = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(evidence)


class EvidenceTests(unittest.TestCase):
    def test_evidence_relay_preserves_unicode_and_child_exit_status(self):
        # Use the real evidence entrypoint with a temporary synthetic CLI child.
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            scripts = root / "scripts"
            scripts.mkdir()
            entry = scripts / "evidence-local.py"
            entry.write_bytes((ROOT / "scripts/evidence-local.py").read_bytes())
            (scripts / "ulaxys-tools.py").write_text(
                "import sys\nprint(chr(0x2713))\nprint(chr(0x2500), file=sys.stderr)\n"
                "print('PASS WITH SKIPS (1)')\nsys.exit(7)\n", encoding="utf-8")
            result = subprocess.run([sys.executable, str(entry), "verify", "--verbose"],
                                    env={**os.environ, "PYTHONIOENCODING": "cp1254:strict", "PYTHONUTF8": "0"},
                                    capture_output=True, timeout=30)
            self.assertEqual(result.returncode, 7, result.stderr)
            self.assertIn(b"\\u2713", result.stdout)
            self.assertIn(b"\\u2500", result.stdout)
            report_path, = (root / "test-results/acceptance").glob("*/acceptance.json")
            report = json.loads(report_path.read_text(encoding="utf-8"))
            self.assertEqual(report["status"], "FAIL")
            self.assertEqual(report["exitCode"], 7)
            self.assertEqual(report["reportedSkippedStageCounts"], [1])

    def test_native_batch_relay_rejects_missing_first_record_and_missing_suite(self):
        row = {"suite": "Foundation", "name": "Tests.Example.test_first", "status": "PASS",
               "durationSeconds": 0.1, "skipReason": None}
        second = {**row, "name": "Tests.Example.test_second"}
        start = "EVIDENCE test-batch-start Foundation 2"
        end = "EVIDENCE test-batch-end Foundation 2"
        events = ["EVIDENCE test " + json.dumps(value) for value in (row, second)]
        for label, lines, expected in (
            ("complete", [start, *events, end], 0),
            ("lost-first", [start, "  stage heading " + events[0], events[1], end], 1),
            ("missing-end", [start, *events], 1),
            ("duplicate", [start, events[0], events[0], end], 1),
            ("missing-suite", [], 1),
        ):
            with self.subTest(label=label), tempfile.TemporaryDirectory() as tmp:
                root = Path(tmp)
                scripts = root / "scripts"
                scripts.mkdir()
                entry = scripts / "evidence-local.py"
                entry.write_bytes((ROOT / "scripts/evidence-local.py").read_bytes())
                (scripts / "ulaxys-tools.py").write_text(
                    "for line in " + repr(lines) + ": print(line, flush=True)\n", encoding="utf-8")
                result = subprocess.run([sys.executable, str(entry), "test", "foundation"],
                                        capture_output=True, timeout=30)
                self.assertEqual(result.returncode, expected, result.stderr)
                report_path, = (root / "test-results/acceptance").glob("*/acceptance.json")
                report = json.loads(report_path.read_text(encoding="utf-8"))
                self.assertEqual(report["testEvidenceValid"], expected == 0)
                self.assertEqual(report["exitCode"], expected)
                suite = ET.parse(report_path.with_name("acceptance.xml")).getroot()
                self.assertEqual(suite.get("failures"), str(expected))
                if expected == 0:
                    self.assertEqual(len(report["testResults"]), 2)

    def test_structured_runtime_events_include_cases_and_cleanup(self):
        runtime = evidence.RuntimeEvidence()
        for line in (
            "EVIDENCE iteration-start runtime 1 1",
            "EVIDENCE case-start postgres-recovery",
            "EVIDENCE case-end postgres-recovery PASS 0.123456",
            "EVIDENCE cleanup container owned-app absent",
            "EVIDENCE iteration-end runtime 1 PASS 1.234567",
        ):
            runtime.consume(line)
        self.assertFalse(runtime.invalid)
        self.assertIsNone(runtime.current)
        self.assertEqual(runtime.iterations[0]["cases"][0]["status"], "PASS")
        self.assertEqual(runtime.iterations[0]["cleanup"][0]["name"], "owned-app")

    def test_incomplete_or_unmatched_events_are_not_success(self):
        runtime = evidence.RuntimeEvidence()
        runtime.consume("EVIDENCE iteration-start runtime 1 2")
        runtime.consume("EVIDENCE case-start postgres-recovery")
        runtime.consume("EVIDENCE iteration-end runtime 1 PASS 1.000000")
        self.assertTrue(runtime.invalid)
        self.assertEqual(runtime.iterations[0]["status"], "INCOMPLETE")

    def test_test_event_and_junit_preserve_skip_without_raw_diagnostics(self):
        row = {"suite": "Python", "name": "test_module.Test.test_skip", "status": "SKIP",
               "durationSeconds": 0.1, "skipReason": "SYMLINK_UNAVAILABLE"}
        self.assertEqual(evidence.parse_test_event("EVIDENCE test " + json.dumps(row)), row)
        self.assertIsNone(evidence.parse_test_event("EVIDENCE test " + json.dumps({**row, "skipReason": "secret"})))
        with tempfile.TemporaryDirectory() as tmp:
            evidence.write_test_junit(Path(tmp), [row])
            case = ET.parse(Path(tmp) / "tests.xml").find(".//testcase/skipped")
            self.assertEqual(case.get("message"), "SYMLINK_UNAVAILABLE")

    def test_benchmark_event_validation_and_duplicate_rejection(self):
        row = {"endpoint": "/healthCheck/apiStatus", "sampleCount": 30, "medianMs": 1.2, "p95Ms": 2.4, "maxMs": 3.0}
        event = "EVIDENCE benchmark " + json.dumps(row)
        self.assertEqual(evidence.parse_benchmark_event(event), row)
        for patch in ({"sampleCount": 29}, {"medianMs": 4}, {"maxMs": float("nan")}, {"endpoint": "/other"}):
            self.assertIsNone(evidence.parse_benchmark_event("EVIDENCE benchmark " + json.dumps({**row, **patch})))
        runtime = evidence.RuntimeEvidence()
        runtime.consume("EVIDENCE iteration-start runtime 1 1")
        runtime.consume(event)
        self.assertEqual(runtime.benchmarks, [{**row, "iteration": 1}])
        runtime.consume(event)
        self.assertTrue(runtime.invalid)

    def test_failed_command_is_failed_in_both_formats(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp) / "run"
            report = {"command": "runtime", "exitCode": 1, "durationSeconds": 0.5}
            evidence.write_evidence(directory, report)
            self.assertEqual(json.loads((directory / "acceptance.json").read_text()), report)
            suite = ET.parse(directory / "acceptance.xml").getroot()
            self.assertEqual(suite.attrib["failures"], "1")
            self.assertIsNotNone(suite.find("testcase/failure"))
            with self.assertRaises(FileExistsError):
                evidence.write_evidence(directory, report)
