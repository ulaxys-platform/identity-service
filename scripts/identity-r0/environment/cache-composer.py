"""Seed Composer's dist cache from GitHub's canonical archive endpoint."""
import concurrent.futures
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
import zipfile

cache = Path(sys.argv[1]) / "files"
lock = json.loads(Path("composer.lock").read_text())

def cache_archive(package):
    dist = package["dist"]
    match = re.fullmatch(
        r"https://api\.github\.com/repos/([\w.-]+/[\w.-]+)/zipball/([a-f0-9]{40})",
        dist["url"],
    )
    if not match or dist["type"] != "zip" or match[2] != dist["reference"]:
        raise ValueError(f"Unsupported locked distribution: {package['name']}")
    digest = hashlib.sha1(dist["url"].encode()).hexdigest()
    target = cache / package["name"] / f"{digest}.zip"
    target.parent.mkdir(parents=True, exist_ok=True)
    if target.exists():
        return
    temporary = target.with_suffix(".part")
    try:
        subprocess.run(
            ["curl", "--fail", "--silent", "--show-error", "--retry", "2",
             "--max-time", "45", "--output", str(temporary),
             f"https://codeload.github.com/{match[1]}/legacy.zip/{match[2]}"],
            check=True,
        )
        with zipfile.ZipFile(temporary) as archive:
            if archive.testzip() is not None:
                raise ValueError(f"Corrupt archive for {package['name']}")
        if dist.get("shasum") and hashlib.sha1(temporary.read_bytes()).hexdigest() != dist["shasum"]:
            raise ValueError(f"Checksum mismatch for {package['name']}")
        temporary.replace(target)
    finally:
        temporary.unlink(missing_ok=True)

packages = lock["packages"] + lock["packages-dev"]
with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
    list(pool.map(cache_archive, packages))
print(f"Composer archive cache verified for {len(packages)} locked packages")
