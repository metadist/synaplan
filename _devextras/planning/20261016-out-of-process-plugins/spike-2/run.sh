#!/usr/bin/env bash
# Review spike (2026-10-18) — not product code. Reproduces the evidence of
# 05_review.md; the results are in results.md.
#
#   ./run.sh                     all three parts
#   ./run.sh eras                MCP protocol eras: current SDKs and a stdio bridge
#                                against Synaplan's real McpClient
#   ./run.sh identity            the call-token vector in PHP, Python, Node.js and Go,
#                                plus the draft pseudonym reversal
#   ./run.sh compose             how Compose treats an included plugin file, then a
#                                plugin started with `include:` on the `plugins` network
#   ./run.sh --down              remove the era containers and their network
#
# Needs Docker and the Synaplan backend image (built by `make up`); the dev stack
# itself does not have to run. Writes nothing into the repository: sources are
# copied to a temp folder that the containers mount.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
repo="$(cd "$here/../../../.." && pwd)"
spike1="$here/../spike"
work="${TMPDIR:-/tmp}/synaplan-op-review-spike"
net=op-review-spike
containers=(rv-py rv-go rv-node1 rv-node2 rv-node2-modern rv-bridge)

backend_image() {
  if [[ -n "${SYNAPLAN_BACKEND_IMAGE:-}" ]]; then
    echo "$SYNAPLAN_BACKEND_IMAGE"
  elif docker inspect synaplan-backend >/dev/null 2>&1; then
    docker inspect synaplan-backend --format '{{.Image}}'
  else
    docker images -q --filter label=com.docker.compose.service=backend | head -1
  fi
}

# The real backend code and vendor/ from this checkout, in the backend image's PHP.
php_in_backend() {
  docker run --rm --entrypoint php --network "$net" \
    -v "$repo/backend:/var/www/backend:ro" -v "$work:/spike:ro" "$(backend_image)" "$@"
}

down() {
  docker rm -f "${containers[@]}" >/dev/null 2>&1 || true
  docker network rm "$net" >/dev/null 2>&1 || true
}

wait_for() {
  local url="$1"
  for _ in $(seq 1 150); do
    if docker run --rm --network "$net" node:22-slim node -e "fetch(process.argv[1],{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json, text/event-stream'},body:'{}'}).then(()=>process.exit(0),()=>process.exit(1))" "$url" >/dev/null 2>&1; then
      return 0
    fi
    sleep 2
  done
  echo "Timed out waiting for $url" >&2
  return 1
}

eras() {
  echo "=== Part 1 — MCP protocol eras against the real McpClient"
  down
  docker network create "$net" >/dev/null
  # Sources are mounted read-only and copied inside, so installs never leave
  # root-owned files in the temp folder.
  docker run -d --name rv-py --network "$net" -v "$work/spike1/python:/src:ro" python:3.12-slim \
    sh -c "cp -R /src /app && cd /app && pip install -q --root-user-action=ignore mcp==2.3.0 && python server.py" >/dev/null
  docker run -d --name rv-go --network "$net" -v "$work/spike1/go:/src:ro" golang:1.25 \
    sh -c "cp -R /src /app && cd /app && go mod init example.com/gotools >/dev/null 2>&1; go get github.com/modelcontextprotocol/go-sdk@v1.8.0 && go mod tidy && go run ." >/dev/null
  docker run -d --name rv-node1 --network "$net" -v "$work/spike1/node:/src:ro" node:22-slim \
    sh -c "cp -R /src /app && cd /app && npm init -y >/dev/null && npm install --silent @modelcontextprotocol/sdk@1.32.1 express@5.3.0 zod@4.6.7 && node server.mjs" >/dev/null
  for name in rv-node2 rv-node2-modern; do
    legacy=stateless
    [[ "$name" == rv-node2-modern ]] && legacy=reject
    docker run -d --name "$name" --network "$net" -e MCP_LEGACY="$legacy" -v "$work/node-v2:/src:ro" node:22-slim \
      sh -c "cp -R /src /app && cd /app && npm init -y >/dev/null && npm install --silent @modelcontextprotocol/server@2.3.1 @modelcontextprotocol/node@2.1.1 zod@4.6.7 && node server.mjs" >/dev/null
  done
  docker run -d --name rv-bridge --network "$net" -w /app node:22-slim \
    sh -c "npm init -y >/dev/null && npm install --silent supergateway@4.2.0 @modelcontextprotocol/server-everything@1.0.0 && npx supergateway --stdio 'npx mcp-server-everything' --outputTransport streamableHttp --port 8000 --healthEndpoint /healthz" >/dev/null

  urls=(http://rv-py:8000/mcp http://rv-go:8000/mcp http://rv-node1:8000/mcp http://rv-node2:8000/mcp http://rv-node2-modern:8000/mcp http://rv-bridge:8000/mcp)
  for url in "${urls[@]}"; do wait_for "$url"; done

  echo "--- which era does each server answer (raw requests)"
  docker run --rm --network "$net" -v "$work/eras:/probe:ro" node:22-slim node /probe/era_probe.mjs "${urls[@]}"
  echo
  echo "--- Synaplan's McpClient (backend $(backend_image | cut -c1-19)), guard in operator-network mode as in OP0"
  php_in_backend /spike/spike1/mcp_client_probe.php \
    http://rv-py:8000/mcp word_stats '{"text":"Synaplan tools in any language."}' \
    http://rv-go:8000/mcp reverse_text '{"text":"synaplan"}' \
    http://rv-node1:8000/mcp slugify '{"text":"TypeScript SDK v1"}' \
    http://rv-node2:8000/mcp slugify '{"text":"TypeScript SDK v2 default"}' \
    http://rv-node2-modern:8000/mcp slugify '{"text":"TypeScript SDK v2 modern only"}' \
    http://rv-bridge:8000/mcp echo '{"message":"a stdio server behind a bridge"}'
}

identity() {
  echo "=== Part 2 — the call token of 02_contracts.md §3 in four languages"
  docker network create "$net" >/dev/null 2>&1 || true
  if [[ "${MINT:-0}" == 1 ]]; then
    php_in_backend /spike/identity/mint_token.php mint > "$here/identity/vector.json"
    cp "$here/identity/vector.json" "$work/identity/vector.json"
    echo "Minted a new vector into identity/vector.json"
  fi
  php_in_backend /spike/identity/mint_token.php verify /spike/identity/vector.json
  docker run --rm -v "$work/identity:/v:ro" -w /v python:3.12-slim \
    sh -c "pip install -q --root-user-action=ignore 'pyjwt[crypto]==2.15.1' && python verify_token.py vector.json"
  docker run --rm -v "$work/identity:/v:ro" -w /v node:22-slim node verify_token.mjs vector.json
  docker run --rm -v "$work/identity:/v:ro" -w /v golang:1.25 go run verify_token.go vector.json
  echo
  echo "=== The 2026-10-16 draft pseudonym, recovered by the service that holds the secret"
  docker run --rm -v "$work/identity:/v:ro" -w /v python:3.12-slim python pseudonym_reversal.py
}

compose() {
  echo "=== Part 3 — how Compose treats a plugin file the operator includes"
  "$work/compose/semantics.sh"
  echo
  echo "=== Part 3b — a plugin started together with Synaplan through include:"
  (
    cd "$work/compose"
    docker compose up -d --quiet-pull >/dev/null 2>&1
    sleep 2
    echo "--- the backend reaches the plugin by its service name"
    docker compose exec -T backend wget -qO- -T 3 http://word-tools:8000/mcp
    echo "--- the plugin reaches Synaplan's published keys through the alias"
    docker compose exec -T word-tools wget -qO- -T 3 http://synaplan/.well-known/synaplan-jwks.json
    echo "--- the plugin cannot reach the database"
    docker compose exec -T word-tools wget -qO- -T 3 http://db:3306/ 2>&1 || true
    docker compose down -t 1 >/dev/null 2>&1
  )
}

if [[ "${1:-}" == "--down" ]]; then
  down
  echo "Removed: ${containers[*]} and network $net"
  exit 0
fi

if ! rm -rf "$work" 2>/dev/null; then
  # A run of an older version of this script left root-owned installs behind.
  docker run --rm -v "$work:/w" alpine:3 sh -c 'rm -rf /w/* /w/.[!.]*' && rm -rf "$work"
fi
mkdir -p "$work/spike1" "$work/node-v2"
cp -R "$spike1/servers/." "$work/spike1/"
cp "$spike1/mcp_client_probe.php" "$work/spike1/"
cp "$here/servers/node-v2/server.mjs" "$work/node-v2/"
cp -R "$here/eras" "$here/identity" "$here/compose" "$work/"

parts=("$@")
[[ ${#parts[@]} -eq 0 ]] && parts=(eras identity compose)
for part in "${parts[@]}"; do
  case "$part" in
    eras) eras ;;
    identity) identity ;;
    compose) compose ;;
    *) echo "Unknown part: $part" >&2; exit 2 ;;
  esac
  echo
done
echo "Era servers keep running for inspection. Remove them with: $0 --down"
