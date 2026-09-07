#!/usr/bin/env bash
# Shared helpers for the partner-instance handshake harness.
#
# Not part of the PHPUnit gate — the equivalent assertions live in
# backend/tests/Controller/PlatformLinkControllerTest.php
#
# Requirements: bash, curl, and either jq or python3. Runs against the local
# Docker stack.

set -euo pipefail

BASE="${SYNAPLAN_BASE_URL:-http://localhost:8000}"
EMAIL="${SYNAPLAN_EMAIL:-demo@synaplan.com}"
PASSWORD="${SYNAPLAN_PASSWORD:-demo123}"
ADMIN_EMAIL="${SYNAPLAN_ADMIN_EMAIL:-admin@synaplan.com}"
ADMIN_PASSWORD="${SYNAPLAN_ADMIN_PASSWORD:-admin123}"

COOKIE_JAR="$(mktemp -t synaplan-platform-links-cookies.XXXXXX)"

PASS=0
FAIL=0

cleanup() { rm -f "$COOKIE_JAR"; }
trap cleanup EXIT

json_get() {
  local file="$1" path="$2"
  if command -v jq >/dev/null 2>&1; then
    jq -r "$path" "$file"
    return
  fi
  python3 - "$file" "$path" <<'PY'
import json, sys
data = json.load(open(sys.argv[1]))
path = sys.argv[2].lstrip(".")
cur = data
for part in path.split("."):
    if part == "":
        continue
    if isinstance(cur, dict):
        cur = cur.get(part)
    else:
        cur = None
        break
print("" if cur is None else cur)
PY
}

require_tools() {
  if ! command -v curl >/dev/null 2>&1; then
    echo "ERROR: 'curl' is required but not installed." >&2
    exit 1
  fi
  if ! command -v jq >/dev/null 2>&1 && ! command -v python3 >/dev/null 2>&1; then
    echo "ERROR: 'jq' or 'python3' is required to parse JSON." >&2
    exit 1
  fi
}

assert() {
  local name="$1" cond="$2"
  if eval "$cond"; then
    echo "PASS  $name"
    PASS=$((PASS + 1))
  else
    echo "FAIL  $name  (cond: $cond)"
    FAIL=$((FAIL + 1))
  fi
}

assert_eq() {
  local name="$1" actual="$2" expected="$3"
  if [[ "$actual" == "$expected" ]]; then
    echo "PASS  $name"
    PASS=$((PASS + 1))
  else
    echo "FAIL  $name  (got: '$actual', want: '$expected')"
    FAIL=$((FAIL + 1))
  fi
}

summary() {
  echo
  echo "Result: $PASS passed, $FAIL failed"
  [[ "$FAIL" -eq 0 ]]
}

enable_flag() {
  if [[ "${SYNAPLAN_SKIP_FLAG:-0}" == "1" ]]; then
    echo "NOTE  Skipping flag enable (SYNAPLAN_SKIP_FLAG=1)."
    return 0
  fi
  if ! command -v docker >/dev/null 2>&1; then
    echo "NOTE  docker not found — enable the flag manually:"
    echo "      UPDATE BCONFIG SET BVALUE='1' WHERE BGROUP='PLATFORM_LINKS' AND BSETTING='ENABLED';"
    return 0
  fi
  docker compose exec -T db mariadb -usynaplan_user -psynaplan_password synaplan \
    -e "INSERT INTO BCONFIG (BOWNERID,BGROUP,BSETTING,BVALUE) VALUES (0,'PLATFORM_LINKS','ENABLED','1')
        ON DUPLICATE KEY UPDATE BVALUE='1';" >/dev/null 2>&1 \
    && echo "NOTE  PLATFORM_LINKS.ENABLED set to 1." \
    || echo "NOTE  Could not auto-enable flag; set BCONFIG PLATFORM_LINKS.ENABLED=1 manually."
}

disable_flag() {
  docker compose exec -T db mariadb -usynaplan_user -psynaplan_password synaplan \
    -e "INSERT INTO BCONFIG (BOWNERID,BGROUP,BSETTING,BVALUE) VALUES (0,'PLATFORM_LINKS','ENABLED','0')
        ON DUPLICATE KEY UPDATE BVALUE='0';" >/dev/null 2>&1 \
    && echo "NOTE  PLATFORM_LINKS.ENABLED set to 0." \
    || true
}

login() {
  local email="${1:-$EMAIL}"
  local password="${2:-$PASSWORD}"
  local status
  status=$(curl -sS -o /tmp/syn-pl-login.json -w '%{http_code}' \
    -c "$COOKIE_JAR" \
    -X POST "$BASE/api/v1/auth/login" \
    -H 'content-type: application/json' \
    -d "{\"email\":\"$email\",\"password\":\"$password\"}")
  if [[ "$status" != "200" ]]; then
    echo "ERROR: login failed for $email (HTTP $status). Body:" >&2
    cat /tmp/syn-pl-login.json >&2
    exit 1
  fi
}

http_json() {
  local method="$1" path="$2" body="${3:-}"
  local args=(-sS -o /tmp/syn-pl-body.json -w '%{http_code}' -b "$COOKIE_JAR" -X "$method" "$BASE$path")
  if [[ -n "$body" ]]; then
    args+=(-H 'content-type: application/json' -d "$body")
  fi
  curl "${args[@]}"
}
