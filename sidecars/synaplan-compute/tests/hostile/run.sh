#!/usr/bin/env bash
# Seed of C6: list the corpus and show how to submit it to a running service.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"
echo "hostile corpus scripts:"
find tests/hostile -name '*.py' -o -name '*.js' | sort
echo
echo "Hermetic checks:   go test ./tests/hostile/ && node --check tests/hostile/node/*.js"
echo "Live corpus:       COMPUTE_HOSTILE_DOCKER=1 COMPUTE_URL=http://127.0.0.1:8080 COMPUTE_AUTH_TOKEN=... go test ./tests/hostile/"
echo "Submit one script: examples/csv-chart/run.sh shows the multipart shape (request.json + declared file parts)."
