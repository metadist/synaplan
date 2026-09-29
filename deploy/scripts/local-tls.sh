#!/usr/bin/env bash
#
# HTTPS for a Synaplan install on a local network.
#
# Opt-in. Colleagues on a network with no route to the public internet open
# the app by this machine's address (https://10.0.0.15/). Chat needs that
# HTTPS page. Any IPv4 address on that network is accepted: private ranges,
# link-local, shared (CGNAT), documentation and benchmark blocks, and any
# other block the site assigned and does not announce.
#
#   deploy/scripts/local-tls.sh 10.0.0.15
#   deploy/scripts/local-tls.sh 10.0.0.15 192.168.1.20
#   deploy/scripts/local-tls.sh --force 10.0.0.15
#
# The first address is the one people type. Further addresses are added to
# the certificate for a second interface. The key is written to
# deploy/data/tls/key.pem (mode 0600). An existing certificate is kept when
# it already names every address, so browsers keep the exception they stored.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$SCRIPT_DIR/lib.sh"

ENV_FILE="${DEPLOY_DIR}/.env"
CERT_DIR="${DATA_DIR}/tls"
SKIP_ENV=false
FORCE=false
CHECK_ADDRESS=""
addresses=()

log() { printf '[local-tls] %s\n' "$*"; }
die() { printf '[local-tls] %s\n' "$*" >&2; exit 1; }

usage() {
    cat <<'USAGE'
Usage: deploy/scripts/local-tls.sh [--force] [--env-file FILE] [--cert-dir DIR] <address> [address...]

  <address>   The IPv4 address colleagues open. Any address on a network
              with no public route: 10.0.0.15, 192.168.1.20, 172.16.5.4,
              100.64.0.8, 169.254.1.20, or another block this site uses.
              https://10.0.0.15 is accepted and means the same thing.
              Further addresses are extra names on the same certificate.
  --force     Replace an existing certificate, and replace an APP_URL that
              already uses a public name.
  --env-file  Deployment environment file. Default: deploy/.env
  --cert-dir  Where the certificate is written. Default: deploy/data/tls

The command adds the local-tls profile and sets APP_URL, FRONTEND_URL and
REALTIME_ALLOWED_ORIGINS to https://<address>. It leaves the application
bound to 127.0.0.1. Ports 80 and 443 must be free. Each browser warns once.
USAGE
}

is_ipv4() {
    local ip="$1" octet
    [[ "$ip" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] || return 1
    local -a octets=("${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}" "${BASH_REMATCH[3]}" "${BASH_REMATCH[4]}")
    for octet in "${octets[@]}"; do
        if [[ "$octet" =~ ^0[0-9] ]]; then
            return 1
        fi
        if ((10#$octet > 255)); then
            return 1
        fi
    done
    if [[ "$ip" == "0.0.0.0" || "$ip" == "255.255.255.255" ]]; then
        return 1
    fi
    # Multicast is not a host people open in a browser.
    local first="${ip%%.*}"
    if ((10#$first >= 224 && 10#$first <= 239)); then
        return 1
    fi
    return 0
}

normalize_address() {
    local raw="$1"
    raw="${raw#https://}"
    raw="${raw#http://}"
    raw="${raw%%/*}"
    raw="${raw%/}"
    if [[ "$raw" == *:443 ]]; then
        raw="${raw%:443}"
    fi
    if [[ "$raw" == *:* ]]; then
        die "Use the address without a port ($1). Colleagues open https://<address>/ on port 443."
    fi
    if ! is_ipv4 "$raw"; then
        die "Not an IPv4 host address: ${1}. Pass the address people open on this network, for example 10.0.0.15 or 192.168.1.20. A public name uses its own HTTPS certificate."
    fi
    printf '%s\n' "$raw"
}

url_host() {
    local value="$1"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    value="${value#https://}"
    value="${value#http://}"
    value="${value%%/*}"
    value="${value%%:*}"
    printf '%s\n' "$value"
}

upsert_env() {
    local file="$1" key="$2" value="$3" tmp line stripped cr found=0
    tmp="$(mktemp)"
    while IFS= read -r line || [[ -n "$line" ]]; do
        stripped="${line%$'\r'}"
        cr=""
        [[ "$line" != "$stripped" ]] && cr=$'\r'
        if [[ "$stripped" == "$key="* ]]; then
            printf '%s=%s%s\n' "$key" "$value" "$cr" >> "$tmp"
            found=1
        else
            printf '%s\n' "$line" >> "$tmp"
        fi
    done < "$file"
    if [[ "$found" -eq 0 ]]; then
        printf '%s=%s\n' "$key" "$value" >> "$tmp"
    fi
    mv "$tmp" "$file"
}

merge_profile() {
    local current="$1" profile="$2" item rebuilt="" found=false
    current="${current%$'\r'}"
    current="${current// /}"
    if [[ -z "$current" ]]; then
        printf '%s\n' "$profile"
        return
    fi
    local -a items=()
    IFS=',' read -ra items <<< "$current"
    for item in "${items[@]}"; do
        [[ -z "$item" ]] && continue
        [[ "$item" == "$profile" ]] && found=true
        if [[ -n "$rebuilt" ]]; then
            rebuilt+=",$item"
        else
            rebuilt="$item"
        fi
    done
    [[ "$found" == true ]] || rebuilt+=",$profile"
    printf '%s\n' "$rebuilt"
}

san_names() {
    local ip san="DNS:localhost,IP:127.0.0.1,IP:::1"
    for ip in "$@"; do
        [[ "$ip" == "127.0.0.1" ]] && continue
        san+=",IP:${ip}"
    done
    printf '%s\n' "$san"
}

certificate_covers() {
    local cert="$1" san ip escaped
    shift
    [[ -s "$cert" && -s "$(dirname "$cert")/key.pem" ]] || return 1
    san="$(openssl x509 -in "$cert" -noout -ext subjectAltName 2>/dev/null || true)"
    [[ -n "$san" ]] || return 1
    for ip in "$@"; do
        escaped="${ip//./\\.}"
        [[ "$san" =~ IP\ Address:${escaped}([^0-9]|$) ]] && continue
        [[ "$san" =~ IP:${escaped}([^0-9]|$) ]] && continue
        return 1
    done
}

mint_certificate() {
    local dir="$1"
    shift
    local san
    san="$(san_names "$@")"
    install -d -m 0700 "$dir"
    if ! openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes \
        -keyout "$dir/key.pem.new" -out "$dir/cert.pem.new" \
        -days 3650 -subj '/CN=synaplan' \
        -addext "subjectAltName=${san}" \
        2>"$dir/openssl.err"; then
        cat "$dir/openssl.err" >&2
        rm -f "$dir/openssl.err" "$dir/key.pem.new" "$dir/cert.pem.new"
        die "openssl could not create the certificate."
    fi
    rm -f "$dir/openssl.err"
    chmod 0600 "$dir/key.pem.new"
    chmod 0644 "$dir/cert.pem.new"
    mv -f "$dir/key.pem.new" "$dir/key.pem"
    mv -f "$dir/cert.pem.new" "$dir/cert.pem"
    chmod 0700 "$dir"
    log "Created a certificate for ${san}"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        -h|--help)
            usage
            exit 0
            ;;
        --force)
            FORCE=true
            shift
            ;;
        --skip-env)
            SKIP_ENV=true
            shift
            ;;
        --env-file)
            [[ $# -ge 2 ]] || die "--env-file needs a path"
            ENV_FILE="$2"
            shift 2
            ;;
        --cert-dir)
            [[ $# -ge 2 ]] || die "--cert-dir needs a path"
            CERT_DIR="$2"
            shift 2
            ;;
        --check-address)
            [[ $# -ge 2 ]] || die "--check-address needs an address"
            CHECK_ADDRESS="$2"
            shift 2
            ;;
        --)
            shift
            break
            ;;
        -*)
            die "Unknown option: $1"
            ;;
        *)
            addresses+=("$1")
            shift
            ;;
    esac
done

if [[ -n "$CHECK_ADDRESS" ]]; then
    is_ipv4 "$CHECK_ADDRESS"
    exit
fi

while [[ $# -gt 0 ]]; do
    addresses+=("$1")
    shift
done

[[ ${#addresses[@]} -gt 0 ]] || {
    usage >&2
    die "Pass the address colleagues will open, for example 10.0.0.15."
}

normalized=()
for raw in "${addresses[@]}"; do
    one="$(normalize_address "$raw")"
    duplicate=false
    if [[ ${#normalized[@]} -gt 0 ]]; then
        for have in "${normalized[@]}"; do
            [[ "$have" == "$one" ]] && duplicate=true
        done
    fi
    [[ "$duplicate" == true ]] && continue
    normalized+=("$one")
done
addresses=("${normalized[@]}")
primary="${addresses[0]}"

if [[ "$primary" == "127.0.0.1" ]]; then
    log "Warning: 127.0.0.1 is this machine only. Pass the address colleagues use, for example 10.0.0.15."
fi

if [[ "$SKIP_ENV" == false ]]; then
    if [[ ! -f "$ENV_FILE" ]]; then
        [[ -f "${DEPLOY_DIR}/selfhost.env.example" ]] || die "Missing ${DEPLOY_DIR}/selfhost.env.example"
        cp "${DEPLOY_DIR}/selfhost.env.example" "$ENV_FILE"
        chmod 600 "$ENV_FILE"
        log "Created ${ENV_FILE} from selfhost.env.example"
    fi
    existing_url="$(env_file_raw_value "$ENV_FILE" APP_URL || true)"
    existing_host="$(url_host "$existing_url")"
    if [[ -n "$existing_host" && "$existing_host" != "$primary" ]] && ! is_ipv4 "$existing_host"; then
        [[ "$FORCE" == true ]] || die "APP_URL is ${existing_url}. This command is for a local-network address. Run it again with --force to replace that URL."
    fi
fi

command -v openssl >/dev/null 2>&1 || die "openssl is required to create the certificate. Install it and run this command again."

if [[ "$FORCE" == false ]] && certificate_covers "$CERT_DIR/cert.pem" "${addresses[@]}"; then
    log "Keeping the existing certificate. It already names: ${addresses[*]}."
else
    mint_certificate "$CERT_DIR" "${addresses[@]}"
    if [[ "$FORCE" == true ]]; then
        log "Browsers that already accepted the previous certificate will warn again."
    fi
fi

if [[ "$SKIP_ENV" == false ]]; then
    public_url="https://${primary}"
    previous_bind="$(env_file_raw_value "$ENV_FILE" SYNAPLAN_HTTP_BIND || true)"
    profiles="$(env_file_raw_value "$ENV_FILE" COMPOSE_PROFILES || true)"
    upsert_env "$ENV_FILE" APP_URL "$public_url"
    upsert_env "$ENV_FILE" FRONTEND_URL "$public_url"
    upsert_env "$ENV_FILE" REALTIME_ALLOWED_ORIGINS "$public_url"
    upsert_env "$ENV_FILE" SYNAPLAN_HTTP_BIND "127.0.0.1"
    upsert_env "$ENV_FILE" COMPOSE_PROFILES "$(merge_profile "$profiles" local-tls)"
    if [[ -n "$previous_bind" && "$previous_bind" != "127.0.0.1" ]]; then
        log "Set SYNAPLAN_HTTP_BIND back to 127.0.0.1. The network reaches the app through HTTPS."
    fi
    log "Set the public address to ${public_url}"
fi

if command -v ss >/dev/null 2>&1; then
    for port in 80 443; do
        if ss -ltn 2>/dev/null | grep -q ":${port} "; then
            log "Warning: port ${port} is already in use. Free it before starting the stack."
        fi
    done
fi

http_port="8000"
if [[ "$SKIP_ENV" == false ]]; then
    http_port="$(env_file_raw_value "$ENV_FILE" SYNAPLAN_HTTP_PORT || true)"
    [[ -n "$http_port" ]] || http_port="8000"
fi

log "Colleagues open https://${primary}/"
log "The browser warns once. That is expected: the certificate was created on this machine."
log "On this machine the app also answers at http://127.0.0.1:${http_port}"
log "Back up ${CERT_DIR} with the rest of the data directory. Creating the certificate again makes every browser warn again."
if [[ "$SKIP_ENV" == false ]]; then
    log "Start or restart with: docker compose --env-file ${ENV_FILE} -f ${DEPLOY_DIR}/compose.yaml up -d"
fi
