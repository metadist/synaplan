#!/usr/bin/env bash
# Contract for the opt-in local-network certificate. No Docker.

set -Eeuo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SCRIPT="$ROOT/scripts/local-tls.sh"
CADDYFILE="$ROOT/local-network/Caddyfile"
COMPOSE="$ROOT/compose.yaml"
EXAMPLE="$ROOT/selfhost.env.example"

fail() {
    printf '%s\n' "$*" >&2
    exit 1
}

[[ -x "$SCRIPT" ]] || fail "local-tls.sh is not executable"
[[ -f "$CADDYFILE" ]] || fail "missing $CADDYFILE"

grep -Fq 'auto_https off' "$CADDYFILE" || fail "Caddyfile must disable automatic certificates"
grep -Fq '/etc/caddy/selfsigned/cert.pem' "$CADDYFILE" || fail "Caddyfile must load the minted certificate"
grep -Fq 'reverse_proxy backend:80' "$CADDYFILE" || fail "Caddyfile must proxy to the web container"
grep -Fq 'flush_interval -1' "$CADDYFILE" || fail "Caddyfile must flush chat streams immediately"
if grep -Eq '^[[:space:]]*tls[[:space:]]+internal([[:space:]]|$)' "$CADDYFILE"; then
    fail "Caddyfile uses tls internal, which cannot answer a browser that connects by a bare address"
fi
if grep -Fq 'Strict-Transport-Security' "$CADDYFILE"; then
    fail "Caddyfile must not pin HSTS for a certificate created on the machine"
fi

grep -Fq 'profiles: [local-tls]' "$COMPOSE" || fail "compose.yaml is missing the local-tls profile"
grep -Fq 'caddy:2.10.2-alpine@sha256:4c6e91c6ed0e2fa03efd5b44747b625fec79bc9cd06ac5235a779726618e530d' "$COMPOSE" || fail "tls-proxy image is not pinned"
grep -Fq '${SYNAPLAN_HTTP_BIND:-127.0.0.1}:${SYNAPLAN_HTTP_PORT:-8000}:80' "$COMPOSE" || fail "the application port default left 127.0.0.1"

if grep -q 'is_private_ipv4' "$SCRIPT"; then
    fail "local-tls.sh still limits certificates to one private range"
fi

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
preexisting=false
[[ -d "$ROOT/data/tls" ]] && preexisting=true

assert_check() {
    local address="$1"
    bash "$SCRIPT" --check-address "$address" || fail "expected to accept ${address}"
}

assert_check 10.0.0.15
assert_check 172.16.0.1
assert_check 172.31.255.4
assert_check 192.168.1.20
assert_check 100.64.0.8
assert_check 100.127.255.9
assert_check 169.254.1.20
assert_check 192.0.2.10
assert_check 198.51.100.10
assert_check 203.0.113.10
assert_check 198.18.0.5
assert_check 240.1.2.3
assert_check 8.8.8.8

reject() {
    local address="$1"
    if bash "$SCRIPT" --check-address "$address"; then
        fail "expected to reject ${address}"
    fi
}

reject ai.example.com
reject 0.0.0.0
reject 255.255.255.255
reject 224.0.0.1
reject 10.0.0.256
reject 10.0.0.01

env_file="$tmp/env"
cert_dir="$tmp/tls"
cp "$EXAMPLE" "$env_file"
bash "$SCRIPT" --env-file "$env_file" --cert-dir "$cert_dir" 10.0.0.15 >/dev/null
grep -Fxq 'APP_URL=https://10.0.0.15' "$env_file" || fail "APP_URL was not set to the address"
grep -Fxq 'FRONTEND_URL=https://10.0.0.15' "$env_file" || fail "FRONTEND_URL was not set"
grep -Fxq 'REALTIME_ALLOWED_ORIGINS=https://10.0.0.15' "$env_file" || fail "REALTIME_ALLOWED_ORIGINS was not set"
grep -Fxq 'SYNAPLAN_HTTP_BIND=127.0.0.1' "$env_file" || fail "HTTP bind left the loopback address"
grep -Fxq 'COMPOSE_PROFILES=local-tls' "$env_file" || fail "local-tls profile was not added"
[[ -s "$cert_dir/cert.pem" && -s "$cert_dir/key.pem" ]] || fail "certificate files were not written"
[[ "$(stat -c %a "$cert_dir/key.pem")" == "600" ]] || fail "private key mode is not 0600"

san="$(openssl x509 -in "$cert_dir/cert.pem" -noout -ext subjectAltName)"
[[ "$san" == *"10.0.0.15"* ]] || fail "certificate does not name 10.0.0.15: ${san}"

key_first="$(sha256sum "$cert_dir/key.pem")"
bash "$SCRIPT" --env-file "$env_file" --cert-dir "$cert_dir" 10.0.0.15 >/dev/null
[[ "$(sha256sum "$cert_dir/key.pem")" == "$key_first" ]] || fail "an unchanged address replaced the certificate"
grep -Fxq 'COMPOSE_PROFILES=local-tls' "$env_file" || fail "profile was duplicated"

# A second interface on another unrouted block is added. The previous key
# changes because the certificate has to name the new address.
bash "$SCRIPT" --env-file "$env_file" --cert-dir "$cert_dir" 10.0.0.15 192.168.1.20 100.64.0.8 >/dev/null
[[ "$(sha256sum "$cert_dir/key.pem")" != "$key_first" ]] || fail "a new address kept the old certificate"
san="$(openssl x509 -in "$cert_dir/cert.pem" -noout -ext subjectAltName)"
[[ "$san" == *"10.0.0.15"* && "$san" == *"192.168.1.20"* && "$san" == *"100.64.0.8"* ]] || fail "certificate is missing an address: ${san}"
grep -Fxq 'APP_URL=https://10.0.0.15' "$env_file" || fail "the public URL followed an extra address"

profiles="$tmp/profiles.env"
cp "$EXAMPLE" "$profiles"
sed -i 's/^COMPOSE_PROFILES=.*/COMPOSE_PROFILES=local-ai/' "$profiles"
bash "$SCRIPT" --env-file "$profiles" --cert-dir "$tmp/tls-profiles" 172.16.5.4 >/dev/null
grep -Fxq 'COMPOSE_PROFILES=local-ai,local-tls' "$profiles" || fail "local-ai profile was dropped"
grep -Fxq 'APP_URL=https://172.16.5.4' "$profiles" || fail "172.16 address was rejected"
bash "$SCRIPT" --env-file "$profiles" --cert-dir "$tmp/tls-profiles" 172.16.5.4 >/dev/null
grep -Fxq 'COMPOSE_PROFILES=local-ai,local-tls' "$profiles" || fail "profile list grew a second local-tls"

named="$tmp/named.env"
cp "$EXAMPLE" "$named"
sed -i 's|^APP_URL=.*|APP_URL=https://ai.example.com|' "$named"
if bash "$SCRIPT" --env-file "$named" --cert-dir "$tmp/tls-named" 10.9.9.9 >/dev/null; then
    fail "a named APP_URL was replaced"
fi
grep -Fxq 'APP_URL=https://ai.example.com' "$named" || fail "the named APP_URL was edited"
[[ ! -e "$tmp/tls-named/key.pem" ]] || fail "a refused run still wrote a private key"

bash "$SCRIPT" --force --env-file "$named" --cert-dir "$tmp/tls-named" https://192.168.50.2 >/dev/null
grep -Fxq 'APP_URL=https://192.168.50.2' "$named" || fail "--force did not apply the address"

bind="$tmp/bind.env"
cp "$EXAMPLE" "$bind"
sed -i 's/^SYNAPLAN_HTTP_BIND=.*/SYNAPLAN_HTTP_BIND=0.0.0.0/' "$bind"
bash "$SCRIPT" --env-file "$bind" --cert-dir "$tmp/tls-bind" 169.254.10.9 >/dev/null
grep -Fxq 'SYNAPLAN_HTTP_BIND=127.0.0.1' "$bind" || fail "a wide HTTP bind was left in place"
grep -Fxq 'APP_URL=https://169.254.10.9' "$bind" || fail "link-local address was rejected"

if [[ "$preexisting" == false && -d "$ROOT/data/tls" ]]; then
    fail "tests wrote deploy/data/tls"
fi

printf 'local-tls contract tests passed.\n'
