#!/usr/bin/env bash
#
# Installed as /etc/update-motd.d/99-synaplan. The first thing anyone who opens
# a shell on the VM sees, which for most customers is the moment they are
# looking for the way into their own installation.
#
# It deliberately prints the COMMAND, not the password: update-motd.d runs as
# root but its output is cached in /run/motd.dynamic and shown to every user
# that logs in afterwards.

set -Eeuo pipefail

DATA_MOUNT=/var/lib/synaplan
ENV_FILE="$DATA_MOUNT/.env"

[[ -f "$ENV_FILE" ]] || {
    printf '\nSynaplan is still setting itself up. Follow it with:\n'
    printf '  journalctl -u synaplan-firstboot -f\n\n'
    exit 0
}

url="$(awk -F= '/^APP_URL=/ { print substr($0, index($0, "=") + 1); exit }' "$ENV_FILE" 2>/dev/null || true)"

printf '\nSynaplan\n'
printf '  Address              %s\n' "${url:-unknown}"
printf '  First sign-in        sudo synaplan-admin-password\n'
printf '  Attach a domain      sudo synaplan-tls app.example.com\n'
printf '  Back up now          sudo synaplan-snapshot\n'
printf '  Update               sudo synaplan-update <version>\n'
printf '  Check the stack      sudo synaplan-smoke-test\n\n'
