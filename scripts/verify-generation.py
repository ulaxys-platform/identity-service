#!/usr/bin/env python3
"""Verify the immutable generation baseline of a uLaxys PHP service.

No network, Git, Composer, Laravel or credentials needed. Only the files
recorded at creation are checked: new business files are outside the baseline.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import sys

SCHEMA_VERSION = 1
GENERATOR_VERSION = "P4.3"
MANIFEST = Path(".ulaxys/generation.json")
HASH = re.compile(r"^[0-9a-f]{64}$")
COMMIT = re.compile(r"^[0-9a-f]{40,64}$")


class ManifestError(Exception):
    pass


def file_inventory(root: Path) -> dict[str, str]:
    """Inventory regular files only, before dependency installation."""
    result = {}
    for path in sorted(root.rglob("*")):
        relative = path.relative_to(root)
        if relative == MANIFEST:
            continue
        if path.is_symlink():
            raise ManifestError(f"Symlink in baseline: {relative.as_posix()}")
        if not path.is_file():
            continue
        name = relative.as_posix()
        if name.startswith(".ulaxys/"):
            raise ManifestError("Unexpected reserved .ulaxys content")
        result[name] = hashlib.sha256(path.read_bytes()).hexdigest()
    return result


def create_manifest(root: Path, commit_sha: str, name: str, namespace: str,
                    package: str, service: str) -> None:
    """Write deterministic manifest exactly once in the staged output."""
    if not COMMIT.fullmatch(commit_sha):
        raise ManifestError("Invalid source Git commit SHA")
    metadata = root / MANIFEST
    if metadata.exists() or metadata.is_symlink():
        raise ManifestError("Refusing to replace existing generation manifest")
    files = file_inventory(root)
    payload = {
        "schemaVersion": SCHEMA_VERSION,
        "generatorVersion": GENERATOR_VERSION,
        "source": {
            "repository": "ulaxys-platform/ulaxys-be-php-template-service",
            "commit": commit_sha,
            "snapshot": "git-archive",
        },
        "service": {
            "name": name,
            "namespace": namespace,
            "composerPackage": package,
            "integrationId": service,
        },
        "files": files,
    }
    metadata.parent.mkdir(parents=True, exist_ok=True)
    metadata.write_text(json.dumps(payload, sort_keys=True, indent=2, ensure_ascii=False) + "\n",
                        encoding="utf-8")


def load_manifest(root: Path) -> dict:
    path = root / MANIFEST
    if path.is_symlink() or not path.is_file():
        raise ManifestError("Generation manifest missing or not a regular file")
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict) or set(data) != {"schemaVersion", "generatorVersion", "source", "service", "files"}:
        raise ManifestError("Unsupported generation manifest structure")
    if data["schemaVersion"] != SCHEMA_VERSION or data["generatorVersion"] != GENERATOR_VERSION:
        raise ManifestError("Unsupported manifest schema/generator version")
    source, service, files = data["source"], data["service"], data["files"]
    if not isinstance(source, dict) or set(source) != {"repository", "commit", "snapshot"}:
        raise ManifestError("Invalid template source metadata")
    if source["repository"] != "ulaxys-platform/ulaxys-be-php-template-service" or source["snapshot"] != "git-archive" or not isinstance(source["commit"], str) or not COMMIT.fullmatch(source["commit"]):
        raise ManifestError("Invalid source provenance")
    if not isinstance(service, dict) or set(service) != {"name", "namespace", "composerPackage", "integrationId"} or not all(isinstance(x, str) and x for x in service.values()):
        raise ManifestError("Invalid service metadata")
    if not isinstance(files, dict) or not files:
        raise ManifestError("Missing baseline file inventory")
    for path, digest in files.items():
        if not isinstance(path, str) or not isinstance(digest, str) or not HASH.fullmatch(digest):
            raise ManifestError("Invalid baseline entry")
        p = Path(path)
        if p.is_absolute() or path != p.as_posix() or ".." in p.parts or not p.parts or p == MANIFEST or path.startswith(".ulaxys/"):
            raise ManifestError("Unsafe baseline path")
    return data


def verify(root: Path) -> tuple[dict, list[str]]:
    data = load_manifest(root)
    failures = []
    for name, expected in sorted(data["files"].items()):
        relative = Path(name)
        # Refuse symlinked ancestors before hashing: a linked directory could
        # otherwise redirect reads outside the generated service checkout.
        if any((root / Path(*relative.parts[:i])).is_symlink()
               for i in range(1, len(relative.parts) + 1)):
            failures.append(f"symlink path: {name}")
            continue
        target = root / relative
        if not target.is_file():
            failures.append(f"missing or unsupported: {name}")
        elif hashlib.sha256(target.read_bytes()).hexdigest() != expected:
            failures.append(f"modified: {name}")
    return data, failures


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("inspect", "verify"))
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parent.parent)
    args = parser.parse_args(argv)
    try:
        root = args.root.resolve(strict=True)
        if args.command == "inspect":
            data = load_manifest(root)
        else:
            data, issues = verify(root)
            if issues:
                for issue in issues:
                    print(f"DRIFT: {issue}", file=sys.stderr)
                print(f"FAIL: {len(issues)} baseline file(s) differ; no changes made.", file=sys.stderr)
                return 1
        print(f"Template source commit: {data['source']['commit']}")
        print(f"Service: {data['service']['name']} ({data['service']['composerPackage']})")
        print(f"Baseline files: {len(data['files'])}")
        if args.command == "verify":
            print("PASS: generation baseline verified (recorded files only).")
        return 0
    except (ManifestError, OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"Generation metadata check failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
