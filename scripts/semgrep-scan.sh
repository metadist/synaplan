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

# nosemgrep stays in the SARIF as a suppression. Drop those before upload so a
# commented fixture does not become a code-scanning alert.
if [ -f "$sarif" ] && command -v python3 >/dev/null 2>&1; then
  python3 - "$sarif" <<'PY'
import json
import sys

path = sys.argv[1]
with open(path) as handle:
    data = json.load(handle)
for run in data.get("runs") or []:
    run["results"] = [
        result
        for result in (run.get("results") or [])
        if not result.get("suppressions")
    ]
with open(path, "w") as handle:
    json.dump(data, handle)
PY
fi

if [ "$status" -ne 0 ]; then
  summarized=false
  if [ -f "$sarif" ] && command -v python3 >/dev/null 2>&1; then
    if python3 - "$sarif" <<'PY' >&2
import json
import sys

data = json.load(open(sys.argv[1]))
count = 0
for run in data.get("runs") or []:
    for result in run.get("results") or []:
        count += 1
        location = (result.get("locations") or [{}])[0].get("physicalLocation") or {}
        path = (location.get("artifactLocation") or {}).get("uri") or "?"
        line = (location.get("region") or {}).get("startLine") or "?"
        rule = result.get("ruleId") or "?"
        print(f"{path}:{line}: {rule}")
if count == 0:
    sys.exit(1)
PY
    then
      summarized=true
    fi
  fi
  if [ "$summarized" != true ]; then
    tail -n 40 "$log" >&2
  fi
  exit "$status"
fi

echo "Semgrep: no findings"
