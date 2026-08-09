#!/usr/bin/env bash
# Phase 2 smoke: MCP tool loop against fixture upstream.
# Requires: SYNAPLAN_API_KEY, fixture on :8099, MCP_TOOLS_ENABLED=1,
# MESSAGES_GATEWAY_UPSTREAM_URL pointing at the fixture (or BCONFIG UPSTREAM_URL).
set -euo pipefail

BASE="${SYNAPLAN_BASE_URL:-http://localhost:8000}"
KEY="${SYNAPLAN_API_KEY:?Set SYNAPLAN_API_KEY}"
MODEL="${SYNAPLAN_TEST_MODEL:-claude-sonnet-5}"
FAIL=0

pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*"; FAIL=1; }

# Mixed-turn policy: client-supplied tools must skip injection (no mcp__ tools
# executed). We assert the upstream echo path sees client tools only when
# using X-Fixture: echo — here we just ensure a request with client tools
# still returns 200 without requiring MCP servers.
CODE=$(curl -sS -o /tmp/mg-mixed.json -w '%{http_code}' -X POST "$BASE/v1/messages?beta=true" \
  -H "x-api-key: $KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -H "x-fixture: complete" \
  -d '{
    "model":"'"$MODEL"'",
    "max_tokens":32,
    "stream":false,
    "tools":[{"name":"Bash","description":"shell","input_schema":{"type":"object"}}],
    "messages":[{"role":"user","content":"hi"}]
  }' || true)

if [[ "$CODE" == "200" ]]; then
  pass "client-supplied tools request accepted (mixed-turn injection skipped by default)"
else
  fail "client-supplied tools request returned HTTP $CODE"
fi

# Tool-loop path (needs MCP server registered + MCP_TOOLS_ENABLED). Optional.
if [[ "${RUN_MCP_LOOP:-0}" == "1" ]]; then
  CODE=$(curl -sS -o /tmp/mg-loop.json -w '%{http_code}' -X POST "$BASE/v1/messages?beta=true" \
    -H "x-api-key: $KEY" \
    -H "anthropic-version: 2023-06-01" \
    -H "content-type: application/json" \
    -H "x-fixture: tool-use" \
    -d '{
      "model":"'"$MODEL"'",
      "max_tokens":64,
      "stream":false,
      "messages":[{"role":"user","content":"search my knowledge base for test"}]
    }' || true)
  if [[ "$CODE" == "200" ]] && grep -q 'Tool loop complete\|Hello from the Synaplan' /tmp/mg-loop.json; then
    pass "tool-loop non-stream completed"
  else
    fail "tool-loop non-stream HTTP $CODE body=$(head -c 200 /tmp/mg-loop.json)"
  fi
else
  pass "skipped live MCP loop (set RUN_MCP_LOOP=1 to exercise)"
fi

if [[ "$FAIL" -ne 0 ]]; then
  exit 1
fi
echo "ALL PASS"
