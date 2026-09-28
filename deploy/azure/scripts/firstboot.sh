#!/usr/bin/env bash
#
# Turns a freshly deployed instance of the Synaplan Azure image into a
# configured, running installation. Runs once per data disk, before
# synaplan.service.
#
# Two properties this script must keep, because the Marketplace depends on them:
#
#   It works with NO user data and NO template parameters. A customer who picks
#   the image straight out of the Marketplace and clicks Create gets a bare VM
#   with nothing but a managed identity, and that has to produce a working,
#   reachable installation.
#
#   It is idempotent and never overwrites configuration. A reboot, a deallocate
#   and start, and a VM recreated onto the same data disk all re-run it; an
#   installation that already exists is left exactly as the operator left it.

set -Eeuo pipefail

APP_DIR=/opt/synaplan
DEPLOY_DIR="$APP_DIR/deploy"
DATA_MOUNT=/var/lib/synaplan
DATA_LABEL=synaplan-data
# Azure exposes every attached data disk under a stable, LUN-addressed path.
# Unlike AWS there is no NVMe renaming to guess around: the template attaches
# the data disk at LUN 0, so this is the disk and nothing else is.
DATA_DISK=/dev/disk/azure/scsi1/lun0
ENV_FILE="$DATA_MOUNT/.env"
STATE_FILE="$DATA_MOUNT/.firstboot-complete"
PASSWORD_FILE="$DATA_MOUNT/initial-admin-password"
IMDS_BASE=http://169.254.169.254/metadata

log() { printf '[synaplan-firstboot] %s\n' "$*"; }
warn() { printf '[synaplan-firstboot] %s\n' "$*" >&2; }

work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT
USER_DATA_FILE="$work_dir/user-data"
TAGS_FILE="$work_dir/tags"

# --------------------------------------------------------------------------
# Instance metadata
#
# One request answers everything: identity, placement, addresses, tags and the
# user data document. No CLI, no credential, no role assignment — the metadata
# service is reachable from the VM itself and from nowhere else.
# --------------------------------------------------------------------------

instance_metadata() {
    curl --fail --silent --max-time 5 \
        -H "Metadata:true" --noproxy '*' \
        "$IMDS_BASE/instance?api-version=2021-02-01" 2>/dev/null || true
}

INSTANCE="$(instance_metadata)"

# Reads one field out of the document above. Every caller has to work when the
# metadata service is unreachable, which is why this stays silent and empty
# rather than failing.
metadata() {
    [[ -n "$INSTANCE" ]] || return 0
    printf '%s' "$INSTANCE" | jq -r "$1 // empty" 2>/dev/null || true
}

VM_NAME="$(metadata '.compute.name')"
VM_ID="$(metadata '.compute.vmId')"
PUBLIC_IP="$(metadata '.network.interface[0].ipv4.ipAddress[0].publicIpAddress')"
PRIVATE_IP="$(metadata '.network.interface[0].ipv4.ipAddress[0].privateIpAddress')"
[[ -n "$PRIVATE_IP" ]] || PRIVATE_IP=127.0.0.1

# --------------------------------------------------------------------------
# Optional operator configuration
#
# Everything below is optional and has a working default. Two sources, because
# the two ways of deploying the image can each only supply one: an ARM template
# writes a user data document, and an operator deploying the bare image from the
# Marketplace sets tags on the VM.
# --------------------------------------------------------------------------

# `key=value` lines, one setting per line, `#` starts a comment. The value runs
# to the end of the line, so a DSN or an API key containing `=` survives intact.
parse_settings() {
    awk -F= '
        { line = $0; sub(/^[[:space:]]+/, "", line) }
        line == "" || index(line, "#") == 1 { next }
        index(line, "=") == 0 { next }
        {
            key = substr(line, 1, index(line, "=") - 1)
            value = substr(line, index(line, "=") + 1)
            sub(/[[:space:]]+$/, "", key)
            if (key != "") printf "%s\t%s\n", key, value
        }
    '
}

load_user_data() {
    local encoded
    encoded="$(metadata '.compute.userData')"
    [[ -n "$encoded" ]] || return 0
    # An unreadable document is an operator mistake, not a reason to refuse to
    # boot: the defaults below still produce a working installation.
    printf '%s' "$encoded" | base64 -d 2>/dev/null | parse_settings > "$USER_DATA_FILE" || {
        warn "The user data document is not valid base64; ignoring it"
        : > "$USER_DATA_FILE"
    }
}

load_tags() {
    [[ -n "$INSTANCE" ]] || return 0
    printf '%s' "$INSTANCE" |
        jq -r '.compute.tagsList[]? | select(.name | startswith("synaplan:")) | "\(.name | sub("^synaplan:"; ""))\t\(.value)"' \
            > "$TAGS_FILE" 2>/dev/null || : > "$TAGS_FILE"
}

lookup() {
    [[ -f "$1" ]] || return 0
    awk -F'\t' -v wanted="$2" '$1 == wanted { print $2; exit }' "$1"
}

# Reads one optional setting: user data first (the deployment said so), then the
# VM tags (someone said so afterwards), then the default.
setting() {
    local key="$1" default="${2-}" value
    value="$(lookup "$USER_DATA_FILE" "$key")"
    [[ -n "$value" ]] || value="$(lookup "$TAGS_FILE" "$key")"
    printf '%s' "${value:-$default}"
}

# --------------------------------------------------------------------------
# Data disk
# --------------------------------------------------------------------------

# The empty block device the template attached for user data. The next step
# formats whatever this returns, so "blank" has to mean completely blank: a
# filesystem, a partition table or a partition all mean the disk carries
# somebody's data — a restored snapshot, a disk the customer attached, or this
# instance's second boot — and mkfs would destroy it.
data_disk_is_blank() {
    local device="$1" signature partitions
    signature="$(lsblk --noheadings --nodeps --output FSTYPE,PTTYPE "$device" 2>/dev/null | tr -d ' ')" || return 1
    [[ -z "$signature" ]] || return 1
    # Everything below the device itself. A partitioned disk reports no
    # filesystem of its own, so without this a disk whose data sits in a
    # partition would look untouched.
    partitions="$(lsblk --noheadings --output NAME "$device" 2>/dev/null | tail -n +2)" || return 1
    [[ -z "$partitions" ]]
}

# The udev symlink appears a moment after the disk is attached, and a VM created
# with its data disk in the same deployment can boot faster than that. Only the
# templates know whether to expect a disk, and they say so; without the setting
# the wait is skipped entirely, so a bare Marketplace deployment starts
# immediately.
wait_for_data_disk() {
    local deadline=$((SECONDS + 300))

    [[ "$(setting data-disk)" == attached ]] || return 0

    log "Waiting for the data disk the template attaches"
    while ((SECONDS < deadline)); do
        if blkid --label "$DATA_LABEL" >/dev/null 2>&1 || [[ -b "$DATA_DISK" ]]; then
            return 0
        fi
        sleep 5
    done

    warn "No data disk appeared within five minutes; continuing on the OS disk"
}

prepare_data_disk() {
    if findmnt --noheadings --target "$DATA_MOUNT" --mountpoint "$DATA_MOUNT" >/dev/null 2>&1; then
        log "Data disk already mounted at $DATA_MOUNT"
        return 0
    fi

    wait_for_data_disk

    local device=""
    if blkid --label "$DATA_LABEL" >/dev/null 2>&1; then
        device="$(blkid --label "$DATA_LABEL" | head -n1)"
        log "Found an existing Synaplan data disk: $device"
    elif [[ -b "$DATA_DISK" ]] && data_disk_is_blank "$DATA_DISK"; then
        log "Formatting the data disk at LUN 0 for Synaplan"
        mkfs.xfs -L "$DATA_LABEL" "$DATA_DISK"
        device="$DATA_DISK"
    else
        # No separate disk. Not the recommended layout — the ARM template always
        # attaches one — but a bare "deploy this image" must still produce a
        # working installation, so the OS disk carries the data.
        warn "No separate data disk found; keeping data on the OS disk"
        install -d -m 0755 "$DATA_MOUNT"
        return 0
    fi

    install -d -m 0755 "$DATA_MOUNT"

    # By UUID, not by the LUN path: Azure documents that a data disk can come
    # back on a different controller path after a resize or a redeploy, and an
    # fstab entry naming a path that moved leaves the VM unable to boot.
    local uuid
    uuid="$(blkid --output value --match-tag UUID "$device")"
    if [[ -n "$uuid" ]] && ! grep -q "UUID=$uuid" /etc/fstab; then
        printf 'UUID=%s %s xfs defaults,nofail 0 2\n' "$uuid" "$DATA_MOUNT" >> /etc/fstab
    fi
    mount "$DATA_MOUNT"
    log "Mounted the data disk at $DATA_MOUNT"
}

# --------------------------------------------------------------------------
# Configuration
# --------------------------------------------------------------------------

baked_version() {
    # shellcheck disable=SC1091
    source "$APP_DIR/image-release"
    printf '%s' "${SYNAPLAN_VERSION:?The image does not record a baked release}"
}

# A password nobody has ever seen, for an account that cannot keep it: the
# application marks it as one-time use and refuses every request until it is
# replaced (BOOTSTRAP_ADMIN_FORCE_PASSWORD_CHANGE below). 24 hex characters, so
# it clears the length rule without containing a character Compose would rewrite
# inside an .env file.
generate_admin_password() {
    openssl rand -hex 12
}

key_vault_url() {
    local vault="$1"
    if [[ "$vault" == https://* ]]; then
        printf '%s' "${vault%/}"
    else
        printf 'https://%s.vault.azure.net' "$vault"
    fi
}

# The managed identity's token for Key Vault, straight from the metadata
# service. No CLI and no credential on disk: Azure hands the token to this VM
# and to nothing else.
key_vault_token() {
    curl --fail --silent --max-time 15 \
        -H "Metadata:true" --noproxy '*' \
        "$IMDS_BASE/identity/oauth2/token?api-version=2018-02-01&resource=https%3A%2F%2Fvault.azure.net" 2>/dev/null |
        jq -r '.access_token // empty' 2>/dev/null || true
}

publish_admin_password() {
    local password="$1" vault secret token base

    # Always on the data disk, mode 0600, next to the .env that already holds
    # the same value: it is what `synaplan-admin-password` reads, and it is the
    # only way in for a deployment with no Key Vault.
    (
        umask 077
        printf '%s\n' "$password" > "$PASSWORD_FILE"
    )
    chmod 0600 "$PASSWORD_FILE"

    vault="$(setting key-vault)"
    [[ -n "$vault" ]] || {
        log "No Key Vault configured; read the password with: sudo synaplan-admin-password"
        return 0
    }

    secret="$(setting key-vault-secret synaplan-admin-password)"
    token="$(key_vault_token)"
    [[ -n "$token" ]] || {
        # Not fatal. The VM may have no managed identity, or the identity may
        # deliberately not be allowed near this vault, and an installation that
        # works but needs a shell to reveal its password beats an installation
        # that refuses to start.
        warn "No managed-identity token for Key Vault; the initial administrator password stayed on the VM."
        warn "Read it with: sudo synaplan-admin-password"
        return 0
    }

    base="$(key_vault_url "$vault")"
    # Through stdin, so the password never appears in the process list.
    if printf '{"value":"%s"}' "$password" |
        curl --fail --silent --show-error --max-time 30 \
            -X PUT "$base/secrets/$secret?api-version=7.4" \
            -H "Authorization: Bearer $token" \
            -H "Content-Type: application/json" \
            --data-binary @- >/dev/null 2>&1; then
        log "Stored the initial administrator password in Key Vault as $secret"
    else
        warn "Could not write the administrator password to Key Vault."
        warn "Read it with: sudo synaplan-admin-password"
    fi
}

write_env_file() {
    local domain admin_email admin_password app_url registration previous_umask

    domain="$(setting domain)"
    admin_email="$(setting admin-email "admin@${domain:-example.com}")"
    registration="$(setting registration-enabled false)"

    if [[ -n "$domain" ]]; then
        app_url="https://$domain"
    elif [[ -n "$PUBLIC_IP" ]]; then
        # No domain: Caddy serves a self-signed certificate on the IP. The
        # browser warns, which is correct — it is an unverifiable certificate —
        # and the operator replaces it by setting a domain.
        app_url="https://$PUBLIC_IP"
    else
        app_url="https://$PRIVATE_IP"
    fi

    admin_password="$(generate_admin_password)"

    # Restored below, so the scripts main() runs afterwards create their
    # directories with the permissions they ask for rather than with whatever
    # this file needed.
    previous_umask="$(umask)"
    umask 077
    cat > "$ENV_FILE" <<EOF
# Generated by the Synaplan Azure image on the first boot of this data disk.
# Edit it, then run: sudo systemctl restart synaplan
# This file is the only copy of some values — it is on the data disk, so it is
# included in every backup of that disk.

COMPOSE_PROJECT_NAME=synaplan
SYNAPLAN_VERSION=$(baked_version)
# The images are already in the local cache; never reach out on a normal start.
SYNAPLAN_PULL_POLICY=missing
SYNAPLAN_PLATFORM=azure

# Caddy on the host terminates TLS and proxies to this port, so the application
# container must not be reachable from outside the VM.
SYNAPLAN_HTTP_BIND=127.0.0.1
SYNAPLAN_HTTP_PORT=8000

APP_URL=$app_url
FRONTEND_URL=$app_url
REALTIME_ALLOWED_ORIGINS=$app_url
# Everything is served over TLS, including the self-signed fallback.
AUTH_COOKIE_SECURE=true

# Off by default: a VM with a public IP would otherwise let anyone who finds it
# create an account. Set to true once you want open sign-up.
REGISTRATION_ENABLED=$registration

# One-time administrator credentials. The password is also readable with
# \`sudo synaplan-admin-password\`, and the first sign-in has to replace it
# before anything else in the application works.
BOOTSTRAP_ADMIN_EMAIL=$admin_email
BOOTSTRAP_ADMIN_PASSWORD=$admin_password
BOOTSTRAP_ADMIN_FORCE_PASSWORD_CHANGE=true

# Cloud AI. Synaplan needs one provider key to answer anything; add it here or
# in Admin > AI Providers after signing in.
AI_DEFAULT_PROVIDER=$(setting ai-provider groq)
GROQ_API_KEY=$(setting groq-api-key)
OPENAI_API_KEY=$(setting openai-api-key)
ANTHROPIC_API_KEY=$(setting anthropic-api-key)
GOOGLE_GEMINI_API_KEY=$(setting google-gemini-api-key)
MISTRAL_API_KEY=$(setting mistral-api-key)
XAI_API_KEY=$(setting xai-api-key)

# Optional: bill your own users through your own Stripe account. Empty means
# open-source mode — no plans, no quotas, every feature open. Full setup in
# docs/BILLING_SELFHOST.md.
STRIPE_SECRET_KEY=$(setting stripe-secret-key)
STRIPE_WEBHOOK_SECRET=$(setting stripe-webhook-secret)
STRIPE_PRICE_PRO=$(setting stripe-price-pro)
STRIPE_PRICE_TEAM=$(setting stripe-price-team)
STRIPE_PRICE_BUSINESS=$(setting stripe-price-business)

# Outgoing email. Without it, password resets and notifications are unavailable.
MAILER_DSN=$(setting mailer-dsn "null://null")
APP_SENDER_EMAIL=$(setting sender-email)

COMPOSE_PROFILES=
LOG_FORMAT=json
EOF
    umask "$previous_umask"
    chmod 0600 "$ENV_FILE"

    publish_admin_password "$admin_password"
    log "Wrote $ENV_FILE for $app_url"
}

# --------------------------------------------------------------------------

main() {
    log "Configuring ${VM_NAME:-this VM}${VM_ID:+ ($VM_ID)}"

    load_user_data
    load_tags

    prepare_data_disk

    install -d -m 0755 "$DATA_MOUNT/data"
    install -d -m 0700 "$DATA_MOUNT/.lifecycle"

    if [[ -f "$STATE_FILE" && -f "$ENV_FILE" ]]; then
        log "This data disk is already initialised; leaving the configuration untouched"
    elif [[ -f "$ENV_FILE" ]]; then
        # A restored disk carries its configuration but not our marker.
        log "Found an existing configuration; adopting it unchanged"
    else
        write_env_file
    fi

    "$APP_DIR/deploy/host/configure-tls.sh"

    # The portable contract does the rest: resolve the deployment secrets,
    # create the data directories, and refuse to continue on a configuration
    # the stack could not start with.
    "$DEPLOY_DIR/scripts/prepare.sh"

    date --utc +%Y-%m-%dT%H:%M:%SZ > "$STATE_FILE"
    log "First-boot setup complete"
}

main "$@"
