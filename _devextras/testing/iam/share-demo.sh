#!/usr/bin/env bash
# IAM S2 share acceptance: owner shares a chat with use; member continues;
# revoke drops the share. Requires a running stack, sharing flags on, and
# two accounts. RAG source chips are covered by KnowledgeIsolationTest —
# this script checks the HTTP path only.
set -euo pipefail

BASE="${SYNAPLAN_BASE_URL:-http://localhost:8000}"
OWNER_EMAIL="${SHARE_OWNER_EMAIL:-demo@synaplan.com}"
OWNER_PASS="${SHARE_OWNER_PASS:-demo123}"
MEMBER_EMAIL="${SHARE_MEMBER_EMAIL:-}"
MEMBER_PASS="${SHARE_MEMBER_PASS:-}"
MEMBER_ID="${SHARE_MEMBER_ID:-}"

if [[ -z "${MEMBER_EMAIL}" || -z "${MEMBER_PASS}" || -z "${MEMBER_ID}" ]]; then
  echo "Set SHARE_MEMBER_EMAIL, SHARE_MEMBER_PASS, and SHARE_MEMBER_ID."
  exit 1
fi

COOKIE_JAR="$(mktemp)"
trap 'rm -f "${COOKIE_JAR}"' EXIT

login() {
  local email="$1" pass="$2"
  curl -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" -X POST "${BASE}/api/v1/auth/login" \
    -H 'Content-Type: application/json' \
    -d "{\"email\":\"${email}\",\"password\":\"${pass}\"}" >/dev/null
}

authed() {
  curl -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" "$@"
}

json_field() {
  python3 -c 'import json,sys; print(json.load(sys.stdin)'"$1"')'
}

login "${OWNER_EMAIL}" "${OWNER_PASS}"
CHAT_JSON="$(authed -X POST "${BASE}/api/v1/chats" -H 'Content-Type: application/json' -d '{"title":"Share demo"}')"
CHAT_ID="$(printf '%s' "${CHAT_JSON}" | json_field '["chat"]["id"]')"
echo "Created chat ${CHAT_ID}"

GRANT="$(authed -X POST "${BASE}/api/v1/shares" -H 'Content-Type: application/json' \
  -d "{\"kind\":\"conversation\",\"resource\":\"${CHAT_ID}\",\"subjectType\":\"user\",\"subjectId\":${MEMBER_ID},\"permission\":\"use\"}")"
echo "${GRANT}" | grep -q '"permission":"use"'
echo "Share granted"

login "${MEMBER_EMAIL}" "${MEMBER_PASS}"
MEMBER_CHAT="$(authed "${BASE}/api/v1/chats/${CHAT_ID}")"
echo "${MEMBER_CHAT}" | grep -q '"access":"use"'

CONTINUE="$(authed -X POST "${BASE}/api/v1/chats/${CHAT_ID}/continue")"
COPY_ID="$(printf '%s' "${CONTINUE}" | json_field '["chat"]["id"]')"
echo "${CONTINUE}" | grep -q '"access":"owner"'
if [[ "${COPY_ID}" == "${CHAT_ID}" ]]; then
  echo "Continue must create a new chat id"
  exit 1
fi
echo "Member copy ${COPY_ID}"

login "${OWNER_EMAIL}" "${OWNER_PASS}"
authed -X DELETE "${BASE}/api/v1/shares?kind=conversation&resource=${CHAT_ID}&subjectType=user&subjectId=${MEMBER_ID}" \
  | grep -q '"success":true'
echo "Share revoked"

login "${MEMBER_EMAIL}" "${MEMBER_PASS}"
STATUS="$(curl -sS -o /dev/null -w '%{http_code}' -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" \
  "${BASE}/api/v1/chats/${CHAT_ID}")"
if [[ "${STATUS}" != "403" ]]; then
  echo "Expected 403 after revoke, got ${STATUS}"
  exit 1
fi

echo "share-demo.sh OK"
