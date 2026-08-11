#!/usr/bin/env bash
#
# Turns a freshly launched instance of the Synaplan AMI into a configured,
# running installation. Runs once per data volume, before synaplan.service.
#
# Two properties this script must keep, because the Marketplace depends on them:
#
#   It works with NO user data and NO stack parameters. "Launch from Website"
#   starts the AMI with nothing but an instance profile, and that launch has to
#   produce a working, reachable installation.
#
#   It is idempotent and never overwrites configuration. A reboot, a stop/start,
#   and an instance replaced onto the same data volume all re-run it; an
#   installation that already exists is left exactly as the operator left it.

set -Eeuo pipefail

APP_DIR=/opt/synaplan
DEPLOY_DIR="$APP_DIR/deploy"
DATA_MOUNT=/var/lib/synaplan
DATA_LABEL=synaplan-data
ENV_FILE="$DATA_MOUNT/.env"
STATE_FILE="$DATA_MOUNT/.firstboot-complete"

log() { printf '[synaplan-firstboot] %s\n' "$*"; }
warn() { printf '[synaplan-firstboot] %s\n' "$*" >&2; }

# --------------------------------------------------------------------------
# Instance metadata (IMDSv2 only — the AMI enables nothing else)
# --------------------------------------------------------------------------

imds_token() {
    curl --fail --silent --show-error --max-time 5 \
        -X PUT "http://169.254.169.254/latest/api/token" \
        -H "X-aws-ec2-metadata-token-ttl-seconds: 300" 2>/dev/null || true
}

imds() {
    local path="$1" token
    token="$(imds_token)"
    [[ -n "$token" ]] || return 1
    curl --fail --silent --max-time 5 \
        -H "X-aws-ec2-metadata-token: $token" \
        "http://169.254.169.254/latest/meta-data/$path" 2>/dev/null || return 1
}

INSTANCE_ID="$(imds instance-id || echo unknown)"
PUBLIC_IP="$(imds public-ipv4 || true)"
PRIVATE_IP="$(imds local-ipv4 || echo 127.0.0.1)"
REGION="$(imds placement/region || true)"
[[ -n "$REGION" ]] && export AWS_DEFAULT_REGION="$REGION"

# --------------------------------------------------------------------------
# Optional operator configuration
#
# Everything below is optional and has a working default. Two sources, because
# the two ways of launching the AMI can each only supply one of them: a
# CloudFormation stack sets instance tags, and an operator who wants to change
# something after the launch writes an SSM parameter and reboots.
# --------------------------------------------------------------------------

instance_tag() {
    local key="$1"
    command -v aws >/dev/null 2>&1 || return 1
    aws ec2 describe-tags \
        --filters "Name=resource-id,Values=$INSTANCE_ID" "Name=key,Values=$key" \
        --query 'Tags[0].Value' --output text 2>/dev/null | grep -v '^None$' || return 1
}

ssm_parameter() {
    local name="$1"
    command -v aws >/dev/null 2>&1 || return 1
    aws ssm get-parameter --name "$name" --with-decryption \
        --query 'Parameter.Value' --output text 2>/dev/null || return 1
}

# Reads one optional setting: instance tag first (the launch said so), then the
# parameter store (the operator said so afterwards), then the default.
setting() {
    local key="$1" default="${2-}" value
    value="$(instance_tag "synaplan:$key" || true)"
    [[ -n "$value" ]] || value="$(ssm_parameter "/synaplan/$INSTANCE_ID/config/$key" || true)"
    printf '%s' "${value:-$default}"
}

# --------------------------------------------------------------------------
# Data volume
# --------------------------------------------------------------------------

root_device() {
    findmnt --noheadings --output SOURCE --target / | sed 's/[0-9]*$//'
}

# The empty block device the stack attached for user data. Identified by having
# no filesystem at all, so an already-formatted volume — a restored snapshot, or
# this instance's second boot — is never touched.
find_blank_data_device() {
    local device fstype root
    root="$(root_device)"
    while read -r device; do
        [[ "/dev/$device" == "$root"* ]] && continue
        fstype="$(lsblk --noheadings --output FSTYPE "/dev/$device" | head -n1 | tr -d ' ')"
        [[ -z "$fstype" ]] || continue
        printf '/dev/%s\n' "$device"
        return 0
    done < <(lsblk --noheadings --nodeps --output NAME)
    return 1
}

# A data volume the stack attaches after the instance is already running is not
# there when this script first looks. Only the templates know whether to expect
# one, and they say so with a tag; without it the wait is skipped entirely, so a
# bare "Launch from Website" still starts immediately.
wait_for_data_volume() {
    local deadline=$((SECONDS + 300))

    [[ "$(setting data-volume)" == attached ]] || return 0

    log "Waiting for the data volume the stack attaches"
    while ((SECONDS < deadline)); do
        if blkid --label "$DATA_LABEL" >/dev/null 2>&1 || find_blank_data_device >/dev/null; then
            return 0
        fi
        sleep 5
    done

    warn "No data volume appeared within five minutes; continuing on the root volume"
}

prepare_data_volume() {
    if findmnt --noheadings --target "$DATA_MOUNT" --mountpoint "$DATA_MOUNT" >/dev/null 2>&1; then
        log "Data volume already mounted at $DATA_MOUNT"
        return 0
    fi

    wait_for_data_volume

    local device=""
    if blkid --label "$DATA_LABEL" >/dev/null 2>&1; then
        device="$(blkid --label "$DATA_LABEL" | head -n1)"
        log "Found an existing Synaplan data volume: $device"
    elif device="$(find_blank_data_device)"; then
        log "Formatting $device as the Synaplan data volume"
        mkfs.xfs -L "$DATA_LABEL" "$device"
    else
        # No separate volume. Not the recommended layout — the CloudFormation
        # templates always attach one — but a bare "launch this AMI" must still
        # produce a working installation, so the root volume carries the data.
        warn "No separate data volume found; keeping data on the root volume"
        install -d -m 0755 "$DATA_MOUNT"
        return 0
    fi

    install -d -m 0755 "$DATA_MOUNT"
    if ! grep -q "LABEL=$DATA_LABEL" /etc/fstab; then
        printf 'LABEL=%s %s xfs defaults,nofail 0 2\n' "$DATA_LABEL" "$DATA_MOUNT" >> /etc/fstab
    fi
    mount "$DATA_MOUNT"
    log "Mounted the data volume at $DATA_MOUNT"
}

# --------------------------------------------------------------------------
# Configuration
# --------------------------------------------------------------------------

baked_version() {
    # shellcheck disable=SC1091
    source "$APP_DIR/ami-release"
    printf '%s' "${SYNAPLAN_VERSION:?The AMI does not record a baked release}"
}

# A password nobody has ever seen, for an account that cannot keep it: the
# application marks it as one-time use and refuses every request until it is
# replaced (BOOTSTRAP_ADMIN_FORCE_PASSWORD_CHANGE below). 24 hex characters, so
# it clears the length rule without containing a character Compose would rewrite
# inside an .env file.
generate_admin_password() {
    openssl rand -hex 12
}

publish_admin_password() {
    local password="$1" name="/synaplan/$INSTANCE_ID/admin-password"

    if ! command -v aws >/dev/null 2>&1; then
        warn "AWS CLI unavailable; the initial administrator password is only in $ENV_FILE"
        return 0
    fi

    if aws ssm put-parameter \
        --name "$name" \
        --type SecureString \
        --value "$password" \
        --description "Synaplan initial administrator password. One-time use: the first sign-in must replace it." \
        --overwrite >/dev/null 2>&1; then
        log "Stored the initial administrator password at $name"
    else
        # Not fatal. The instance profile may deliberately not allow it, and an
        # installation that works but needs the console to reveal its password
        # beats an installation that refuses to start.
        warn "Could not write the administrator password to the SSM Parameter Store."
        warn "Read it from $ENV_FILE over Session Manager instead."
    fi
}

write_env_file() {
    local domain admin_email admin_password app_url registration

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

    umask 077
    cat > "$ENV_FILE" <<EOF
# Generated by the Synaplan AMI on the first boot of this data volume.
# Edit it, then run: sudo systemctl restart synaplan
# This file is the only copy of some values — it is on the data volume, so it is
# included in every snapshot of that volume.

COMPOSE_PROJECT_NAME=synaplan
SYNAPLAN_VERSION=$(baked_version)
# The images are already in the local cache; never reach out on a normal start.
SYNAPLAN_PULL_POLICY=missing
SYNAPLAN_PLATFORM=aws

# Caddy on the host terminates TLS and proxies to this port, so the application
# container must not be reachable from outside the instance.
SYNAPLAN_HTTP_BIND=127.0.0.1
SYNAPLAN_HTTP_PORT=8000

APP_URL=$app_url
FRONTEND_URL=$app_url
REALTIME_ALLOWED_ORIGINS=$app_url
# Everything is served over TLS, including the self-signed fallback.
AUTH_COOKIE_SECURE=true

# Off by default: an instance with a public IP would otherwise let anyone who
# finds it create an account. Set to true once you want open sign-up.
REGISTRATION_ENABLED=$registration

# One-time administrator credentials. The password is also in the SSM Parameter
# Store under /synaplan/$INSTANCE_ID/admin-password, and the first sign-in has to
# replace it before anything else in the application works.
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
    chmod 0600 "$ENV_FILE"

    publish_admin_password "$admin_password"
    log "Wrote $ENV_FILE for $app_url"
}

# --------------------------------------------------------------------------

main() {
    prepare_data_volume

    install -d -m 0755 "$DATA_MOUNT/data"
    install -d -m 0700 "$DATA_MOUNT/.lifecycle"

    if [[ -f "$STATE_FILE" && -f "$ENV_FILE" ]]; then
        log "This data volume is already initialised; leaving the configuration untouched"
    elif [[ -f "$ENV_FILE" ]]; then
        # A restored volume carries its configuration but not our marker.
        log "Found an existing configuration; adopting it unchanged"
    else
        write_env_file
    fi

    "$APP_DIR/deploy/aws/scripts/configure-tls.sh"

    # The portable contract does the rest: resolve the deployment secrets,
    # create the data directories, and refuse to continue on a configuration
    # the stack could not start with.
    "$DEPLOY_DIR/scripts/prepare.sh"

    date --utc +%Y-%m-%dT%H:%M:%SZ > "$STATE_FILE"
    log "First-boot setup complete"
}

main "$@"
