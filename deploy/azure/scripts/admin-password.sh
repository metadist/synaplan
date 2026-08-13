#!/usr/bin/env bash
#
# Prints the initial administrator password, published as
# `synaplan-admin-password`.
#
# The password is generated on the first boot of the data disk and exists
# nowhere a customer can guess: it is in deploy/.env, in this file next to it,
# and — if the deployment created a Key Vault — in that vault. This command is
# the short way to it over SSH, Azure Bastion or Run Command, so nobody has to
# be told which file to grep.
#
# It is single-use: the account can sign in and do nothing but set its own
# password, enforced by the application, until it does.

set -Eeuo pipefail

DATA_MOUNT=/var/lib/synaplan
PASSWORD_FILE="$DATA_MOUNT/initial-admin-password"
ENV_FILE="$DATA_MOUNT/.env"

[[ $EUID -eq 0 ]] || {
    echo "synaplan-admin-password must run as root: sudo synaplan-admin-password" >&2
    exit 1
}

[[ -f "$ENV_FILE" ]] || {
    echo "This VM has not completed its first boot yet; there is no installation to sign in to." >&2
    echo "Follow it with: journalctl -u synaplan-firstboot -f" >&2
    exit 1
}

email="$(awk -F= '/^BOOTSTRAP_ADMIN_EMAIL=/ { print $2; exit }' "$ENV_FILE")"
url="$(awk -F= '/^APP_URL=/ { print substr($0, index($0, "=") + 1); exit }' "$ENV_FILE")"

if [[ -f "$PASSWORD_FILE" ]]; then
    password="$(<"$PASSWORD_FILE")"
else
    # A data disk restored from before this file existed, or an operator who
    # removed it after the first sign-in. The .env still holds the value that
    # bootstrapped the account.
    password="$(awk -F= '/^BOOTSTRAP_ADMIN_PASSWORD=/ { print $2; exit }' "$ENV_FILE")"
fi

[[ -n "$password" ]] || {
    echo "No initial password is recorded for this installation." >&2
    echo "It has almost certainly been changed already; reset it over the sign-in page instead." >&2
    exit 1
}

cat <<EOF
Synaplan initial administrator credentials

  Address   ${url:-unknown}
  Email     ${email:-unknown}
  Password  $password

This password works exactly once: the first sign-in must replace it before
anything else in the application becomes available.
EOF
