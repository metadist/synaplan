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
#   5. BCONFIG MESSAGES_GATEWAY: ENABLED=1, ALLOW_OPERATOR_KEY=1, WEB_SEARCH_ENABLED=1
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

echo "== Messages gateway web search smoke =="
echo "BASE=$BASE MODEL=$MODEL"
echo

# --- 1. What actually leaves the gateway -------------------------------------
CODE=$(curl -sS -o /tmp/mgw-ws-echo.json -w '%{http_code}' \
  -X POST "$BASE/v1/messages?beta=true" \
  -H "x-api-key: $KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -H "X-Fixture: echo" \
  -d "{\"model\":\"$MODEL\",\"max_tokens\":64,\"tools\":[$SERVER_TOOL],\"messages\":[{\"role\":\"user\",\"content\":\"what is new in synaplan?\"}]}")

# Guard the negative assertions below: on an error response they would pass for
# the wrong reason.
assert "echo request reached the upstream (HTTP 200)" "[[ '$CODE' == '200' ]]"

FORWARDED=$(python3 -c '
import json,sys
body = json.load(open("/tmp/mgw-ws-echo.json")).get("fixture_received_body") or {}
json.dump(body.get("tools", []), sys.stdout)
' 2>/dev/null || echo '[]')

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

echo
echo "Result: $PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]
