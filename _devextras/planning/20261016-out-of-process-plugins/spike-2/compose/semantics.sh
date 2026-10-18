#!/usr/bin/env bash
# Review spike (2026-10-18) — not product code. How Docker Compose treats a
# plugin's compose file that the operator includes. Uses `docker compose config`
# only: no container starts, nothing is written outside a temp folder.
set -euo pipefail

scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT
cd "$scratch"
mkdir -p plugins/word_tools

synaplan() {
  # A stand-in for Synaplan's compose file: one PHP role and the database.
  cat > "${1:-docker-compose.yml}" <<'EOF'
name: demo
services:
  backend:
    image: alpine:3
    environment: {APP: "1"}
    networks: [synaplan-network, plugins]
  db:
    image: alpine:3
    networks: [synaplan-network]
networks:
  synaplan-network: {driver: bridge}
  plugins: {driver: bridge}
EOF
}
plugin() { printf '%s\n' "$1" > plugins/word_tools/compose.yaml; }
include_from() { printf 'include:\n  - plugins/word_tools/compose.yaml\n' > "$1"; }
networks_of() { docker compose "$@" config --format json | python3 -c 'import json,sys; s=json.load(sys.stdin)["services"]; print({k: sorted(v.get("networks", {})) for k, v in s.items()})'; }

synaplan
include_from docker-compose.override.yml

echo "A. The plugin file names no network:"
plugin $'services:\n  word-tools:\n    image: alpine:3'
echo "   $(networks_of)"

echo "B. The plugin file names the shared 'plugins' key (defined only by Synaplan's file):"
plugin $'services:\n  word-tools:\n    image: alpine:3\n    networks: [plugins]'
echo "   $(networks_of)"

echo "C. The plugin file declares 'plugins' itself as well:"
plugin $'services:\n  word-tools:\n    image: alpine:3\n    networks: [plugins]\nnetworks:\n  plugins: {}'
echo "   $(networks_of)"

echo "D. The plugin file reads a variable that only the project .env sets:"
printf 'WORD_TOOLS_API_KEY=from-project-env\n' > .env
plugin $'services:\n  word-tools:\n    image: alpine:3\n    environment:\n      API_KEY: ${WORD_TOOLS_API_KEY:-unset}'
echo "   $(docker compose config --format json | python3 -c 'import json,sys; print(json.load(sys.stdin)["services"]["word-tools"]["environment"])')"
rm .env

echo "E. Which files load (self-host layout: compose.yaml + compose.override.yaml):"
rm docker-compose.yml docker-compose.override.yml
synaplan compose.yaml
include_from compose.override.yaml
plugin $'services:\n  word-tools:\n    image: alpine:3\n    networks: [plugins]'
echo "   docker compose config:                 $(docker compose config --services | tr '\n' ' ')"
echo "   docker compose -f compose.yaml config: $(docker compose -f compose.yaml config --services | tr '\n' ' ')"

echo "F. The plugin file defines a service with the name of one of Synaplan's:"
plugin $'services:\n  backend:\n    image: alpine:3\n    privileged: true\n    volumes: ["/var/run/docker.sock:/var/run/docker.sock"]\n    environment: {INJECTED: "yes"}\n    networks: [synaplan-network]'
docker compose config --format json | python3 -c '
import json, sys
b = json.load(sys.stdin)["services"]["backend"]
print("   exit 0; Synaplan backend now has privileged=%s, environment=%s, volumes=%s" % (b.get("privileged", False), b.get("environment"), [v["source"] for v in b.get("volumes", [])]))'
