#!/usr/bin/env bash
# How many MariaDB connections do PHP workers hold while AI streams wait?
#
# Starts the worker-mode FrankenPHP of make perf-runtime (8 workers, :8090),
# streams N slow in-app chat turns as the demo user (test-model, 400 ms per
# chunk, no paid provider), and counts the connections from the backend's
# address before, during and after the streams. Leaves the stack as found.
#
#   _devextras/perf/runtime/db_connections.sh [streams]
set -euo pipefail

DIR=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$DIR/../../.." && pwd)
cd "$ROOT"

STREAMS=${1:-8}
CHUNK_MS=400

compose() {
  docker compose -p synaplan "$@"
}

if ! docker inspect synaplan-backend >/dev/null 2>&1; then
  echo "synaplan-backend is not running. Start the dev stack with: make up" >&2
  exit 1
fi
if [[ "$(docker exec synaplan-backend printenv APP_ENV 2>/dev/null || true)" != "dev" ]]; then
  echo "synaplan-backend is not APP_ENV=dev. This script only measures the dev stack." >&2
  exit 1
fi
if docker exec synaplan-backend sh -c 'pid=$(cat /tmp/synaplan-perf-franken.pid 2>/dev/null) && [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null'; then
  echo "A make perf-runtime run is active in synaplan-backend. Wait for it to finish." >&2
  exit 1
fi
CHAT_DEFAULT=$(compose exec -T db mariadb -N -usynaplan_user -psynaplan_password synaplan -e "SELECT m.BSERVICE FROM BCONFIG c JOIN BMODELS m ON m.BID = CAST(c.BVALUE AS SIGNED) WHERE c.BGROUP='DEFAULTMODEL' AND c.BSETTING='CHAT' AND c.BOWNERID IN (0, (SELECT BID FROM BUSER WHERE BMAIL='demo@synaplan.com')) ORDER BY c.BOWNERID DESC LIMIT 1;" | tr -d '\r')
if [[ "$CHAT_DEFAULT" != "test" ]]; then
  echo "The demo user's chat model is not the test provider (${CHAT_DEFAULT:-unset}). Refusing to call a paid provider." >&2
  exit 1
fi

NETWORK=$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}' synaplan-backend)
BACKEND_IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' synaplan-backend)
WORK=$(mktemp -d)
STARTED=0
WARMED=0
SAMPLER=0

cleanup() {
  rc=$?
  set +e
  if [[ "$SAMPLER" -ne 0 ]]; then
    kill "$SAMPLER" 2>/dev/null
    wait "$SAMPLER" 2>/dev/null
  fi
  if [[ "$STARTED" -eq 1 ]]; then
    compose exec -T backend sh -c '
      pid=$(cat /tmp/synaplan-perf-franken.pid 2>/dev/null || true)
      if [ -n "$pid" ] && [ "$pid" != "1" ]; then
        kill "$pid" 2>/dev/null || true
        i=0
        while kill -0 "$pid" 2>/dev/null && [ "$i" -lt 20 ]; do i=$((i + 1)); sleep 0.1; done
        kill -0 "$pid" 2>/dev/null && kill -9 "$pid" 2>/dev/null || true
      fi
    '
  fi
  if [[ "$WARMED" -eq 1 ]]; then
    compose exec -T backend rm -rf /var/www/backend/var/cache/prod
  fi
  compose exec -T backend sh -c 'rm -f /tmp/synaplan-perf-franken.pid /tmp/synaplan-perf-franken.log /tmp/perf-worker.Caddyfile'
  rm -rf "$WORK"
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

held() {
  compose exec -T db mariadb -N -usynaplan_user -psynaplan_password -e \
    "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE HOST LIKE '${BACKEND_IP}:%';" | tr -d '\r'
}

compose cp "$DIR/worker-mode.Caddyfile" backend:/tmp/perf-worker.Caddyfile
echo "Warming the prod cache..."
WARMED=1
compose exec -T -e APP_ENV=prod -e APP_DEBUG=0 -e SYNAPLAN_PERF_TEST_PROVIDER=1 \
  -e SYNAPLAN_TEST_PROVIDER_CHUNK_MS="$CHUNK_MS" backend php bin/console cache:warmup >/dev/null
compose exec -d -e APP_ENV=prod -e APP_DEBUG=0 -e SYNAPLAN_PERF_TEST_PROVIDER=1 \
  -e SYNAPLAN_TEST_PROVIDER_CHUNK_MS="$CHUNK_MS" -e SPIKE_WORKER_NUM=8 -e SPIKE_NUM_THREADS=12 \
  backend sh -c 'cd /var/www/backend && echo $$ > /tmp/synaplan-perf-franken.pid; exec frankenphp run --config /tmp/perf-worker.Caddyfile > /tmp/synaplan-perf-franken.log 2>&1'
STARTED=1
for _ in $(seq 1 60); do
  if compose exec -T backend php -r 'exit(@file_get_contents("http://127.0.0.1:8090/api/health") === false ? 1 : 0);'; then
    break
  fi
  sleep 1
done

BEFORE=$(held)
( while true; do held >>"$WORK/samples"; sleep 1; done ) &
SAMPLER=$!

set +e
docker run --rm --network "$NETWORK" -v "$DIR":/work:ro -w /work python:3.12-alpine \
  python stream_turns.py --base http://synaplan-backend:8090 --streams "$STREAMS" >"$WORK/turns.json"
turns_rc=$?
set -e

kill "$SAMPLER" 2>/dev/null || true
wait "$SAMPLER" 2>/dev/null || true
SAMPLER=0
sleep 3
AFTER=$(held)

python3 - "$WORK/turns.json" "$WORK/samples" "$BEFORE" "$AFTER" "$STREAMS" <<'PY'
import json, pathlib, sys
turns = json.loads(pathlib.Path(sys.argv[1]).read_text() or "{}")
samples = [int(x) for x in pathlib.Path(sys.argv[2]).read_text().split() if x.strip().isdigit()]
print(json.dumps({
    "streams": int(sys.argv[5]),
    "turns": turns,
    "connections_before": int(sys.argv[3]),
    "connections_during_max": max(samples) if samples else None,
    "connections_during_median": sorted(samples)[len(samples) // 2] if samples else None,
    "connections_after_idle": int(sys.argv[4]),
    "samples": len(samples),
}, indent=2))
PY
exit "$turns_rc"
