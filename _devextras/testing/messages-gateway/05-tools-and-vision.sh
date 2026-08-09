#!/usr/bin/env bash
# Smoke: the two remaining "not compatible" reports — client tool calling and
# vision — plus faithful upstream error relay.
#
# Proves, against a fixture upstream:
#   1. a client-declared tool round-trips: the model's tool_use reaches the
#      client untouched, and the client's tool_result is accepted back,
#   2. the same on the streaming path,
#   3. image blocks reach the upstream byte-for-byte (no HTTP 500, no dropped
#      image), on the Messages API and on the OpenAI-compatible endpoint, and
#   4. an upstream 401/429 keeps its status instead of becoming a 500.
#
# Prerequisites:
#   1. docker compose up -d (backend on :8000)
#   2. php -S 127.0.0.1:8099 _devextras/testing/messages-gateway/fixture-upstream.php
#   3. backend/.env: MESSAGES_GATEWAY_UPSTREAM_URL=http://127.0.0.1:8099
#   4. BCONFIG MESSAGES_GATEWAY: ENABLED=1, ALLOW_OPERATOR_KEY=1
#
# Usage:
#   SYNAPLAN_API_KEY=sk_... ./_devextras/testing/messages-gateway/05-tools-and-vision.sh

set -uo pipefail

BASE="${SYNAPLAN_BASE_URL:-http://localhost:8000}"
KEY="${SYNAPLAN_API_KEY:?Set SYNAPLAN_API_KEY to a Synaplan API key}"
MODEL="${SYNAPLAN_TEST_MODEL:-claude-sonnet-5}"
PASS=0
FAIL=0

# A 1x1 transparent PNG — small enough to read in a diff, real enough to decode.
PNG_B64="iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII="

CLIENT_TOOL='{"name":"get_weather","description":"Get the weather for a place","input_schema":{"type":"object","properties":{"location":{"type":"string"}},"required":["location"]}}'

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

echo "== Messages gateway tools + vision smoke =="
echo "BASE=$BASE MODEL=$MODEL"
echo

# --- 1. Client tool calling, non-streaming -----------------------------------
CODE=$(curl -sS -o /tmp/mgw-ct-1.json -w '%{http_code}' \
  -X POST "$BASE/v1/messages" \
  -H "x-api-key: $KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -H "X-Fixture: client-tool" \
  -d "{\"model\":\"$MODEL\",\"max_tokens\":128,\"tools\":[$CLIENT_TOOL],\"messages\":[{\"role\":\"user\",\"content\":\"weather in Bremen?\"}]}")

assert "client tool: HTTP 200" "[[ '$CODE' == '200' ]]"
assert "client tool: gateway relays stop_reason=tool_use" \
  "grep -q '\"stop_reason\":\"tool_use\"' /tmp/mgw-ct-1.json"
assert "client tool: the tool_use block reaches the client" \
  "grep -q '\"name\":\"get_weather\"' /tmp/mgw-ct-1.json && grep -q 'toolu_fixture_client' /tmp/mgw-ct-1.json"
assert "client tool: the model's arguments survive" \
  "grep -q 'Bremen' /tmp/mgw-ct-1.json"

# The client now runs the tool and sends the result back — the half that a
# broken gateway drops.
CODE=$(curl -sS -o /tmp/mgw-ct-2.json -w '%{http_code}' \
  -X POST "$BASE/v1/messages" \
  -H "x-api-key: $KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -H "X-Fixture: client-tool" \
  -d "{\"model\":\"$MODEL\",\"max_tokens\":128,\"tools\":[$CLIENT_TOOL],\"messages\":[
        {\"role\":\"user\",\"content\":\"weather in Bremen?\"},
        {\"role\":\"assistant\",\"content\":[{\"type\":\"tool_use\",\"id\":\"toolu_fixture_client\",\"name\":\"get_weather\",\"input\":{\"location\":\"Bremen\"}}]},
        {\"role\":\"user\",\"content\":[{\"type\":\"tool_result\",\"tool_use_id\":\"toolu_fixture_client\",\"content\":\"CLIENT_TOOL_RESULT 19C\"}]}
      ]}")

assert "client tool: tool_result turn is accepted (HTTP 200)" "[[ '$CODE' == '200' ]]"
assert "client tool: the result reached the model" \
  "grep -q 'ANSWER_FROM_CLIENT_TOOL' /tmp/mgw-ct-2.json && grep -q 'CLIENT_TOOL_RESULT' /tmp/mgw-ct-2.json"

# --- 2. Client tool calling, streaming ---------------------------------------
curl -sS -N --no-buffer -o /tmp/mgw-ct-stream.log \
  -X POST "$BASE/v1/messages" \
  -H "x-api-key: $KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -H "accept: text/event-stream" \
  -H "X-Fixture: client-tool" \
  -d "{\"model\":\"$MODEL\",\"max_tokens\":128,\"stream\":true,\"tools\":[$CLIENT_TOOL],\"messages\":[{\"role\":\"user\",\"content\":\"weather in Bremen?\"}]}"

assert "client tool (stream): tool_use block is streamed to the client" \
  "grep -q '\"type\":\"tool_use\"' /tmp/mgw-ct-stream.log && grep -q 'get_weather' /tmp/mgw-ct-stream.log"
assert "client tool (stream): arguments are streamed" \
  "grep -q 'input_json_delta' /tmp/mgw-ct-stream.log"
assert "client tool (stream): stop_reason=tool_use is signalled" \
  "grep -q 'tool_use' /tmp/mgw-ct-stream.log && grep -q 'message_stop' /tmp/mgw-ct-stream.log"

# --- 3. Vision on the Messages API -------------------------------------------
CODE=$(curl -sS -o /tmp/mgw-vision.json -w '%{http_code}' \
  -X POST "$BASE/v1/messages" \
  -H "x-api-key: $KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -H "X-Fixture: echo" \
  -d "{\"model\":\"$MODEL\",\"max_tokens\":64,\"messages\":[{\"role\":\"user\",\"content\":[
        {\"type\":\"text\",\"text\":\"what is in this image?\"},
        {\"type\":\"image\",\"source\":{\"type\":\"base64\",\"media_type\":\"image/png\",\"data\":\"$PNG_B64\"}}
      ]}]}")

assert "vision: HTTP 200 (was 500 via strlen on array content)" "[[ '$CODE' == '200' ]]"
assert "vision: the image block reaches the upstream" \
  "grep -q '\"type\":\"image\"' /tmp/mgw-vision.json"
assert "vision: the image bytes survive unchanged" \
  "grep -q '$PNG_B64' /tmp/mgw-vision.json"

# --- 4. Vision on the OpenAI-compatible endpoint ------------------------------
# Same content shape as the report: content parts with an image_url. The point
# is that metering no longer blows up on array content, so anything other than
# a 500 means the reported crash is gone.
CODE=$(curl -sS -o /tmp/mgw-vision-oai.json -w '%{http_code}' \
  -X POST "$BASE/v1/chat/completions" \
  -H "Authorization: Bearer $KEY" \
  -H "content-type: application/json" \
  -d "{\"model\":\"$MODEL\",\"max_tokens\":64,\"messages\":[{\"role\":\"user\",\"content\":[
        {\"type\":\"text\",\"text\":\"what is in this image?\"},
        {\"type\":\"image_url\",\"image_url\":{\"url\":\"data:image/png;base64,$PNG_B64\"}}
      ]}]}")

assert "vision (OpenAI-compatible): no HTTP 500 from metering" "[[ '$CODE' != '500' ]]"
assert "vision (OpenAI-compatible): no strlen type error" \
  "! grep -qi 'strlen' /tmp/mgw-vision-oai.json"

# --- 5. Upstream errors keep their status ------------------------------------
CODE=$(curl -sS -o /tmp/mgw-err-401.json -w '%{http_code}' \
  -X POST "$BASE/v1/messages" \
  -H "x-api-key: $KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -H "X-Fixture: error-401" \
  -d "{\"model\":\"$MODEL\",\"max_tokens\":16,\"messages\":[{\"role\":\"user\",\"content\":\"hi\"}]}")

assert "upstream 401 is relayed as 401, not 500" "[[ '$CODE' == '401' ]]"

CODE=$(curl -sS -o /tmp/mgw-err-429.json -w '%{http_code}' \
  -X POST "$BASE/v1/messages" \
  -H "x-api-key: $KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -H "X-Fixture: error-429" \
  -d "{\"model\":\"$MODEL\",\"max_tokens\":16,\"messages\":[{\"role\":\"user\",\"content\":\"hi\"}]}")

assert "upstream 429 is relayed as 429, not 500" "[[ '$CODE' == '429' ]]"

echo
echo "Result: $PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]
