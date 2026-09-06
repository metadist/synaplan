#!/usr/bin/env bash
# IAM S4 directory-sync acceptance against the oidc profile realm.
# Assign a user to a Keycloak group, log in, assert membership; remove,
# log in, assert gone; a manual membership survives.
# Requires: docker compose --profile oidc up -d, IAM.DIRECTORY_SYNC_ENABLED=1,
# and a Keycloak user whose groups claim includes the test group.
set -euo pipefail

BASE="${SYNAPLAN_BASE_URL:-http://localhost:8000}"
EMAIL="${DIRECTORY_USER_EMAIL:-}"
PASS="${DIRECTORY_USER_PASS:-}"
CLAIM_GROUP="${DIRECTORY_CLAIM_GROUP:-sales}"
MANUAL_GROUP_ID="${DIRECTORY_MANUAL_GROUP_ID:-}"

if [[ -z "${EMAIL}" || -z "${PASS}" ]]; then
  echo "Set DIRECTORY_USER_EMAIL and DIRECTORY_USER_PASS (Keycloak user in group ${CLAIM_GROUP})."
  echo "Optional: DIRECTORY_MANUAL_GROUP_ID so a hand-added membership is checked after revoke."
  exit 1
fi

COOKIE_JAR="$(mktemp)"
trap 'rm -f "${COOKIE_JAR}"' EXIT

login() {
  curl -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" -X POST "${BASE}/api/v1/auth/login" \
    -H 'Content-Type: application/json' \
    -d "{\"email\":\"${EMAIL}\",\"password\":\"${PASS}\"}" >/dev/null
}

groups_json() {
  curl -sS -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" "${BASE}/api/v1/groups/mine"
}

echo "1. Sign in while the Keycloak user is in ${CLAIM_GROUP}"
login
MINE="$(groups_json)"
echo "${MINE}"
echo "${MINE}" | grep -qi "${CLAIM_GROUP}"
echo "Directory group present after login"

if [[ -n "${MANUAL_GROUP_ID}" ]]; then
  echo "${MINE}" | grep -q "\"id\":${MANUAL_GROUP_ID}"
  echo "Manual group ${MANUAL_GROUP_ID} still present"
fi

echo
echo "2. Remove the user from ${CLAIM_GROUP} in Keycloak, then press Enter"
read -r
login
MINE2="$(groups_json)"
echo "${MINE2}"
if echo "${MINE2}" | grep -qi "${CLAIM_GROUP}"; then
  echo "Directory group should have disappeared after the next login"
  exit 1
fi
echo "Directory membership gone after removal"

if [[ -n "${MANUAL_GROUP_ID}" ]]; then
  echo "${MINE2}" | grep -q "\"id\":${MANUAL_GROUP_ID}"
  echo "Manual membership survived"
fi

echo "directory-demo.sh OK"
