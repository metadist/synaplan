#!/usr/bin/env bash
# PHP is outside GitHub CodeQL. This scan is the CI gate for that gap, and
# for secret patterns in the tree. GitHub secret scanning still runs on the
# push; a finding here is what turns red on All Checks Passed.
#
# Same command locally: make semgrep
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

# semgrep/semgrep 1.187.0
image="semgrep/semgrep:1.187.0@sha256:0c8dfa7aa1ca8c50233ec9110e8cd88d139dedd603d56507493f74c6f4b3e062"
sarif="${SEMGREP_SARIF:-semgrep.sarif}"

if ! command -v docker >/dev/null 2>&1; then
  echo "docker is required to run the Semgrep scan" >&2
  exit 1
fi

log="$(mktemp)"
trap 'rm -f "$log"' EXIT

status=0
# stdout is the SARIF document when --sarif is set. Keep it out of the CI log:
# a finding's match text can be a secret. The summary below prints only the
# path, line, and rule.
docker run --rm \
  -v "$root:/src" \
  -w /src \
  -e HOME=/tmp \
  -e SEMGREP_SEND_METRICS=off \
  -u "$(id -u):$(id -g)" \
  "$image" \
  semgrep scan \
    --metrics=off \
    --disable-version-check \
    --error \
    --quiet \
    --sarif \
    --sarif-output "$sarif" \
    --config p/php \
    --config p/secrets \
  >"$log" 2>&1 || status=$?

# Findings whose tokens are all in .github/dummy-secrets.allow are dropped.
# Anything else fails the gate. The filter prints path, line, and rule only.
if [ ! -f "$sarif" ] || ! command -v python3 >/dev/null 2>&1; then
  echo "Semgrep failed (exit ${status}). The scan log was omitted because it can contain a matched secret." >&2
  if [ "$status" -eq 0 ]; then
    exit 1
  fi
  exit "$status"
fi

filter_status=0
python3 "$root/scripts/semgrep-filter-allowlist.py" "$sarif" "$root/.github/dummy-secrets.allow" || filter_status=$?
if [ "$filter_status" -ne 0 ]; then
  exit "$filter_status"
fi
if [ "$status" -ne 0 ] && [ "$status" -ne 1 ]; then
  echo "Semgrep failed (exit ${status}). The scan log was omitted because it can contain a matched secret." >&2
  exit "$status"
fi

echo "Semgrep: no findings"
