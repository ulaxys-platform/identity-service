#!/usr/bin/env python3
"""Safe, cross-platform cleanup of checkout-local developer caches.

Never access application cache backends, databases, Docker volumes, user
uploads, generated integration bundles, or live deployment containers.
"""
from __future__ import annotations

import argparse
import os
from pathlib import Path
import shutil
import sys

ROOT = Path(__file__).resolve().parent.parent

# All paths here are relative to the current service checkout. Never walk
# vendor, dependencies, user uploads, generated output, or private data.
PYTHON_SOURCE_ROOTS = (
    "scripts", "tests", "app", "bootstrap", "config", "database", "routes", ".docker",
)
WALK_SKIP = frozenset({
    ".git", ".idea", ".vscode", "vendor", "node_modules", "generated",
    "storage", ".local", ".cache", "coverage", "test-results", ".venv",
})
CATEGORY_NAMES = ("cache", "config", "routes", "events", "compiled", "views", "tests")
COMMAND_CATEGORIES = {
    "clear:cache": ("cache", "tests"),
    "clear:config": ("config",),
    "clear:routes": ("routes",),
    "clear:events": ("events",),
    "clear:compiled": ("compiled",),
    "clear:views": ("views",),
    "clear:tests": ("tests",),
    "clear:all": CATEGORY_NAMES,
}


def source_pycache_paths(root: Path) -> list[Path]:
    """Find only Python-generated caches in known source directories.

    Symlinks are never traversed or removed. The project root itself is not
    recursively walked, which prevents touching arbitrary user data.
    """
    found: list[Path] = []
    for name in PYTHON_SOURCE_ROOTS:
        source = root / name
        if source.is_symlink() or not source.is_dir():
            continue
        for current, directories, _ in os.walk(source, followlinks=False):
            current_path = Path(current)
            filtered: list[str] = []
            for directory in directories:
                candidate = current_path / directory
                if candidate.is_symlink() or directory in WALK_SKIP:
                    continue
                if directory == "__pycache__":
                    found.append(candidate)
                else:
                    filtered.append(directory)
            directories[:] = filtered
    return found


def scoped_file(path: Path, suffixes: tuple[str, ...] | None = None) -> list[Path]:
    if path.is_symlink() or not path.is_file():
        return []
    if suffixes is not None and path.suffix not in suffixes:
        return []
    return [path]


def scoped_directory_files(root: Path, directory: str, pattern: str) -> list[Path]:
    folder = root / directory
    if folder.is_symlink() or not folder.is_dir():
        return []
    return [item for item in folder.glob(pattern) if item.is_file() and not item.is_symlink()]


def targets(root: Path, category: str) -> list[Path]:
    if category == "cache":
        result = source_pycache_paths(root)
        result += scoped_directory_files(root, ".", "*.pyc")
        result += scoped_directory_files(root, ".", "*.pyo")
        return sorted(set(result))
    if category == "config":
        return scoped_file(root / "bootstrap/cache/config.php")
    if category == "routes":
        return sorted(set(
            scoped_directory_files(root, "bootstrap/cache", "routes-*.php")
            + scoped_file(root / "bootstrap/cache/routes.php")
        ))
    if category == "events":
        return scoped_file(root / "bootstrap/cache/events.php")
    if category == "compiled":
        return sorted(set(
            scoped_file(root / "bootstrap/cache/services.php")
            + scoped_file(root / "bootstrap/cache/packages.php")
        ))
    if category == "views":
        # Only Blade compiled PHP files, never user uploads or .gitignore.
        return scoped_directory_files(root, "storage/framework/views", "*.php")
    if category == "tests":
        return sorted(set(
            scoped_file(root / ".phpunit.result.cache")
            + source_test_caches(root)
        ))
    raise ValueError(f"Unknown category: {category}")


def source_test_caches(root: Path) -> list[Path]:
    result = []
    for name in (".phpunit.cache", ".pytest_cache"):
        candidate = root / name
        if candidate.is_symlink():
            continue
        if candidate.is_dir():
            result.append(candidate)
    return result


def safe_within_checkout(root: Path, path: Path) -> bool:
    """Reject paths outside the checkout or passing through symlink directories."""
    try:
        relative = path.relative_to(root)
    except ValueError:
        return False
    if not relative.parts or ".." in relative.parts:
        return False
    current = root
    for part in relative.parts:
        current = current / part
        if current.is_symlink():
            return False
    return True


def remove_target(path: Path) -> None:
    if path.is_symlink():
        raise RuntimeError(f"Refusing symlink: {path}")
    if path.is_dir():
        shutil.rmtree(path)
    elif path.is_file():
        path.unlink()


def execute(action: str, root: Path = ROOT) -> int:
    if action == "cache:status":
        print("uLaxys  /  CACHE  /  LOCAL\n")
        total = 0
        for category in CATEGORY_NAMES:
            count = len([p for p in targets(root, category) if safe_within_checkout(root, p)])
            total += count
            print(f"  {category.capitalize():<18} {count:>4} item(s)")
        print(f"\n  {total} local cache item(s); no changes made")
        return 0
    print(f"uLaxys  /  CLEAR  /  {action.removeprefix('clear:').upper()}\n")
    selected = COMMAND_CATEGORIES[action]
    removed = 0
    seen: set[Path] = set()
    for category in selected:
        candidates = [path for path in targets(root, category) if path not in seen]
        unsafe = [path for path in candidates if not safe_within_checkout(root, path)]
        if unsafe:
            raise RuntimeError(f"Refusing path outside safe checkout scope: {unsafe[0]}")
        try:
            for path in candidates:
                remove_target(path)
                seen.add(path)
                removed += 1
        except OSError as exc:
            print(f"  {category.capitalize():<18} FAIL  {exc}", file=sys.stderr)
            return 1
        print(f"  {category.capitalize():<18} PASS  ({len(candidates)} item(s))")
    print(f"\n  PASS  {removed} local cache item(s) removed")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("action", choices=(*COMMAND_CATEGORIES, "cache:status"))
    arguments = parser.parse_args(argv)
    try:
        return execute(arguments.action)
    except (OSError, RuntimeError, ValueError) as exc:
        print(f"  FAIL  {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
