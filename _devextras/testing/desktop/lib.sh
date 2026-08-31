#!/usr/bin/env bash
# Shared helpers for the Synaplan Desktop pairing + check-in harness.
#
# These scripts are the Phase A acceptance demo and the stand-in for the real
# desktop client (which does not exist yet). They are NOT part of the PHPUnit
# gate — the equivalent assertions live in:
#   backend/tests/Controller/DesktopControllerTest.php
#   backend/tests/Controller/DesktopMcpCheckinTest.php
#   backend/tests/Unit/Service/Desktop/*
#
# Requirements: bash, curl, jq. Runs against the local Docker stack.
#
# Environment:
#   SYNAPLAN_BASE_URL   default http://localhost:8000
#   SYNAPLAN_EMAIL      default demo@synaplan.com
#   SYNAPLAN_PASSWORD   default demo123
#   SYNAPLAN_SKIP_FLAG  set to 1 to skip the automatic BCONFIG flag enable
#   MCP_PROTOCOL        default 2025-11-25 (the MCP transport version, NOT the
#                       desktop job protocol which is always 1)

set -euo pipefail

BASE="${SYNAPLAN_BASE_URL:-http://localhost:8000}"
EMAIL="${SYNAPLAN_EMAIL:-demo@synaplan.com}"
PASSWORD="${SYNAPLAN_PASSWORD:-demo123}"
MCP_PROTOCOL="${MCP_PROTOCOL:-2025-11-25}"

COOKIE_JAR="$(mktemp -t synaplan-desktop-cookies.XXXXXX)"
CREDS_FILE="${SYNAPLAN_DESKTOP_CREDS:-/tmp/synaplan-desktop.creds}"

PASS=0
FAIL=0

cleanup() { rm -f "$COOKIE_JAR"; }
trap cleanup EXIT

require_tools() {
  for tool in curl jq; do
    if ! command -v "$tool" >/dev/null 2>&1; then
      echo "ERROR: '$tool' is required but not installed." >&2
      exit 1
    fi
  done
}

# Boolean/condition assert. NOTE: $cond is eval'd, so ONLY pass conditions that
# reference simple scalar tokens (numbers, lease tokens, function calls) — never
# embed a raw JSON blob (its double quotes destroy the eval quoting). For value
# comparisons use assert_eq, which takes the values as positional args (no eval).
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

# Equality assert without eval — safe for values containing quotes/spaces.
# Args: <name> <actual> <expected>
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

# Enable the DESKTOP_AGENT.ENABLED global flag via the db container. The whole
# surface 404s while it is off (invariant C8), so the harness needs it on.
enable_flag() {
  if [[ "${SYNAPLAN_SKIP_FLAG:-0}" == "1" ]]; then
    echo "NOTE  Skipping flag enable (SYNAPLAN_SKIP_FLAG=1)."
    return 0
  fi
  if ! command -v docker >/dev/null 2>&1; then
    echo "NOTE  docker not found — enable the flag manually:"
    echo "      UPDATE BCONFIG SET BVALUE='1' WHERE BGROUP='DESKTOP_AGENT' AND BSETTING='ENABLED';"
    return 0
  fi
  docker compose exec -T db mariadb -usynaplan_user -psynaplan_password synaplan \
    -e "INSERT INTO BCONFIG (BOWNERID,BGROUP,BSETTING,BVALUE) VALUES (0,'DESKTOP_AGENT','ENABLED','1')
        ON DUPLICATE KEY UPDATE BVALUE='1';" >/dev/null 2>&1 \
    && echo "NOTE  DESKTOP_AGENT.ENABLED set to 1." \
    || echo "NOTE  Could not auto-enable flag; set BCONFIG DESKTOP_AGENT.ENABLED=1 manually."
}

# Log in as the web user, storing session cookies in $COOKIE_JAR.
login() {
  local status
  status=$(curl -sS -o /tmp/syn-login.json -w '%{http_code}' \
    -c "$COOKIE_JAR" \
    -X POST "$BASE/api/v1/auth/login" \
    -H 'content-type: application/json' \
    -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}")
  if [[ "$status" != "200" ]]; then
    echo "ERROR: login failed (HTTP $status). Body:" >&2
    cat /tmp/syn-login.json >&2
    exit 1
  fi
}

# Mint a pairing code (session user). Echoes the code.
mint_code() {
  local status
  status=$(curl -sS -o /tmp/syn-code.json -w '%{http_code}' \
    -b "$COOKIE_JAR" \
    -X POST "$BASE/api/v1/desktop/pairing-codes")
  if [[ "$status" != "201" ]]; then
    echo "ERROR: pairing-code creation failed (HTTP $status). Is the flag on?" >&2
    cat /tmp/syn-code.json >&2
    exit 1
  fi
  jq -r '.code' /tmp/syn-code.json
}

# Exchange a code for a scoped key. Args: <code> <deviceName>. Writes KEY,
# DEVICE_ID, API_BASE_URL to $CREDS_FILE and exports them.
pair_device() {
  local code="$1" name="${2:-Harness laptop}" status
  status=$(curl -sS -o /tmp/syn-pair.json -w '%{http_code}' \
    -X POST "$BASE/api/v1/desktop/pair" \
    -H 'content-type: application/json' \
    -d "{\"code\":\"$code\",\"deviceName\":\"$name\",\"capabilities\":[\"skill.run\"]}")
  if [[ "$status" != "201" ]]; then
    echo "ERROR: pairing failed (HTTP $status). Body:" >&2
    cat /tmp/syn-pair.json >&2
    exit 1
  fi
  DESKTOP_KEY=$(jq -r '.key' /tmp/syn-pair.json)
  DEVICE_ID=$(jq -r '.deviceId' /tmp/syn-pair.json)
  API_BASE_URL=$(jq -r '.apiBaseUrl' /tmp/syn-pair.json)
  export DESKTOP_KEY DEVICE_ID API_BASE_URL
  {
    echo "DESKTOP_KEY=$DESKTOP_KEY"
    echo "DEVICE_ID=$DEVICE_ID"
    echo "API_BASE_URL=$API_BASE_URL"
  } > "$CREDS_FILE"
}

# --- MCP helpers (device side, authenticated by the scoped key) ---------------

MCP_SESSION=""

# Extract a JSON-RPC result from either a plain JSON body or an SSE stream.
_mcp_extract_json() {
  local body="$1"
  if grep -q '^data:' <<<"$body"; then
    grep '^data:' <<<"$body" | tail -1 | sed 's/^data: //'
  else
    echo "$body"
  fi
}

# initialize the MCP session with the desktop key; sets $MCP_SESSION.
mcp_init() {
  local headers body
  curl -sS -D /tmp/syn-mcp-h.txt -o /tmp/syn-mcp-b.txt \
    -X POST "$BASE/mcp" \
    -H "x-api-key: $DESKTOP_KEY" \
    -H 'content-type: application/json' \
    -H 'accept: application/json, text/event-stream' \
    -H "mcp-protocol-version: $MCP_PROTOCOL" \
    -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"initialize\",\"params\":{\"protocolVersion\":\"$MCP_PROTOCOL\",\"capabilities\":{},\"clientInfo\":{\"name\":\"fake-device\",\"version\":\"1.0.0\"}}}"
  MCP_SESSION=$(grep -i '^mcp-session-id:' /tmp/syn-mcp-h.txt | tail -1 | tr -d '\r' | awk '{print $2}')
}

# Call an MCP tool. Args: <name> <arguments-json>. Echoes the JSON-RPC response.
mcp_call() {
  local name="$1" args="$2" body
  body=$(curl -sS \
    -X POST "$BASE/mcp" \
    -H "x-api-key: $DESKTOP_KEY" \
    -H 'content-type: application/json' \
    -H 'accept: application/json, text/event-stream' \
    -H "mcp-protocol-version: $MCP_PROTOCOL" \
    -H "mcp-session-id: $MCP_SESSION" \
    -d "{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"tools/call\",\"params\":{\"name\":\"$name\",\"arguments\":$args}}")
  _mcp_extract_json "$body"
}

# List MCP tool names (one per line).
mcp_tool_names() {
  local body
  body=$(curl -sS \
    -X POST "$BASE/mcp" \
    -H "x-api-key: $DESKTOP_KEY" \
    -H 'content-type: application/json' \
    -H 'accept: application/json, text/event-stream' \
    -H "mcp-protocol-version: $MCP_PROTOCOL" \
    -H "mcp-session-id: $MCP_SESSION" \
    -d '{"jsonrpc":"2.0","id":3,"method":"tools/list","params":{}}')
  _mcp_extract_json "$body" | jq -r '.result.tools[]?.name'
}

# Enqueue a job as the web user (session). Args: <deviceId> <skill> <prompt> [chatId]
# Echoes the HTTP status; leaves the JSON body in /tmp/syn-enq.json.
enqueue_job() {
  local deviceId="$1" skill="$2" prompt="$3" chatId="${4:-null}"
  curl -sS -o /tmp/syn-enq.json -w '%{http_code}' \
    -b "$COOKIE_JAR" \
    -X POST "$BASE/api/v1/desktop/jobs" \
    -H 'content-type: application/json' \
    -d "{\"deviceId\":$deviceId,\"type\":\"skill.run\",\"input\":{\"skill\":\"$skill\",\"prompt\":\"$prompt\"},\"chatId\":$chatId}"
}

# Enqueue with a caller-supplied raw JSON body (session). Args: <body-json>
# Echoes the HTTP status; leaves the JSON body in /tmp/syn-enq.json. Used to
# prove that hostile extra keys (e.g. input.command) never reach the device.
enqueue_job_raw() {
  curl -sS -o /tmp/syn-enq.json -w '%{http_code}' \
    -b "$COOKIE_JAR" \
    -X POST "$BASE/api/v1/desktop/jobs" \
    -H 'content-type: application/json' \
    -d "$1"
}

# Poll a job's status as the web user (session). Args: <jobId>
# Echoes the HTTP status; leaves the JSON body in /tmp/syn-job.json.
get_job() {
  curl -sS -o /tmp/syn-job.json -w '%{http_code}' \
    -b "$COOKIE_JAR" \
    -X GET "$BASE/api/v1/desktop/jobs/$1"
}
