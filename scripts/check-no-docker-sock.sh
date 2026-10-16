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
# ci.yml only invokes this guard itself.
allowed_files = {".github/workflows/compute-nightly.yml", ".github/workflows/ci.yml"}
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
    if path.name in {"docker-compose.yml", "compose.yaml"}:
        continue
    if path.name == "check-no-docker-sock.sh":
        continue
    bad.append(rel)

def check_compose(path: pathlib.Path) -> None:
    if not path.is_file():
        return
    service = None
    for i, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        stripped = line.lstrip()
        if stripped.startswith("#"):
            continue
        if line.startswith("  ") and not line.startswith("    ") and line.rstrip().endswith(":"):
            service = line.strip()[:-1]
        if "docker.sock" in line and service != "compute":
            bad.append(f"{path.as_posix()}:{i}: service {service}")

check_compose(pathlib.Path("docker-compose.yml"))
check_compose(pathlib.Path("deploy/compose.yaml"))

if bad:
    print("docker.sock must appear only on the compute service:")
    print("\n".join(bad))
    sys.exit(1)
print("check-no-docker-sock: ok")
PY
