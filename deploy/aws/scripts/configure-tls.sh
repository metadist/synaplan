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
SELFSIGNED_DIR=/etc/caddy/selfsigned
DATA_MOUNT=/var/lib/synaplan
ENV_FILE="$DATA_MOUNT/.env"

log() { printf '[synaplan-tls] %s\n' "$*"; }
warn() { printf '[synaplan-tls] %s\n' "$*" >&2; }

usage() {
    cat >&2 <<'USAGE'
Usage: sudo synaplan-tls <domain> [acme-email]

  <domain>      A host name whose A record points at this instance, for example
                app.example.com. Without one the instance keeps its self-signed
                certificate.
  [acme-email]  Where Let's Encrypt sends expiry notices. Defaults to the
                administrator address of this installation.
USAGE
    exit 64
}

# It writes /etc/caddy and restarts a unit, so a run without sudo would fail
# halfway through with a permission error rather than up front.
[[ $EUID -eq 0 ]] || {
    echo "synaplan-tls must run as root: sudo synaplan-tls ${1-app.example.com}" >&2
    exit 1
}

# A host name and nothing else. The value is substituted into APP_URL with sed
# and becomes a Caddy site address, so a slash, a space or a `|` would each
# rewrite the configuration into something other than what was asked for.
is_domain() {
    [[ "$1" =~ ^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$ ]]
}

# Caddy only ever uses this to notify about an expiring certificate. It becomes a
# line of its own in an EnvironmentFile, so a value carrying anything but an
# address is dropped rather than written.
is_email() {
    [[ "$1" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]
}

# The value the operator passed wins; otherwise take the domain the running
# configuration already names, so a restart never silently downgrades a working
# certificate to a self-signed one.
domain="${1-}"
acme_email="${2-}"
requested_domain="$domain"

if [[ -n "$requested_domain" ]] && ! is_domain "$requested_domain"; then
    printf 'Not a domain name: %s\n\n' "$requested_domain" >&2
    usage
fi

if [[ -z "$domain" && -f "$ENV_FILE" ]]; then
    app_url="$(awk -F= '/^APP_URL=/ { print $2; exit }' "$ENV_FILE")"
    host="${app_url#https://}"
    host="${host#http://}"
    host="${host%%/*}"
    # A bare IPv4 address is not a domain and cannot get a public certificate,
    # and neither can anything else that is not a host name — an APP_URL edited
    # by hand reaches this line too.
    if is_domain "$host" && ! [[ "$host" =~ ^[0-9]+(\.[0-9]+){3}$ ]]; then
        domain="$host"
    fi
fi

if [[ -z "$acme_email" && -f "$ENV_FILE" ]]; then
    acme_email="$(awk -F= '/^BOOTSTRAP_ADMIN_EMAIL=/ { print $2; exit }' "$ENV_FILE")"
fi

if [[ -n "$acme_email" ]] && ! is_email "$acme_email"; then
    warn "Ignoring \"$acme_email\": not an email address, so Let's Encrypt gets none."
    warn 'Certificates are still issued and renewed; only the expiry notices are lost.'
    acme_email=""
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

# The certificate pair Caddyfile.selfsigned serves. Not Caddy's `tls internal`:
# that site address has no host name, a client connecting by bare IP sends no
# SNI, and the internal issuer then has no name to mint a certificate for — it
# aborts every handshake with "tlsv1 alert internal error". A bare IP is
# exactly how this AMI is reached until a domain is attached, so the
# certificate is minted here, with the instance's own addresses in the SANs.
#
# Minted on the instance and never baked into the image: an AMI that ships a
# private key hands the SAME key to every customer. An existing pair is kept —
# regenerating it on every boot would invalidate the exception the operator's
# browser has already stored.
ensure_selfsigned_certificate() {
    install -d -m 0755 "$SELFSIGNED_DIR"
    if [[ -s "$SELFSIGNED_DIR/cert.pem" && -s "$SELFSIGNED_DIR/key.pem" ]]; then
        return 0
    fi

    local san="DNS:localhost,IP:127.0.0.1" address token public_ip
    for address in $(hostname -I 2>/dev/null || true); do
        san+=",IP:$address"
    done
    # The public address is the one the operator's browser actually dials.
    # Best effort over IMDSv2: an instance without metadata access still gets
    # a working certificate, just without its public IP in the SANs — the
    # browser warning is identical either way.
    token="$(curl -sf -X PUT --connect-timeout 2 \
        -H 'X-aws-ec2-metadata-token-ttl-seconds: 60' \
        http://169.254.169.254/latest/api/token 2>/dev/null || true)"
    if [[ -n "$token" ]]; then
        public_ip="$(curl -sf --connect-timeout 2 \
            -H "X-aws-ec2-metadata-token: $token" \
            http://169.254.169.254/latest/meta-data/public-ipv4 2>/dev/null || true)"
        [[ -n "$public_ip" ]] && san+=",IP:$public_ip"
    fi

    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes \
        -keyout "$SELFSIGNED_DIR/key.pem" -out "$SELFSIGNED_DIR/cert.pem" \
        -days 3650 -subj '/CN=synaplan' -addext "subjectAltName=$san" \
        2>/dev/null
    chown caddy:caddy "$SELFSIGNED_DIR/key.pem" "$SELFSIGNED_DIR/cert.pem"
    chmod 0600 "$SELFSIGNED_DIR/key.pem"
    chmod 0644 "$SELFSIGNED_DIR/cert.pem"
    log "Minted a self-signed certificate ($san)"
}

install -d -m 0755 /etc/caddy
install -d -o caddy -g caddy -m 0750 /var/log/caddy

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
    ensure_selfsigned_certificate
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
LogsDirectory=caddy
EOF

# With the same environment the unit will run under: the domain Caddyfile is a
# placeholder until SYNAPLAN_DOMAIN is set, and validating it without one would
# reject a configuration that is correct.
SYNAPLAN_DOMAIN="$domain" SYNAPLAN_ACME_EMAIL="$acme_email" \
    caddy validate --config "$CADDY_FILE" --adapter caddyfile

# validate PROVISIONS the configuration, and provisioning a `log { output file }`
# block OPENS the file — as root, because this script runs as root. That leaves
# /var/log/caddy/synaplan.log owned by root, mode 0600, and caddy.service
# (User=caddy) then dies in milliseconds on "permission denied" opening its own
# log. This killed a verification run whose whole stack was healthy behind the
# dead proxy. Hand everything back to the service user, every time validate ran.
chown -R caddy:caddy /var/log/caddy

# During the first boot systemd starts Caddy itself, right after this unit.
if systemctl is-active --quiet caddy.service; then
    systemctl daemon-reload
    systemctl reload-or-restart caddy.service
    log "Reloaded Caddy"
else
    systemctl daemon-reload
fi
