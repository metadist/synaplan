#!/usr/bin/env bash
# GitHub-hosted runners resolve through Azure's recursive DNS (168.63.129.16).
# That resolver intermittently cannot resolve archive.ubuntu.com or
# security.ubuntu.com while Cloudflare and Google still can — other names on
# the same runner keep working — so apt dies with "Temporary failure resolving"
# and Playwright never installs its system libraries.
#
# When a public resolver can see the Ubuntu archives, point systemd-resolved
# at Cloudflare and Google for the rest of the job. Writing /etc/resolv.conf
# is not enough: apt uses glibc, and glibc asks systemd-resolved, which keeps
# using the DHCP server on the default link until that link is replaced.
#
# When those resolvers cannot see the archives either, leave the runner's
# resolver alone (it can still resolve every other name) and point apt at
# Ubuntu mirrors outside the ubuntu.com zone.
set -euo pipefail

public_dns=(1.1.1.1 1.0.0.1 8.8.8.8 8.8.4.4)
probe_dns=(1.1.1.1 8.8.8.8)
fallback_mirrors=(
  "https://mirrors.edge.kernel.org/ubuntu/"
  "https://ftp.fau.de/ubuntu/"
)

log() {
  printf '%s\n' "$*"
}

as_root() {
  if [ "$(id -u)" -eq 0 ]; then
    "$@"
  else
    sudo "$@"
  fi
}

# Ask one recursive resolver directly, so a broken system resolver cannot
# hide a public one that already has the answer.
resolver_answers() {
  local server="$1"
  local name="$2"
  python3 - "$server" "$name" <<'PY'
import socket
import sys

server, name = sys.argv[1], sys.argv[2]
labels = b"".join(
    bytes([len(part)]) + part.encode("ascii") for part in name.split(".")
)
query = (
    b"\x12\x34"  # id
    b"\x01\x00"  # recursion desired
    b"\x00\x01\x00\x00\x00\x00\x00\x00"
    + labels
    + b"\x00"
    b"\x00\x01"  # A
    b"\x00\x01"  # IN
)

sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
sock.settimeout(2)
data = b""
last_error = "no reply"
for _ in range(2):
    try:
        sock.sendto(query, (server, 53))
        data, _addr = sock.recvfrom(2048)
        break
    except OSError as exc:
        last_error = str(exc)
        data = b""
sock.close()

if len(data) < 12:
    print(f"{server} did not answer for {name}: {last_error}", file=sys.stderr)
    sys.exit(1)

rcode = data[3] & 0x0F
ancount = int.from_bytes(data[6:8], "big")
truncated = (data[2] & 0x02) != 0
if rcode == 0 and (ancount > 0 or truncated):
    print(f"{server} resolved {name} ({ancount} answers)")
    sys.exit(0)

print(
    f"{server} has no answer for {name} (rcode={rcode}, answers={ancount})",
    file=sys.stderr,
)
sys.exit(1)
PY
}

any_public_resolver_answers() {
  local name="$1"
  local server
  for server in "${probe_dns[@]}"; do
    if resolver_answers "$server" "$name"; then
      return 0
    fi
  done
  return 1
}

host_resolves() {
  local host="$1"
  local attempt
  for attempt in 1 2 3; do
    if getent hosts "$host" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  return 1
}

configure_resolved() {
  local ns iface="" restart=()
  if ! command -v resolvectl >/dev/null 2>&1; then
    log "resolvectl is absent; writing public nameservers to /etc/resolv.conf."
    local resolv
    resolv="$(mktemp)"
    for ns in "${public_dns[@]}"; do
      printf 'nameserver %s\n' "$ns" >>"$resolv"
    done
    printf 'options timeout:2 attempts:3\n' >>"$resolv"
    as_root tee /etc/resolv.conf <"$resolv" >/dev/null
    rm -f "$resolv"
    return 0
  fi

  as_root mkdir -p /etc/systemd/resolved.conf.d
  as_root tee /etc/systemd/resolved.conf.d/public-dns.conf >/dev/null <<EOF
[Resolve]
DNS=${public_dns[*]}
FallbackDNS=9.9.9.9 149.112.112.112
Domains=~.
EOF

  if [ "$(id -u)" -eq 0 ]; then
    restart=(systemctl restart systemd-resolved)
  else
    restart=(sudo systemctl restart systemd-resolved)
  fi
  if ! timeout 30 "${restart[@]}"; then
    log "::warning::systemd-resolved did not restart; updating the link DNS anyway."
  fi

  # DHCP puts 168.63.129.16 back on the default link. A failure from that
  # server fails the lookup even when a public resolver would answer.
  if command -v ip >/dev/null 2>&1; then
    iface="$(ip -4 route show default 2>/dev/null | awk '{print $5; exit}')"
  fi
  if [ -n "$iface" ]; then
    as_root resolvectl dns "$iface" "${public_dns[@]}"
    as_root resolvectl domain "$iface" '~.'
    log "Default route ${iface} now uses ${public_dns[*]}."
  else
    log "::warning::No default IPv4 route; only the global resolver list was changed."
  fi

  as_root resolvectl flush-caches || log "::warning::Could not flush the resolver cache."
  log "Resolver after the change:"
  resolvectl dns || true
}

mirror_host() {
  local url="$1"
  url="${url#*://}"
  printf '%s\n' "${url%%/*}"
}

# A mirror list line is "<url>\tpriority:N". The direct-source rewrite must
# use one of these URLs: the first entry is the first mirror that resolved.
mirror_url_from_line() {
  printf '%s\n' "${1%%$'\t'*}"
}

use_fallback_mirrors() {
  local url host priority=1 line
  local -a lines=()
  for url in "${fallback_mirrors[@]}"; do
    host="$(mirror_host "$url")"
    if host_resolves "$host"; then
      line="$(printf '%s\tpriority:%s' "$url" "$priority")"
      lines+=("$line")
      priority=$((priority + 1))
      log "Fallback mirror ${url} resolves."
    else
      log "Fallback mirror ${host} did not resolve; skipping it."
    fi
  done

  if [ "${#lines[@]}" -eq 0 ]; then
    return 1
  fi

  if [ -f /etc/apt/apt-mirrors.txt ]; then
    printf '%s\n' "${lines[@]}" | as_root tee /etc/apt/apt-mirrors.txt >/dev/null
    log "Apt mirror list is now outside ubuntu.com:"
    cat /etc/apt/apt-mirrors.txt
  fi

  # Images that do not use apt-mirrors.txt name the archives directly.
  # Use a mirror that just resolved. Hardcoding the first candidate would
  # point apt at a name that failed the DNS check when only a later mirror
  # answered.
  local chosen file patched=false
  local -a files=()
  chosen="$(mirror_url_from_line "${lines[0]}")"
  if [ -f /etc/apt/sources.list ]; then
    files+=("/etc/apt/sources.list")
  fi
  for file in /etc/apt/sources.list.d/*; do
    [ -f "$file" ] || continue
    files+=("$file")
  done
  if [ "${#files[@]}" -gt 0 ]; then
    for file in "${files[@]}"; do
      if grep -qE 'archive\.ubuntu\.com|security\.ubuntu\.com' "$file"; then
        as_root sed -i -E \
          -e "s#https?://(azure\\.)?archive\\.ubuntu\\.com/ubuntu/?#${chosen}#g" \
          -e "s#https?://security\\.ubuntu\\.com/ubuntu/?#${chosen}#g" \
          "$file"
        log "Rewrote Ubuntu archive URIs in ${file} to ${chosen}."
        patched=true
      fi
    done
  fi

  if [ -f /etc/apt/apt-mirrors.txt ] || [ "$patched" = true ]; then
    return 0
  fi
  log "::error::No apt mirror list or Ubuntu source entry to retarget."
  return 1
}

self_test() {
  local host line
  host="$(mirror_host "https://mirrors.edge.kernel.org/ubuntu/")"
  [ "$host" = "mirrors.edge.kernel.org" ]
  host="$(mirror_host "https://ftp.fau.de/ubuntu/")"
  [ "$host" = "ftp.fau.de" ]
  line="$(printf '%s\tpriority:%s' "https://mirrors.edge.kernel.org/ubuntu/" 1)"
  [ "$line" = $'https://mirrors.edge.kernel.org/ubuntu/\tpriority:1' ]
  line="$(printf '%s\tpriority:%s' "https://ftp.fau.de/ubuntu/" 1)"
  [ "$(mirror_url_from_line "$line")" = "https://ftp.fau.de/ubuntu/" ]
  log "self-test ok"
}

main() {
  if ! command -v python3 >/dev/null 2>&1; then
    log "::error::python3 is required to probe the public resolvers."
    exit 1
  fi

  local switched=false
  log "Probing public resolvers for archive.ubuntu.com."
  if any_public_resolver_answers archive.ubuntu.com; then
    if configure_resolved; then
      switched=true
    else
      log "::warning::Could not switch the system resolver to public DNS."
    fi
    if [ "$switched" = true ] && host_resolves archive.ubuntu.com && host_resolves security.ubuntu.com; then
      log "archive.ubuntu.com and security.ubuntu.com resolve through public DNS."
      resolvectl query archive.ubuntu.com || true
      exit 0
    fi
    if [ "$switched" = true ]; then
      log "::warning::A public resolver answered directly, but the system resolver still cannot resolve the Ubuntu archives."
    fi
  else
    log "::warning::Neither Cloudflare nor Google DNS resolved archive.ubuntu.com. Leaving the runner's resolver in place."
  fi

  if [ "$switched" = false ] && host_resolves archive.ubuntu.com && host_resolves security.ubuntu.com; then
    log "The runner's current resolver can already resolve the Ubuntu archives."
    exit 0
  fi

  log "Pointing apt at Ubuntu mirrors outside the ubuntu.com zone."
  if use_fallback_mirrors; then
    exit 0
  fi

  log "::error::The Ubuntu archives did not resolve, and no fallback mirror resolved either."
  resolvectl status || true
  exit 1
}

if [ "${1:-}" = "--self-test" ]; then
  self_test
  exit 0
fi

main
