#!/usr/bin/env bash
#
# Points Caddy at the right certificate strategy for this instance.
#
# With a domain: a publicly trusted Let's Encrypt certificate, obtained and
# renewed automatically. Without one: a self-signed certificate, which the
# browser warns about but which still keeps the administrator password off the
# wire — the alternative, plain HTTP, is not one.
#
# Also runs standalone, after the launch, to attach a domain to a running
# instance:
#
#   sudo synaplan-tls app.example.com [admin@example.com]

set -Eeuo pipefail

APP_DIR=/opt/synaplan
CADDY_SRC="$APP_DIR/deploy/aws/caddy"
CADDY_FILE=/etc/caddy/Caddyfile
CADDY_ENV=/etc/caddy/synaplan.env
DATA_MOUNT=/var/lib/synaplan
ENV_FILE="$DATA_MOUNT/.env"

log() { printf '[synaplan-tls] %s\n' "$*"; }

# The value the operator passed wins; otherwise take the domain the running
# configuration already names, so a restart never silently downgrades a working
# certificate to a self-signed one.
domain="${1-}"
acme_email="${2-}"
requested_domain="$domain"

if [[ -z "$domain" && -f "$ENV_FILE" ]]; then
    app_url="$(awk -F= '/^APP_URL=/ { print $2; exit }' "$ENV_FILE")"
    host="${app_url#https://}"
    host="${host#http://}"
    host="${host%%/*}"
    # A bare IPv4 address is not a domain and cannot get a public certificate.
    [[ "$host" =~ ^[0-9]+(\.[0-9]+){3}$ ]] || domain="$host"
fi

if [[ -z "$acme_email" && -f "$ENV_FILE" ]]; then
    acme_email="$(awk -F= '/^BOOTSTRAP_ADMIN_EMAIL=/ { print $2; exit }' "$ENV_FILE")"
fi

# A domain named on the command line is a change of the public address, so the
# application has to learn it too — an APP_URL that still says the old host
# breaks every absolute link, the widget origin check, and the OAuth callbacks.
if [[ -n "$requested_domain" && -f "$ENV_FILE" ]]; then
    for key in APP_URL FRONTEND_URL REALTIME_ALLOWED_ORIGINS; do
        if grep -q "^$key=" "$ENV_FILE"; then
            sed -i "s|^$key=.*|$key=https://$requested_domain|" "$ENV_FILE"
        else
            printf '%s=https://%s\n' "$key" "$requested_domain" >> "$ENV_FILE"
        fi
    done
    log "Set the public address to https://$requested_domain"
    log "Apply it to the running stack with: sudo systemctl restart synaplan"
fi

install -d -m 0755 /etc/caddy /var/log/caddy
chown caddy:caddy /var/log/caddy

if [[ -n "$domain" ]]; then
    log "Serving https://$domain with a Let's Encrypt certificate"
    install -m 0644 "$CADDY_SRC/Caddyfile.domain" "$CADDY_FILE"
    cat > "$CADDY_ENV" <<EOF
SYNAPLAN_DOMAIN=$domain
SYNAPLAN_ACME_EMAIL=$acme_email
EOF
else
    log "No domain configured; serving HTTPS with a self-signed certificate"
    log "Attach one later with: sudo synaplan-tls app.example.com"
    install -m 0644 "$CADDY_SRC/Caddyfile.selfsigned" "$CADDY_FILE"
    : > "$CADDY_ENV"
fi
chmod 0644 "$CADDY_ENV"

# Caddy reads SYNAPLAN_DOMAIN and SYNAPLAN_ACME_EMAIL from the environment, and
# the packaged unit does not read our file.
install -d -m 0755 /etc/systemd/system/caddy.service.d
cat > /etc/systemd/system/caddy.service.d/10-synaplan.conf <<EOF
[Service]
EnvironmentFile=$CADDY_ENV
EOF

# With the same environment the unit will run under: the domain Caddyfile is a
# placeholder until SYNAPLAN_DOMAIN is set, and validating it without one would
# reject a configuration that is correct.
SYNAPLAN_DOMAIN="$domain" SYNAPLAN_ACME_EMAIL="$acme_email" \
    caddy validate --config "$CADDY_FILE" --adapter caddyfile

# During the first boot systemd starts Caddy itself, right after this unit.
if systemctl is-active --quiet caddy.service; then
    systemctl daemon-reload
    systemctl reload-or-restart caddy.service
    log "Reloaded Caddy"
else
    systemctl daemon-reload
fi
