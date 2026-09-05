#!/usr/bin/env bash
# IAM S3 publish-to-group acceptance: admin creates "Sales Helper", shares
# it with use to a group; a member lists it; a non-member GET is 403; revoke.
# Requires a running stack, sharing flags on, and two extra accounts.
# Classifier routing is covered by PromptRepository + characterization —
# this script checks the HTTP path only.
set -euo pipefail

BASE="${SYNAPLAN_BASE_URL:-http://localhost:8000}"
OWNER_EMAIL="${SHARE_OWNER_EMAIL:-demo@synaplan.com}"
OWNER_PASS="${SHARE_OWNER_PASS:-demo123}"
MEMBER_EMAIL="${SHARE_MEMBER_EMAIL:-}"
MEMBER_PASS="${SHARE_MEMBER_PASS:-}"
MEMBER_ID="${SHARE_MEMBER_ID:-}"
OUTSIDER_EMAIL="${SHARE_OUTSIDER_EMAIL:-}"
OUTSIDER_PASS="${SHARE_OUTSIDER_PASS:-}"
GROUP_ID="${SHARE_GROUP_ID:-}"

if [[ -z "${MEMBER_EMAIL}" || -z "${MEMBER_PASS}" || -z "${MEMBER_ID}" || -z "${GROUP_ID}" ]]; then
  echo "Set SHARE_MEMBER_EMAIL, SHARE_MEMBER_PASS, SHARE_MEMBER_ID, and SHARE_GROUP_ID."
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
PROMPT_JSON="$(authed -X POST "${BASE}/api/v1/prompts" -H 'Content-Type: application/json' \
  -d '{"topic":"sales-helper","shortDescription":"Sales Helper","prompt":"You help the sales team.","language":"en"}')"
PROMPT_ID="$(printf '%s' "${PROMPT_JSON}" | json_field '["prompt"]["id"]')"
echo "Created assistant ${PROMPT_ID}"

GRANT="$(authed -X POST "${BASE}/api/v1/shares" -H 'Content-Type: application/json' \
  -d "{\"kind\":\"assistant\",\"resource\":\"${PROMPT_ID}\",\"subjectType\":\"group\",\"subjectId\":${GROUP_ID},\"permission\":\"use\"}")"
echo "${GRANT}" | grep -q '"permission":"use"'
echo "Share granted to group ${GROUP_ID}"

login "${MEMBER_EMAIL}" "${MEMBER_PASS}"
LIST="$(authed "${BASE}/api/v1/prompts")"
echo "${LIST}" | grep -q 'sales-helper'
MEMBER_GET="$(authed "${BASE}/api/v1/prompts/${PROMPT_ID}")"
echo "${MEMBER_GET}" | grep -q '"access":"use"'
echo "Member can list and open Sales Helper"

if [[ -n "${OUTSIDER_EMAIL}" && -n "${OUTSIDER_PASS}" ]]; then
  login "${OUTSIDER_EMAIL}" "${OUTSIDER_PASS}"
  STATUS="$(curl -sS -o /dev/null -w '%{http_code}' -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" \
    "${BASE}/api/v1/prompts/${PROMPT_ID}")"
  if [[ "${STATUS}" != "403" ]]; then
    echo "Expected 403 for non-member GET, got ${STATUS}"
    exit 1
  fi
  echo "Non-member GET is 403"
fi

login "${OWNER_EMAIL}" "${OWNER_PASS}"
authed -X DELETE "${BASE}/api/v1/shares?kind=assistant&resource=${PROMPT_ID}&subjectType=group&subjectId=${GROUP_ID}" \
  | grep -q '"success":true'
echo "Share revoked"

login "${MEMBER_EMAIL}" "${MEMBER_PASS}"
STATUS="$(curl -sS -o /dev/null -w '%{http_code}' -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" \
  "${BASE}/api/v1/prompts/${PROMPT_ID}")"
if [[ "${STATUS}" != "403" ]]; then
  echo "Expected 403 after revoke, got ${STATUS}"
  exit 1
fi

echo "publish-demo.sh OK"
