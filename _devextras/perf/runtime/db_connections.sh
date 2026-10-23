#!/usr/bin/env bash
# How many MariaDB connections do PHP workers hold while AI streams wait?
#
# Starts the worker-mode FrankenPHP of make perf-runtime (8 workers, :8090),
# streams N slow in-app chat turns as the demo user (test-model, 400 ms per
# chunk), and counts the connections from the backend's address before,
# during and after the streams. Leaves the stack as found.
#
# No paid provider: the demo user's chat, sorting and memory models must be the
# test provider, the measured FrankenPHP runs without Qdrant (so no memory
# search embeds the question), and the run fails if the usage ledger shows a
# call to any other provider.
#
#   _devextras/perf/runtime/db_connections.sh [streams]
set -euo pipefail

DIR=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$DIR/../../.." && pwd)
cd "$ROOT"

STREAMS=${1:-8}
CHUNK_MS=400
DEMO_USER="(SELECT BID FROM BUSER WHERE BMAIL='demo@synaplan.com')"

compose() {
  docker compose -p synaplan "$@"
}

sql() {
  compose exec -T db mariadb -N -usynaplan_user -psynaplan_password synaplan -e "$1" | tr -d '\r'
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
# A turn is sorted (SORT), answered (CHAT) and has its memories mined
# afterwards (MEM).
for setting in CHAT SORT MEM; do
  service=$(sql "SELECT m.BSERVICE FROM BCONFIG c JOIN BMODELS m ON m.BID = CAST(c.BVALUE AS SIGNED) WHERE c.BGROUP='DEFAULTMODEL' AND c.BSETTING='$setting' AND c.BOWNERID IN (0, $DEMO_USER) ORDER BY c.BOWNERID DESC LIMIT 1;")
  if [[ "$service" != "test" ]]; then
    echo "The demo user's $setting model is not the test provider (${service:-unset}). Refusing to call a paid provider." >&2
    exit 1
  fi
done

NETWORK=$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}' synaplan-backend)
BACKEND_IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' synaplan-backend)
WORK=$(mktemp -d)
STARTED=0
WARMED=0
SAMPLER=0

turns() {
  docker run --rm --network "$NETWORK" -v "$DIR":/work:ro -v "$WORK":/state -w /work -e SYNAPLAN_PERF_PASSWORD \
    python:3.12-alpine python stream_turns.py "$1" --state /state/turns.state \
    --base http://synaplan-backend:8090 --streams "$STREAMS"
}

cleanup() {
  rc=$?
  set +e
  if [[ "$SAMPLER" -ne 0 ]]; then
    kill "$SAMPLER" 2>/dev/null
    wait "$SAMPLER" 2>/dev/null
  fi
  if [[ -f "$WORK/turns.state" ]]; then
    turns cleanup >/dev/null 2>&1
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
  sql "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE HOST LIKE '${BACKEND_IP}:%';"
}

compose cp "$DIR/worker-mode.Caddyfile" backend:/tmp/perf-worker.Caddyfile
echo "Warming the prod cache..."
WARMED=1
compose exec -T -e APP_ENV=prod -e APP_DEBUG=0 -e SYNAPLAN_PERF_TEST_PROVIDER=1 \
  -e SYNAPLAN_TEST_PROVIDER_CHUNK_MS="$CHUNK_MS" backend php bin/console cache:warmup >/dev/null
compose exec -d -e APP_ENV=prod -e APP_DEBUG=0 -e SYNAPLAN_PERF_TEST_PROVIDER=1 \
  -e SYNAPLAN_TEST_PROVIDER_CHUNK_MS="$CHUNK_MS" -e SPIKE_WORKER_NUM=8 -e SPIKE_NUM_THREADS=12 -e QDRANT_URL= \
  backend sh -c 'cd /var/www/backend && echo $$ > /tmp/synaplan-perf-franken.pid; exec frankenphp run --config /tmp/perf-worker.Caddyfile > /tmp/synaplan-perf-franken.log 2>&1'
STARTED=1
for _ in $(seq 1 60); do
  if compose exec -T backend php -r 'exit(@file_get_contents("http://127.0.0.1:8090/api/health") === false ? 1 : 0);'; then
    break
  fi
  sleep 1
done

START=$(sql "SELECT UNIX_TIMESTAMP();")
turns prepare
BEFORE=$(held)
( while true; do held >>"$WORK/samples"; sleep 1; done ) &
SAMPLER=$!

set +e
turns stream >"$WORK/turns.json"
turns_rc=$?
set -e

kill "$SAMPLER" 2>/dev/null || true
wait "$SAMPLER" 2>/dev/null || true
SAMPLER=0
sleep 3
AFTER=$(held)
turns cleanup

sql "SELECT CONCAT(BACTION, ' ', BPROVIDER, ' ', BMODEL, ' x', COUNT(*)) FROM BUSELOG WHERE BUSERID = $DEMO_USER AND BUNIXTIMES >= $START AND LOWER(BPROVIDER) <> 'test' GROUP BY BACTION, BPROVIDER, BMODEL;" >"$WORK/other_calls"

python3 - "$WORK/turns.json" "$WORK/samples" "$BEFORE" "$AFTER" "$STREAMS" "$WORK/other_calls" <<'PY'
import json, pathlib, sys
turns = json.loads(pathlib.Path(sys.argv[1]).read_text() or "{}")
samples = [int(x) for x in pathlib.Path(sys.argv[2]).read_text().split() if x.strip().isdigit()]
other = [line for line in pathlib.Path(sys.argv[6]).read_text().splitlines() if line.strip()]
print(json.dumps({
    "streams": int(sys.argv[5]),
    "turns": turns,
    "connections_before": int(sys.argv[3]),
    "connections_during_max": max(samples) if samples else None,
    "connections_during_median": sorted(samples)[len(samples) // 2] if samples else None,
    "connections_after_idle": int(sys.argv[4]),
    "samples": samples,
    "calls_to_other_providers": other,
}, indent=2))
PY
if [[ -s "$WORK/other_calls" ]]; then
  echo "The run called a provider other than the test provider (see calls_to_other_providers)." >&2
  exit 1
fi
exit "$turns_rc"
