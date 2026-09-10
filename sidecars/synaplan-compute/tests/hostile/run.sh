#!/usr/bin/env bash
# Seed of C6: parse headers and optionally submit through a running service.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"
echo "hostile corpus scripts:"
find tests/hostile -name '*.py' -o -name '*.js' | sort
echo "Run 'go test ./tests/hostile/' for header parsing."
echo "Set COMPUTE_HOSTILE_DOCKER=1 against a live stack for T1 execution."
