#!/usr/bin/env bash
# DS10 — pair a (fake) Synaplan Desktop computer against the local stack.
#
# Logs in as the web user, mints a one-time pairing code, exchanges it for a
# scoped API key + device row, and stores the credentials for reuse by
# fake-device.sh. This is the minimal, human-runnable pairing demo.
#
# Usage:
#   ./_devextras/testing/desktop/pair.sh
#   SYNAPLAN_EMAIL=me@example.com SYNAPLAN_PASSWORD=... ./pair.sh
#
# Output: writes DESKTOP_KEY / DEVICE_ID / API_BASE_URL to
# $SYNAPLAN_DESKTOP_CREDS (default /tmp/synaplan-desktop.creds).

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

require_tools

echo "== Synaplan Desktop pairing =="
echo "BASE=$BASE  EMAIL=$EMAIL"
echo

enable_flag
login
echo "NOTE  Logged in."

CODE="$(mint_code)"
echo "NOTE  Pairing code: $CODE"

pair_device "$CODE" "${1:-Harness laptop}"

assert "pairing returned a scoped sk_ key" "[[ \"$DESKTOP_KEY\" == sk_* ]]"
assert "pairing returned a device id"       "[[ \"$DEVICE_ID\" =~ ^[0-9]+$ ]]"
assert "pairing returned an apiBaseUrl"     "[[ -n \"$API_BASE_URL\" ]]"

echo
echo "Paired. Credentials written to: $CREDS_FILE"
echo "  DESKTOP_KEY=$DESKTOP_KEY"
echo "  DEVICE_ID=$DEVICE_ID"
echo "  API_BASE_URL=$API_BASE_URL"

summary
