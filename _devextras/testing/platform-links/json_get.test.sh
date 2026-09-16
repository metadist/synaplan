#!/usr/bin/env bash
# Isolated regression for json_get: bare keys and jq paths must both work.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
# lib.sh creates a cookie jar and a cleanup trap at source time; that is
# harmless here (the trap only removes the jar).
# shellcheck source=lib.sh
source "$ROOT/lib.sh"

PASS=0
FAIL=0

assert_eq() {
  local label="$1" got="$2" want="$3"
  if [[ "$got" == "$want" ]]; then
    echo "PASS  $label"
    PASS=$((PASS + 1))
  else
    echo "FAIL  $label (got '$got', want '$want')"
    FAIL=$((FAIL + 1))
  fi
}

TMP="$(mktemp)"
cat >"$TMP" <<'JSON'
{"instance_id":"abc-123","nested":{"status":"active"}}
JSON

assert_eq "bare key" "$(json_get "$TMP" instance_id)" "abc-123"
assert_eq "dotted path" "$(json_get "$TMP" .instance_id)" "abc-123"
assert_eq "nested dotted path" "$(json_get "$TMP" nested.status)" "active"

rm -f "$TMP"

echo
echo "Result: $PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]
