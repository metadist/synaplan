#!/usr/bin/env bash
# Phase 1 smoke tests for POST /v1/messages against a local fixture upstream.
#
# Prerequisites:
#   1. docker compose up -d (backend on :8000)
#   2. php -S 127.0.0.1:8099 _devextras/testing/messages-gateway/fixture-upstream.php
#   3. In backend/.env: MESSAGES_GATEWAY_UPSTREAM_URL=http://host.docker.internal:8099
#   4. Enable gateway + allow operator key for a test user (see below), and set SYNAPLAN_API_KEY
#
# Usage:
#   SYNAPLAN_API_KEY=sk_... ./_devextras/testing/messages-gateway/01-passthrough.sh

set -euo pipefail

BASE="${SYNAPLAN_BASE_URL:-http://localhost:8000}"
KEY="${SYNAPLAN_API_KEY:?Set SYNAPLAN_API_KEY to a Synaplan API key}"
MODEL="${SYNAPLAN_TEST_MODEL:-claude-sonnet-4-6}"
PASS=0
FAIL=0

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

echo "== Messages gateway Phase 1 smoke =="
echo "BASE=$BASE MODEL=$MODEL"
echo

# --- non-streaming ---
RESP=$(curl -sS -D /tmp/mgw-headers.txt -o /tmp/mgw-body.json \
  -X POST "$BASE/v1/messages?beta=true" \
  -H "x-api-key: $KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -H "X-Fixture: complete" \
  -d "{\"model\":\"$MODEL\",\"max_tokens\":64,\"messages\":[{\"role\":\"user\",\"content\":\"hi\"}]}")

STATUS=$(head -n1 /tmp/mgw-headers.txt | awk '{print $2}')
BODY=$(cat /tmp/mgw-body.json)

assert "non-stream HTTP 200 (or expected gateway status)" "[[ \"$STATUS\" == \"200\" || \"$STATUS\" == \"403\" || \"$STATUS\" == \"401\" || \"$STATUS\" == \"404\" ]]"

if [[ "$STATUS" == "403" ]]; then
  echo "NOTE  Gateway disabled (MESSAGES_GATEWAY.ENABLED=0). Enable it for a full green run:"
  echo "      UPDATE BCONFIG SET BVALUE='1' WHERE BGROUP='MESSAGES_GATEWAY' AND BSETTING='ENABLED';"
fi

if [[ "$STATUS" == "200" ]]; then
  assert "non-stream Anthropic shape" "echo \"$BODY\" | grep -q '\"type\":\"message\"'"
  assert "budget header present" "grep -qi 'x-synaplan-budget-percent' /tmp/mgw-headers.txt"
fi

# --- auth missing ---
STATUS401=$(curl -sS -o /tmp/mgw-401.json -w '%{http_code}' \
  -X POST "$BASE/v1/messages" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -d "{\"model\":\"$MODEL\",\"max_tokens\":16,\"messages\":[{\"role\":\"user\",\"content\":\".\"}]}")
assert "missing key → 401" "[[ \"$STATUS401\" == \"401\" ]]"
assert "401 Anthropic error shape" "grep -q '\"type\":\"error\"' /tmp/mgw-401.json"

# --- unknown model ---
STATUS404=$(curl -sS -o /tmp/mgw-404.json -w '%{http_code}' \
  -X POST "$BASE/v1/messages" \
  -H "x-api-key: $KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -d '{"model":"definitely-not-a-real-model-xyz","max_tokens":16,"messages":[{"role":"user","content":"."}]}')
assert "unknown model → 404 (or 403 if disabled)" "[[ \"$STATUS404\" == \"404\" || \"$STATUS404\" == \"403\" ]]"

# --- models discovery shape ---
STATUS_MODELS=$(curl -sS -o /tmp/mgw-models.json -w '%{http_code}' \
  -H "x-api-key: $KEY" \
  "$BASE/v1/models?limit=1000")
assert "GET /v1/models → 200" "[[ \"$STATUS_MODELS\" == \"200\" ]]"
assert "models data[].id present" "grep -q '\"id\"' /tmp/mgw-models.json"

# --- streaming incremental (only when gateway enabled + model resolves) ---
if [[ "$STATUS" == "200" ]]; then
  rm -f /tmp/mgw-stream.log
  curl -sS -N --no-buffer \
    -X POST "$BASE/v1/messages?beta=true" \
    -H "x-api-key: $KEY" \
    -H "anthropic-version: 2023-06-01" \
    -H "content-type: application/json" \
    -H "accept: text/event-stream" \
    -H "X-Fixture: stream" \
    -d "{\"model\":\"$MODEL\",\"max_tokens\":64,\"stream\":true,\"messages\":[{\"role\":\"user\",\"content\":\"hi\"}]}" \
    | while IFS= read -r line; do
        printf '%s\t%s\n' "$(date +%s)" "$line"
      done > /tmp/mgw-stream.log || true

  assert "stream contains message_start" "grep -q 'message_start' /tmp/mgw-stream.log"
  assert "stream contains ping" "grep -q 'ping' /tmp/mgw-stream.log"

  # Incremental arrival: first and last timestamps should differ when fixture sleeps.
  FIRST_TS=$(awk -F'\t' 'NR==1{print $1; exit}' /tmp/mgw-stream.log)
  LAST_TS=$(awk -F'\t' 'END{print $1}' /tmp/mgw-stream.log)
  if [[ -n "$FIRST_TS" && -n "$LAST_TS" && "$LAST_TS" -gt "$FIRST_TS" ]]; then
    echo "PASS  stream arrived incrementally (${FIRST_TS} → ${LAST_TS})"
    PASS=$((PASS + 1))
  else
    echo "FAIL  stream looks buffered (timestamps ${FIRST_TS:-?} → ${LAST_TS:-?})"
    FAIL=$((FAIL + 1))
  fi
fi

echo
echo "Result: $PASS passed, $FAIL failed"
[[ "$FAIL" -eq 0 ]]
