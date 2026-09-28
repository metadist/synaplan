#!/usr/bin/env bash
# The published compose file configures Centrifugo from environment variables.
# The channel namespaces must stay identical to the development config file,
# which the development compose files still mount.
set -euo pipefail

root="$(cd "$(dirname "$0")/../../.." && pwd)"
compose="$root/deploy/compose.yaml"
config="$root/_docker/centrifugo/config.json"

fail() {
    printf 'centrifugo-env: %s\n' "$1" >&2
    exit 1
}

grep -Fq '../_docker/centrifugo/config.json' "$compose" &&
    fail "deploy/compose.yaml still mounts the Centrifugo config file"
grep -Fq 'checkconfig' "$compose" &&
    fail "deploy/compose.yaml still health-checks Centrifugo with checkconfig"
grep -Fq 'http://127.0.0.1:8000/health' "$compose" ||
    fail "deploy/compose.yaml does not probe Centrifugo /health"

python3 - "$compose" "$config" << 'PY'
import json
import re
import sys

compose = open(sys.argv[1], encoding="utf-8").read()
config = json.load(open(sys.argv[2], encoding="utf-8"))
match = re.search(r"CENTRIFUGO_CHANNEL_NAMESPACES:\s*'([^']*)'", compose)
if not match:
    sys.exit("CENTRIFUGO_CHANNEL_NAMESPACES is missing from deploy/compose.yaml")
got = json.loads(match.group(1))
want = config["channel"]["namespaces"]
if got != want:
    sys.exit("channel namespaces in deploy/compose.yaml differ from _docker/centrifugo/config.json")
required = {
    "CENTRIFUGO_ENGINE_TYPE": "redis",
    "CENTRIFUGO_ENGINE_REDIS_ADDRESS": "redis://redis:6379/3",
    "CENTRIFUGO_CLIENT_USER_CONNECTION_LIMIT": "16",
    "CENTRIFUGO_CLIENT_PING_INTERVAL": "25s",
    "CENTRIFUGO_CLIENT_STALE_CLOSE_DELAY": "60s",
    "CENTRIFUGO_HEALTH_ENABLED": "true",
    "CENTRIFUGO_ADMIN_ENABLED": "true",
    "CENTRIFUGO_PROMETHEUS_ENABLED": "true",
}
for key, value in required.items():
    if f"{key}: {value}" not in compose and f'{key}: "{value}"' not in compose:
        sys.exit(f"{key} is not set to {value} in deploy/compose.yaml")
print("centrifugo-env: ok")
PY
