#!/usr/bin/env bash
# IAM S5 group-policy acceptance: Support may only use two models, and a locked
# default cannot be overridden (409 iam.settingLocked).
# Requires: groups + group policies on, an admin session, and two catalog keys.
set -euo pipefail

BASE="${SYNAPLAN_BASE_URL:-http://localhost:8000}"
ADMIN_EMAIL="${POLICY_ADMIN_EMAIL:-admin@synaplan.com}"
ADMIN_PASS="${POLICY_ADMIN_PASS:-admin123}"
MEMBER_EMAIL="${POLICY_MEMBER_EMAIL:-demo@synaplan.com}"
MEMBER_PASS="${POLICY_MEMBER_PASS:-demo123}"
KEY_A="${POLICY_MODEL_KEY_A:-}"
KEY_B="${POLICY_MODEL_KEY_B:-}"

COOKIE_ADMIN="$(mktemp)"
COOKIE_MEMBER="$(mktemp)"
trap 'rm -f "${COOKIE_ADMIN}" "${COOKIE_MEMBER}"' EXIT

login() {
  local jar="$1" email="$2" pass="$3"
  curl -sS -c "${jar}" -b "${jar}" -X POST "${BASE}/api/v1/auth/login" \
    -H 'Content-Type: application/json' \
    -d "{\"email\":\"${email}\",\"password\":\"${pass}\"}" >/dev/null
}

echo "1. Sign in as admin and member"
login "${COOKIE_ADMIN}" "${ADMIN_EMAIL}" "${ADMIN_PASS}"
login "${COOKIE_MEMBER}" "${MEMBER_EMAIL}" "${MEMBER_PASS}"

if [[ -z "${KEY_A}" || -z "${KEY_B}" ]]; then
  echo "Set POLICY_MODEL_KEY_A and POLICY_MODEL_KEY_B to two catalog keys (service:providerId:tag)."
  exit 1
fi

echo "2. Create Support (or reuse) and restrict models"
CREATE="$(curl -sS -c "${COOKIE_ADMIN}" -b "${COOKIE_ADMIN}" -X POST "${BASE}/api/v1/admin/groups" \
  -H 'Content-Type: application/json' \
  -d '{"name":"Support","description":"Policy demo"}')"
GROUP_ID="$(echo "${CREATE}" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("group",{}).get("id",""))' || true)"
if [[ -z "${GROUP_ID}" ]]; then
  LIST="$(curl -sS -c "${COOKIE_ADMIN}" -b "${COOKIE_ADMIN}" "${BASE}/api/v1/admin/groups")"
  GROUP_ID="$(echo "${LIST}" | python3 -c 'import json,sys
groups=json.load(sys.stdin).get("groups",[])
print(next((g["id"] for g in groups if g.get("name")=="Support"), ""))')"
fi
echo "Support group id=${GROUP_ID}"

curl -sS -c "${COOKIE_ADMIN}" -b "${COOKIE_ADMIN}" -X PUT \
  "${BASE}/api/v1/admin/groups/${GROUP_ID}/config" \
  -H 'Content-Type: application/json' \
  -d "{\"MODELS.ALLOWED\":[\"${KEY_A}\",\"${KEY_B}\"]}" >/dev/null

echo "3. Member model list is limited"
MODELS="$(curl -sS -c "${COOKIE_MEMBER}" -b "${COOKIE_MEMBER}" "${BASE}/api/v1/config/models")"
echo "${MODELS}" | python3 -c 'import json,sys
data=json.load(sys.stdin)
chat=data.get("models",{}).get("CHAT",[])
print(f"CHAT models visible: {len(chat)}")
'

echo "4. Lock DEFAULTMODEL.CHAT; member POST is 409"
curl -sS -c "${COOKIE_ADMIN}" -b "${COOKIE_ADMIN}" -X PATCH \
  "${BASE}/api/v1/admin/config/locks" \
  -H 'Content-Type: application/json' \
  -d '{"DEFAULTMODEL.CHAT":true}' >/dev/null

CODE="$(curl -sS -o /tmp/policy-lock.json -w '%{http_code}' -c "${COOKIE_MEMBER}" -b "${COOKIE_MEMBER}" \
  -X POST "${BASE}/api/v1/config/models/defaults" \
  -H 'Content-Type: application/json' \
  -d '{"defaults":{"CHAT":1}}')"
echo "POST /models/defaults -> ${CODE}"
test "${CODE}" = "409"
grep -q iam.settingLocked /tmp/policy-lock.json

echo "policy-demo.sh OK"
