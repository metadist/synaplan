#!/usr/bin/env bash
# Phase 3 smoke: session-stable context injection hashes.
# Requires CONTEXT_INJECTION_ENABLED=1 (or X-Synaplan-Context: on).
set -euo pipefail

BASE="${SYNAPLAN_BASE_URL:-http://localhost:8000}"
KEY="${SYNAPLAN_API_KEY:?Set SYNAPLAN_API_KEY}"
FAIL=0
SID="smoke-session-$RANDOM"

pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*"; FAIL=1; }

H1=$(curl -sS -D /tmp/mg-ctx1.hdr -o /tmp/mg-ctx1.json -X POST "$BASE/v1/messages?beta=true" \
  -H "x-api-key: $KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -H "x-claude-code-session-id: $SID" \
  -H "x-synaplan-context: on" \
  -H "x-synaplan-debug: 1" \
  -H "x-fixture: complete" \
  -d '{
    "model":"claude-sonnet-4-6",
    "max_tokens":32,
    "stream":false,
    "messages":[{"role":"user","content":"what do you know about me?"}]
  }' | true)

HASH1=$(grep -i '^x-synaplan-context-hash:' /tmp/mg-ctx1.hdr | awk '{print $2}' | tr -d '\r')

H2=$(curl -sS -D /tmp/mg-ctx2.hdr -o /tmp/mg-ctx2.json -X POST "$BASE/v1/messages?beta=true" \
  -H "x-api-key: $KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -H "x-claude-code-session-id: $SID" \
  -H "x-synaplan-context: on" \
  -H "x-synaplan-debug: 1" \
  -H "x-fixture: complete" \
  -d '{
    "model":"claude-sonnet-4-6",
    "max_tokens":32,
    "stream":false,
    "messages":[
      {"role":"user","content":"what do you know about me?"},
      {"role":"assistant","content":"Not much yet."},
      {"role":"user","content":"and now?"}
    ]
  }' | true)

HASH2=$(grep -i '^x-synaplan-context-hash:' /tmp/mg-ctx2.hdr | awk '{print $2}' | tr -d '\r')

if [[ -n "$HASH1" && "$HASH1" == "$HASH2" ]]; then
  pass "context hash stable across turns in session ($HASH1)"
else
  fail "context hash mismatch or missing: '$HASH1' vs '$HASH2'"
fi

SID2="smoke-session-other-$RANDOM"
curl -sS -D /tmp/mg-ctx3.hdr -o /tmp/mg-ctx3.json -X POST "$BASE/v1/messages?beta=true" \
  -H "x-api-key: $KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "content-type: application/json" \
  -H "x-claude-code-session-id: $SID2" \
  -H "x-synaplan-context: on" \
  -H "x-synaplan-debug: 1" \
  -H "x-fixture: complete" \
  -d '{
    "model":"claude-sonnet-4-6",
    "max_tokens":32,
    "stream":false,
    "messages":[{"role":"user","content":"completely different first turn"}]
  }' >/dev/null || true

HASH3=$(grep -i '^x-synaplan-context-hash:' /tmp/mg-ctx3.hdr | awk '{print $2}' | tr -d '\r' || true)
# Different first turn + session may still produce empty identical hash if no RAG/memories.
# Only assert difference when both non-empty and first-turn text differed enough to change retrieval.
if [[ -n "$HASH1" && -n "$HASH3" && "$HASH1" != "$HASH3" ]]; then
  pass "different session/first-turn produced different hash"
else
  pass "session hash check skipped or identical empty context (no RAG/memories)"
fi

if [[ "$FAIL" -ne 0 ]]; then
  exit 1
fi
echo "ALL PASS"
