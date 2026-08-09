#!/usr/bin/env bash
# Smoke: Synaplan's native web search through the Messages gateway.
#
# Proves the three things the Anthropic server tool cannot do through a gateway:
#   1. the `web_search_20250305` declaration is replaced by a runnable tool,
#   2. Synaplan executes the search itself and feeds the results back, and
#   3. the same happens on the streaming path.
#
# Prerequisites:
#   1. docker compose up -d (backend on :8000)
#   2. php -S 127.0.0.1:8099 _devextras/testing/messages-gateway/fixture-upstream.php
#   3. php -S 127.0.0.1:8098 _devextras/testing/messages-gateway/fixture-brave-search.php
#   4. backend/.env:
#        MESSAGES_GATEWAY_UPSTREAM_URL=http://127.0.0.1:8099
#        BRAVE_SEARCH_ENABLED=true
#        BRAVE_SEARCH_API_KEY=fixture-token
#        BRAVE_SEARCH_API_URL=http://127.0.0.1:8098/res/v1
#   5. BCONFIG MESSAGES_GATEWAY: ENABLED=1, ALLOW_OPERATOR_KEY=1
#      (WEB_SEARCH_MODE is set by this script; it defaults to `auto`.)
#
# Usage:
#   SYNAPLAN_API_KEY=sk_... ./_devextras/testing/messages-gateway/04-web-search.sh

set -uo pipefail

BASE="${SYNAPLAN_BASE_URL:-http://localhost:8000}"
KEY="${SYNAPLAN_API_KEY:?Set SYNAPLAN_API_KEY to a Synaplan API key}"
MODEL="${SYNAPLAN_TEST_MODEL:-claude-sonnet-4-6}"
PASS=0
FAIL=0

# The declaration Claude Code sends. Only api.anthropic.com can execute it, so
# through a gateway it has to be swapped for something runnable.
SERVER_TOOL='{"type":"web_search_20250305","name":"web_search","max_uses":5}'

assert() {
  local name="$1"
  local cond="$2"
  if eval "$cond"; then
    echo "PASS  $name"
    PASS=$((PASS + 1))
  else
    echo "FAIL  $name"
    FAIL=$((FAIL + 1))
  fi
}

# Set MESSAGES_GATEWAY.WEB_SEARCH_MODE globally. Empty value clears the row, so
# the code default (`auto`) applies — the state a fresh install is in.
set_mode() {
  local mode="${1:-}"
  local sql="DELETE FROM BCONFIG WHERE BGROUP='MESSAGES_GATEWAY' AND BSETTING='WEB_SEARCH_MODE';"
  if [[ -n "$mode" ]]; then
    sql="$sql INSERT INTO BCONFIG (BOWNERID,BGROUP,BSETTING,BVALUE) VALUES (0,'MESSAGES_GATEWAY','WEB_SEARCH_MODE','$mode');"
  fi
  docker compose exec -T db \
    mariadb -usynaplan_user -psynaplan_password synaplan -e "$sql" 2>/dev/null
}

# What the gateway forwarded upstream, as JSON.
forwarded_tools() {
  python3 -c '
import json,sys
body = json.load(open(sys.argv[1])).get("fixture_received_body") or {}
json.dump(body.get("tools", []), sys.stdout)
' "$1" 2>/dev/null || echo '[]'
}

echo_request() {
  curl -sS -o "$1" -w '%{http_code}' \
    -D "$1.headers" \
    -X POST "$BASE/v1/messages?beta=true" \
    -H "x-api-key: $KEY" \
    -H "anthropic-version: 2023-06-01" \
    -H "content-type: application/json" \
    -H "X-Fixture: echo" \
    -d "{\"model\":\"$MODEL\",\"max_tokens\":64,\"tools\":[$SERVER_TOOL],\"messages\":[{\"role\":\"user\",\"content\":\"what is new in synaplan?\"}]}"
}

# A fresh install has no WEB_SEARCH_MODE row at all.
set_mode ''

echo "== Messages gateway web search smoke =="
echo "BASE=$BASE MODEL=$MODEL"
echo

# --- 1. Default install: what actually leaves the gateway --------------------
CODE=$(echo_request /tmp/mgw-ws-echo.json)

# Guard the negative assertions below: on an error response they would pass for
# the wrong reason.
assert "echo request reached the upstream (HTTP 200)" "[[ '$CODE' == '200' ]]"

FORWARDED=$(forwarded_tools /tmp/mgw-ws-echo.json)

assert "default install needs no flag flipped to answer web search" \
  "grep -qi 'x-synaplan-web-search: synaplan' /tmp/mgw-ws-echo.json.headers"
assert "server-tool declaration is not forwarded upstream" \
  "! echo '$FORWARDED' | grep -q 'web_search_20250305'"
assert "an executable web_search tool is forwarded instead" \
  "echo '$FORWARDED' | grep -q 'input_schema' && echo '$FORWARDED' | grep -q '\"web_search\"'"

# --- 2. Non-streaming: search runs and reaches the model ---------------------
CODE=$(curl -sS -o /tmp/mgw-ws-complete.json -w '%{http_code}' \
  -X POST "$BASE/v1/messages?beta=true" \
  -H "x-api-key: $KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -H "X-Fixture: web-search" \
  -d "{\"model\":\"$MODEL\",\"max_tokens\":256,\"tools\":[$SERVER_TOOL],\"messages\":[{\"role\":\"user\",\"content\":\"what is new in synaplan?\"}]}")

assert "non-stream: HTTP 200" "[[ '$CODE' == '200' ]]"
assert "non-stream: model was offered a runnable search tool" \
  "! grep -q 'NO_WEB_SEARCH_TOOL_OFFERED' /tmp/mgw-ws-complete.json"
assert "non-stream: gateway executed the search" \
  "grep -q 'FIXTURE_SEARCH_HIT' /tmp/mgw-ws-complete.json"
assert "non-stream: results came back inside the answer" \
  "grep -q 'ANSWER_FROM_SEARCH' /tmp/mgw-ws-complete.json"

# --- 3. Streaming: same loop over SSE ----------------------------------------
curl -sS -N --no-buffer -o /tmp/mgw-ws-stream.log \
  -X POST "$BASE/v1/messages?beta=true" \
  -H "x-api-key: $KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -H "accept: text/event-stream" \
  -H "X-Fixture: web-search" \
  -d "{\"model\":\"$MODEL\",\"max_tokens\":256,\"stream\":true,\"tools\":[$SERVER_TOOL],\"messages\":[{\"role\":\"user\",\"content\":\"what is new in synaplan?\"}]}"

assert "stream: SSE frames were received" "grep -q 'message_start' /tmp/mgw-ws-stream.log"
assert "stream: model was offered a runnable search tool" \
  "! grep -q 'NO_WEB_SEARCH_TOOL_OFFERED' /tmp/mgw-ws-stream.log"
assert "stream: gateway executed the search" \
  "grep -q 'FIXTURE_SEARCH_HIT' /tmp/mgw-ws-stream.log"
assert "stream: client never sees the internal tool_use round" \
  "! grep -q 'toolu_fixture_web_search' /tmp/mgw-ws-stream.log"
assert "stream: terminates with message_stop" \
  "grep -q 'message_stop' /tmp/mgw-ws-stream.log"

# --- 4. passthrough: hand the declaration to the AI provider untouched -------
# The plain-passthrough escape hatch: Synaplan stays out of the way so an
# Anthropic org can use its own (citation-carrying) web search.
set_mode passthrough
CODE=$(echo_request /tmp/mgw-ws-passthrough.json)
FORWARDED=$(forwarded_tools /tmp/mgw-ws-passthrough.json)

assert "passthrough: HTTP 200" "[[ '$CODE' == '200' ]]"
assert "passthrough: the client's declaration reaches the provider untouched" \
  "echo '$FORWARDED' | grep -q 'web_search_20250305'"
assert "passthrough: Synaplan does not inject a search tool of its own" \
  "! echo '$FORWARDED' | grep -q 'input_schema'"
assert "passthrough: the response says so" \
  "grep -qi 'x-synaplan-web-search: passthrough' /tmp/mgw-ws-passthrough.json.headers"

# --- 5. off: no search reaches the provider at all ---------------------------
set_mode off
CODE=$(echo_request /tmp/mgw-ws-off.json)
FORWARDED=$(forwarded_tools /tmp/mgw-ws-off.json)

assert "off: HTTP 200" "[[ '$CODE' == '200' ]]"
assert "off: the declaration is stripped before the provider sees it" \
  "! echo '$FORWARDED' | grep -q 'web_search'"
assert "off: the response says so" \
  "grep -qi 'x-synaplan-web-search: off' /tmp/mgw-ws-off.json.headers"

set_mode ''

echo
echo "Result: $PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]
