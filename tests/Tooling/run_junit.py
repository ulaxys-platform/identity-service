"""Run real unittest discovery and export actual result events to a temporary XML file."""
import argparse
import time
import unittest
import xml.etree.ElementTree as ET


class JUnitResult(unittest.TextTestResult):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.records = []
        self.current = None
        self.started = 0

    def startTest(self, test):
        super().startTest(test)
        self.started = time.monotonic()
        self.current = ET.Element("testcase", name=test.id(), time="0")
        self.records.append(self.current)

    def stopTest(self, test):
        self.current.set("time", f"{time.monotonic() - self.started:.6f}")
        self.current = None
        super().stopTest(test)

    def outcome(self, test, tag, message=""):
        node = self.current
        if node is None:
            node = ET.Element("testcase", name=test.id(), time="0")
            self.records.append(node)
        if node.find(tag) is None:
            ET.SubElement(node, tag, message=message)

    def addFailure(self, test, err):
        super().addFailure(test, err)
        self.outcome(test, "failure")

    def addError(self, test, err):
        super().addError(test, err)
        self.outcome(test, "error")

    def addSkip(self, test, reason):
        super().addSkip(test, reason)
        self.outcome(test, "skipped", reason)

    def addExpectedFailure(self, test, err):
        super().addExpectedFailure(test, err)
        self.outcome(test, "skipped", "Expected failure")

    def addUnexpectedSuccess(self, test):
        super().addUnexpectedSuccess(test)
        self.outcome(test, "failure")

    def addSubTest(self, test, subtest, err):
        super().addSubTest(test, subtest, err)
        if err:
            self.outcome(test, "failure" if issubclass(err[0], test.failureException) else "error")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--pattern", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    suite = unittest.defaultTestLoader.discover("tests/Tooling", pattern=args.pattern)
    result = unittest.TextTestRunner(verbosity=2, buffer=True, resultclass=JUnitResult).run(suite)
    root = ET.Element("testsuite")
    root.extend(result.records)
    ET.ElementTree(root).write(args.output, encoding="utf-8", xml_declaration=True)
    return 0 if result.wasSuccessful() and result.testsRun else 1


if __name__ == "__main__":
    raise SystemExit(main())
