#!/usr/bin/env bash
# Fake partner-instance handshake (More Nextcloud S1 / NC7).
#
# Usage:
#   ./fake-instance.sh            # enable flag and walk the handshake
#   ./fake-instance.sh --flag-off # assert every platform-links route is 404
#
# Environment: SYNAPLAN_BASE_URL, SYNAPLAN_EMAIL, SYNAPLAN_PASSWORD,
# SYNAPLAN_ADMIN_EMAIL, SYNAPLAN_ADMIN_PASSWORD
# (defaults match the seeded demo + admin accounts).

set -euo pipefail
cd "$(dirname "$0")"
# shellcheck source=lib.sh
source ./lib.sh

require_tools

HOST="${SYNAPLAN_FAKE_HOST:-fake.example}"
REDIRECT="https://${HOST}/cb"

flag_off=0
if [[ "${1:-}" == "--flag-off" ]]; then
  flag_off=1
fi

if [[ "$flag_off" -eq 1 ]]; then
  disable_flag || true
  login
  status=$(http_json POST /api/v1/platform-links/instances \
    "{\"client\":\"nextcloud\",\"host\":\"https://${HOST}\",\"redirect_uris\":[\"${REDIRECT}\"]}")
  assert_eq "register 404 when flag off" "$status" "404"
  status=$(http_json GET /api/v1/platform-links/instances/self)
  assert_eq "self 404 when flag off" "$status" "404"
  status=$(http_json GET /api/v1/me/platform-links)
  assert_eq "my-links 404 when flag off" "$status" "404"
  status=$(http_json POST /api/v1/platform-links/exchange \
    '{"instance_id":"pi_deadbeef","instance_secret":"x","code":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}')
  assert_eq "exchange 404 when flag off" "$status" "404"
  summary
  exit $?
fi

enable_flag
login "$ADMIN_EMAIL" "$ADMIN_PASSWORD"

status=$(http_json POST /api/v1/platform-links/instances \
  "{\"client\":\"nextcloud\",\"host\":\"https://${HOST}\",\"redirect_uris\":[\"${REDIRECT}\"]}")
assert_eq "register instance" "$status" "201"
INSTANCE_ID=$(json_get /tmp/syn-pl-body.json instance_id)
INSTANCE_SECRET=$(json_get /tmp/syn-pl-body.json instance_secret)
INSTANCE_STATUS=$(json_get /tmp/syn-pl-body.json status)
assert "instance id" "[[ -n \"$INSTANCE_ID\" && \"$INSTANCE_ID\" == pi_* ]]"
assert_eq "admin register is active" "$INSTANCE_STATUS" "active"

login "$EMAIL" "$PASSWORD"

status=$(http_json POST /api/v1/platform-links/codes \
  "{\"instance_id\":\"${INSTANCE_ID}\",\"external_id\":\"jdoe\",\"redirect_uri\":\"${REDIRECT}\",\"state\":\"harness\"}")
assert_eq "issue link code" "$status" "200"
REDIR=$(json_get /tmp/syn-pl-body.json redirect)
CODE=$(printf '%s' "$REDIR" | sed -n 's/.*[?&]code=\([^&]*\).*/\1/p')
assert "code in redirect" "[[ -n \"$CODE\" ]]"

status=$(curl -sS -o /tmp/syn-pl-ex.json -w '%{http_code}' \
  -X POST "$BASE/api/v1/platform-links/exchange" \
  -H 'content-type: application/json' \
  -d "{\"instance_id\":\"${INSTANCE_ID}\",\"instance_secret\":\"${INSTANCE_SECRET}\",\"code\":\"${CODE}\"}")
assert_eq "exchange" "$status" "200"
KEY=$(json_get /tmp/syn-pl-ex.json api_key.key)
LINK_ID=$(json_get /tmp/syn-pl-ex.json link_id)
assert "minted key" "[[ \"$KEY\" == sk_* ]]"

status=$(curl -sS -o /tmp/syn-pl-me.json -w '%{http_code}' \
  -H "x-api-key: $KEY" "$BASE/api/v1/auth/me")
assert_eq "scoped key reaches /auth/me" "$status" "200"

status=$(curl -sS -o /tmp/syn-pl-admin.json -w '%{http_code}' \
  -H "x-api-key: $KEY" "$BASE/api/v1/admin/users")
assert_eq "scoped key cannot list users" "$status" "403"

status=$(curl -sS -o /tmp/syn-pl-replay.json -w '%{http_code}' \
  -X POST "$BASE/api/v1/platform-links/exchange" \
  -H 'content-type: application/json' \
  -d "{\"instance_id\":\"${INSTANCE_ID}\",\"instance_secret\":\"${INSTANCE_SECRET}\",\"code\":\"${CODE}\"}")
assert_eq "replay is rejected" "$status" "400"

status=$(http_json DELETE "/api/v1/me/platform-links/${LINK_ID}")
assert_eq "disconnect" "$status" "200"

status=$(curl -sS -o /tmp/syn-pl-gone.json -w '%{http_code}' \
  -H "x-api-key: $KEY" "$BASE/api/v1/auth/me")
assert_eq "revoked key is 401" "$status" "401"

summary
