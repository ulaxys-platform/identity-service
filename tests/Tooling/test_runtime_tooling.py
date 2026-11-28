"""Runtime CLI orchestration tests; Docker is mocked, not runtime acceptance."""
import contextlib
import importlib.util
import io
from pathlib import Path
import unittest
import tempfile
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("runtime_local", ROOT / "scripts/runtime-local.py")
runtime = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(runtime)


class RuntimeToolingTests(unittest.TestCase):
    def test_repeat_invokes_independent_iterations(self):
        with patch.object(runtime, "runtime_iteration") as run, contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(runtime.main(["runtime", "--repeat", "3"]), 0)
        self.assertEqual(run.call_count, 3)

    def test_failure_stops_and_cannot_report_pass(self):
        with patch.object(runtime, "runtime_iteration", side_effect=[None, RuntimeError("failure")]) as run, \
                contextlib.redirect_stdout(io.StringIO()) as stdout, contextlib.redirect_stderr(io.StringIO()):
            self.assertEqual(runtime.main(["runtime", "--repeat", "3"]), 1)
        self.assertEqual(run.call_count, 2)
        self.assertNotIn("PASS:", stdout.getvalue())

    def test_full_runs_verify_smoke_runtime_in_order(self):
        with patch.object(runtime.acceptance, "run") as run, patch.object(runtime, "runtime_iteration") as checks:
            runtime.full_iteration(False)
        self.assertEqual([Path(call.args[1]).name for call in run.call_args_list],
                         ["verify-local.py", "acceptance-local.py"])
        checks.assert_called_once_with(None, False)

    def test_list_does_not_start_docker(self):
        with patch.object(runtime, "runtime_iteration") as run, contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(runtime.main(["runtime", "--list"]), 0)
        run.assert_not_called()
        self.assertIn("postgres-recovery", output.getvalue())

    def test_extension_registry_rejects_override_and_discovers_service_case(self):
        with tempfile.TemporaryDirectory() as tmp, patch.object(runtime, "ROOT", Path(tmp)):
            target = Path(tmp) / "tests/Runtime/service_cases.py"
            target.parent.mkdir(parents=True)
            target.write_text("def check(container, runtime): pass\nCASES = {'service-example': check}\n", encoding="utf-8")
            self.assertIn("service-example", runtime.registry())
            target.write_text("def check(container, runtime): pass\nCASES = {'postgres-recovery': check}\n", encoding="utf-8")
            with self.assertRaisesRegex(RuntimeError, "Invalid service runtime case"):
                runtime.registry()

    def test_benchmark_reports_real_samples_and_rejects_invalid_latency(self):
        import json
        with patch.object(runtime, "probe", side_effect=[{"status": 200, "milliseconds": n} for n in range(1, 31)] * 2), contextlib.redirect_stdout(io.StringIO()) as output:
            runtime.benchmark("owned")
        rows = [json.loads(line[len("EVIDENCE benchmark "):]) for line in output.getvalue().splitlines()
                if line.startswith("EVIDENCE benchmark ")]
        self.assertEqual(len(rows), 2)
        self.assertEqual((rows[0]["sampleCount"], rows[0]["medianMs"], rows[0]["p95Ms"], rows[0]["maxMs"]), (30, 15.5, 29, 30))
        for value in (float("nan"), -1, True):
            with patch.object(runtime, "probe", return_value={"status": 200, "milliseconds": value}):
                with self.assertRaisesRegex(RuntimeError, "Invalid benchmark latency"):
                    runtime.benchmark("owned")

    def test_unknown_filter_fails_before_execution(self):
        with patch.object(runtime, "runtime_iteration") as run, contextlib.redirect_stderr(io.StringIO()):
            with self.assertRaises(SystemExit):
                runtime.main(["runtime", "--filter", "missing"])
        run.assert_not_called()


if __name__ == "__main__":
    unittest.main()
