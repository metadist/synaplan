#!/usr/bin/env bash
# OP0 spike — not product code. Reproduces 01_research.md §5 on the local dev stack.
#
#   ./run.sh          start the four servers, run experiments A and B, print the results
#   ./run.sh --down   remove the four containers
#
# Needs the dev stack (`make up`) and Docker access. Writes nothing into the
# repository: sources are copied to a temp folder that the containers mount.
# Experiment A logs in as the seeded demo user and deletes what it creates.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
api="${SYNAPLAN_API:-http://localhost:8000}"
email="${SYNAPLAN_EMAIL:-demo@synaplan.com}"
password="${SYNAPLAN_PASSWORD:-demo123}"
backend="${SYNAPLAN_BACKEND_CONTAINER:-synaplan-backend}"
work="${TMPDIR:-/tmp}/synaplan-op0-spike"
containers=(py-tool py-rest node-tool go-tool)

down() { docker rm -f "${containers[@]}" >/dev/null 2>&1 || true; }

if [[ "${1:-}" == "--down" ]]; then
  down
  echo "Removed: ${containers[*]}"
  exit 0
fi

net="$(docker inspect "$backend" --format '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}} {{end}}' 2>/dev/null | awk '{print $1}')"
if [[ -z "$net" ]]; then
  echo "The backend container '$backend' is not running. Start the dev stack first (make up)." >&2
  exit 1
fi

rm -rf "$work"
mkdir -p "$work"
cp -R "$here/servers/." "$work/"

echo "Starting the servers on network $net …"
down
docker run -d --name py-tool --network "$net" -v "$work/python:/app" -w /app python:3.12-slim \
  sh -c "pip install -q --root-user-action=ignore mcp==2.3.0 && python server.py" >/dev/null
docker run -d --name py-rest --network "$net" -v "$work/python:/app" -w /app python:3.12-slim \
  sh -c "pip install -q --root-user-action=ignore fastapi==0.143.2 uvicorn==0.54.0 && uvicorn rest:app --host 0.0.0.0 --port 8001" >/dev/null
docker run -d --name node-tool --network "$net" -v "$work/node:/app" -w /app node:22-slim \
  sh -c "npm init -y >/dev/null && npm install --silent @modelcontextprotocol/sdk@1.32.1 express@5.3.0 zod@4.6.7 && node server.mjs" >/dev/null
docker run -d --name go-tool --network "$net" -v "$work/go:/app" -w /app golang:1.25 \
  sh -c "go mod init example.com/gotools >/dev/null 2>&1; go get github.com/modelcontextprotocol/go-sdk@v1.8.0 && go mod tidy && go run ." >/dev/null

wait_for() {
  local url="$1"
  for _ in $(seq 1 120); do
    if docker exec "$backend" php -r '$c = stream_context_create(["http" => ["ignore_errors" => true, "timeout" => 2]]); exit(false === @file_get_contents($argv[1], false, $c) ? 1 : 0);' "$url"; then
      return 0
    fi
    sleep 2
  done
  echo "Timed out waiting for $url" >&2
  return 1
}
for url in http://py-tool:8000/mcp http://node-tool:8000/mcp http://go-tool:8000/mcp http://py-rest:8001/openapi.json; do
  wait_for "$url"
done
echo

# Prints the body and the status code; echoes the code for the caller.
call() {
  local method="$1" path="$2" data="${3:-}" response code body
  if [[ -n "$data" ]]; then
    response="$(curl -s -b "$work/cookies.txt" -H 'Content-Type: application/json' -X "$method" "$api$path" -d "$data" -w $'\n%{http_code}')"
  else
    response="$(curl -s -b "$work/cookies.txt" -X "$method" "$api$path" -w $'\n%{http_code}')"
  fi
  code="${response##*$'\n'}"
  body="${response%$'\n'*}"
  echo "### $method $path" >&2
  echo "$body  [HTTP $code]" >&2
  echo "$code|$body"
}

created_id() { sed -n 's/.*"id":\([0-9][0-9]*\).*/\1/p' <<<"$1" | head -1; }

echo "=== Experiment A — the real API as $email"
curl -s -c "$work/cookies.txt" -H 'Content-Type: application/json' -X POST "$api/api/v1/auth/login" \
  -d "{\"email\":\"$email\",\"password\":\"$password\"}" >/dev/null
call GET /api/v1/mcp-servers >/dev/null
for svc in py-tool node-tool go-tool; do
  out="$(call POST /api/v1/mcp-servers "{\"name\":\"OP0 spike $svc\",\"url\":\"http://$svc:8000/mcp\",\"auth_mode\":\"none\"}")"
  if [[ "${out%%|*}" == 201 ]]; then
    call DELETE "/api/v1/mcp-servers/$(created_id "${out#*|}")" >/dev/null
  fi
done
out="$(call POST /api/v1/tools/custom '{"name":"op0_spike_word_stats","sideEffect":"read","spec":{"method":"GET","url":"http://py-rest:8001/word-stats","query":{"text":"{{input.text}}"}}}')"
if [[ "${out%%|*}" == 201 ]]; then
  tool_id="$(created_id "${out#*|}")"
  call GET '/api/v1/tools?source=custom' >/dev/null
  call POST "/api/v1/tools/custom/$tool_id/try" '{"input":{"text":"hello from synaplan"}}' >/dev/null
  call DELETE "/api/v1/tools/custom/$tool_id" >/dev/null
fi
call POST /api/v1/tools/custom/import-openapi/preview '{"url":"http://py-rest:8001/openapi.json"}' >/dev/null
echo

echo "=== Experiment B — the real McpClient, SSRF guard in its operator-network mode"
docker cp "$here/mcp_client_probe.php" "$backend:/tmp/mcp_client_probe.php" >/dev/null
docker exec "$backend" php /tmp/mcp_client_probe.php "$@"

echo "The servers keep running for OP1–OP3. Remove them with: $0 --down"
