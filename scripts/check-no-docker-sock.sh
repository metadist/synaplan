#!/usr/bin/env bash
# C2: docker.sock may appear only on the optional compute Compose service.
set -euo pipefail
cd "$(dirname "$0")/.."
python3 - <<'PY'
import pathlib
import sys

root = pathlib.Path(".")
allowed_suffixes = {".md", ".md.gotmpl"}
# The compute nightly mounts the socket to run the sidecar under test and to
# count leftover run containers. It never ships to production.
allowed_files = {".github/workflows/compute-nightly.yml"}
bad: list[str] = []
for path in root.rglob("*"):
    if not path.is_file():
        continue
    rel = path.as_posix()
    if rel in allowed_files:
        continue
    if any(part in {".git", "vendor", "node_modules", "var"} for part in path.parts):
        continue
    if rel.startswith("sidecars/synaplan-compute/"):
        continue
    try:
        text = path.read_text(encoding="utf-8")
    except (UnicodeDecodeError, OSError):
        continue
    if "docker.sock" not in text:
        continue
    if any(path.name.endswith(suffix) for suffix in allowed_suffixes):
        continue
    if path.name == "docker-compose.yml":
        continue
    if path.name == "check-no-docker-sock.sh":
        continue
    bad.append(rel)

compose = pathlib.Path("docker-compose.yml").read_text(encoding="utf-8")
service = None
for i, line in enumerate(compose.splitlines(), 1):
    stripped = line.lstrip()
    if stripped.startswith("#"):
        continue
    if line.startswith("  ") and not line.startswith("    ") and line.rstrip().endswith(":"):
        service = line.strip()[:-1]
    if "docker.sock" in line and service != "compute":
        bad.append(f"docker-compose.yml:{i}: service {service}")

if bad:
    print("docker.sock must appear only on the compute service:")
    print("\n".join(bad))
    sys.exit(1)
print("check-no-docker-sock: ok")
PY
