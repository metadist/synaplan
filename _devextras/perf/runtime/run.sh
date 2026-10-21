#!/usr/bin/env bash
# Measure the running dev stack and leave it as it was found.
# No second FrankenPHP, no var/cache/prod, no perf-runtime API key.
set -euo pipefail

DIR=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$DIR/../../.." && pwd)
cd "$ROOT"

WITH_PROVIDERS=0
for arg in "$@"; do
  case "$arg" in
    --with-providers) WITH_PROVIDERS=1 ;;
    -h|--help)
      echo "Usage: make perf-runtime    or    $0 [--with-providers]"
      echo "Paid providers stay unused unless --with-providers is set."
      exit 0
      ;;
    *)
      echo "Unknown argument: $arg" >&2
      exit 2
      ;;
  esac
done

if ! docker inspect synaplan-backend >/dev/null 2>&1; then
  echo "synaplan-backend is not running. Start the dev stack with: make up" >&2
  exit 1
fi

NETWORK=$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}' synaplan-backend)
if [[ -z "${NETWORK}" ]]; then
  echo "Could not find the backend container network." >&2
  exit 1
fi

STAMP=$(date +%Y%m%d-%H%M%S)
OUT_DIR="$ROOT/backend/var/perf"
mkdir -p "$OUT_DIR"
OUT_JSON="$OUT_DIR/runtime-$STAMP.json"
WORK=$(mktemp -d)
STARTED=0
MINTED=0

compose() {
  docker compose -p synaplan "$@"
}

cleanup() {
  rc=$?
  set +e
  if [[ "$MINTED" -eq 1 ]]; then
    compose exec -T backend php /tmp/perf-runtime/mint_key.php revoke /tmp/synaplan-perf-cookie.txt
    if [[ $? -ne 0 ]]; then
      echo "cleanup: failed to revoke the perf-runtime API key" >&2
      rc=1
    fi
  fi
  if [[ "$STARTED" -eq 1 ]]; then
    compose exec -T backend sh -c '
      pid=$(cat /tmp/synaplan-perf-franken.pid 2>/dev/null || true)
      if [ -n "$pid" ] && [ "$pid" != "1" ]; then
        kill "$pid" 2>/dev/null || true
        i=0
        while kill -0 "$pid" 2>/dev/null && [ "$i" -lt 20 ]; do
          i=$((i + 1))
          sleep 0.1
        done
        if kill -0 "$pid" 2>/dev/null; then
          kill -9 "$pid" 2>/dev/null || true
        fi
      fi
    '
  fi
  compose exec -T backend sh -c 'rm -rf /var/www/backend/var/cache/prod /tmp/synaplan-perf-franken.pid /tmp/synaplan-perf-franken.log /tmp/perf-worker.Caddyfile /tmp/synaplan-perf-key.json /tmp/synaplan-perf-cookie.txt /tmp/perf-runtime'
  rm -rf "$WORK"
  unset SYNAPLAN_API_KEY
  exit "$rc"
}
trap cleanup EXIT

if ! compose exec -T backend php -r 'require "/var/www/backend/vendor/autoload.php"; exit(class_exists("App\\AI\\Provider\\TestProviderChunkDelay") ? 0 : 1);'; then
  echo "The running backend does not have the test-provider delay class." >&2
  echo "This target measures the code the dev stack has mounted." >&2
  exit 1
fi

MODEL_ROW=$(compose exec -T db mariadb -N -usynaplan_user -psynaplan_password synaplan -e "SELECT CONCAT(BACTIVE,' ',BSERVICE) FROM BMODELS WHERE BPROVID='test-model' AND BTAG='chat' LIMIT 1;" | tr -d '\r')
MODEL_ACTIVE=${MODEL_ROW%% *}
if [[ "$MODEL_ACTIVE" != "1" ]]; then
  echo "test-model is not an active chat model (${MODEL_ROW:-missing}). Refusing to call the gateway." >&2
  exit 1
fi

compose exec -T backend mkdir -p /tmp/perf-runtime
compose cp "$DIR/mint_key.php" backend:/tmp/perf-runtime/mint_key.php
compose cp "$DIR/worker-mode.Caddyfile" backend:/tmp/perf-worker.Caddyfile

set +e
compose exec -T \
  -e SYNAPLAN_PERF_ADMIN_EMAIL="${SYNAPLAN_PERF_ADMIN_EMAIL:-admin@synaplan.com}" \
  -e SYNAPLAN_PERF_ADMIN_PASSWORD="${SYNAPLAN_PERF_ADMIN_PASSWORD:-admin123}" \
  backend php /tmp/perf-runtime/mint_key.php mint /tmp/synaplan-perf-key.json /tmp/synaplan-perf-cookie.txt \
  >"$WORK/mint.txt"
mint_rc=$?
set -e
if compose exec -T backend test -f /tmp/synaplan-perf-cookie.txt; then
  MINTED=1
fi
if [[ "$mint_rc" -ne 0 ]]; then
  echo "Could not create the temporary API key." >&2
  cat "$WORK/mint.txt" >&2 || true
  exit 1
fi
USER_ID=$(sed -n 's/^user_id=//p' "$WORK/mint.txt" | tr -d '\r')
if [[ ! "$USER_ID" =~ ^[0-9]+$ ]]; then
  echo "Mint did not report a numeric user id." >&2
  exit 1
fi

SYNAPLAN_API_KEY=$(compose exec -T backend php -r 'echo json_decode((string) file_get_contents("/tmp/synaplan-perf-key.json"), true)["api_key"]["key"];')
SYNAPLAN_API_KEY=${SYNAPLAN_API_KEY//$'\r'/}
SYNAPLAN_API_KEY=${SYNAPLAN_API_KEY//$'\n'/}
export SYNAPLAN_API_KEY

echo "Warming the prod cache..."
compose exec -T \
  -e APP_ENV=prod \
  -e APP_DEBUG=0 \
  -e SYNAPLAN_PERF_TEST_PROVIDER=1 \
  -e SYNAPLAN_TEST_PROVIDER_CHUNK_MS=400 \
  backend php bin/console cache:warmup

compose exec -d \
  -e APP_ENV=prod \
  -e APP_DEBUG=0 \
  -e SYNAPLAN_PERF_TEST_PROVIDER=1 \
  -e SYNAPLAN_TEST_PROVIDER_CHUNK_MS=400 \
  -e SPIKE_WORKER_NUM=8 \
  -e SPIKE_NUM_THREADS=12 \
  backend sh -c 'cd /var/www/backend && echo $$ > /tmp/synaplan-perf-franken.pid; exec frankenphp run --config /tmp/perf-worker.Caddyfile > /tmp/synaplan-perf-franken.log 2>&1'
STARTED=1

ready=0
i=0
while [[ "$i" -lt 60 ]]; do
  i=$((i + 1))
  if compose exec -T backend php -r 'exit(@file_get_contents("http://127.0.0.1:8090/api/health") === false ? 1 : 0);'; then
    ready=1
    break
  fi
  sleep 1
done
if [[ "$ready" -ne 1 ]]; then
  echo "Worker-mode FrankenPHP did not answer /api/health on :8090." >&2
  compose exec -T backend tail -n 40 /tmp/synaplan-perf-franken.log >&2 || true
  exit 1
fi

py() {
  docker run --rm --network "$NETWORK" \
    -e SYNAPLAN_API_KEY \
    -v "$DIR":/work:ro \
    -w /work \
    python:3.12-alpine python "$@"
}

echo "Measuring health and runtime config..."
py microreq.py --json --base http://synaplan-backend:8090 --path /api/health --clients 1 --requests 100 >"$WORK/health1.json"
py microreq.py --json --base http://synaplan-backend:8090 --path /api/health --clients 8 --requests 100 >"$WORK/health8.json"
py microreq.py --json --base http://synaplan-backend:8090 --path /api/v1/config/runtime --clients 4 --requests 40 >"$WORK/runtime.json"

echo "Measuring test-model gateway latency..."
set +e
py gateway_ttft.py --base http://synaplan-backend:8090 --runs 5 --expect-canned >"$WORK/gateway.json"
gw_rc=$?
set -e
if [[ "$gw_rc" -ne 0 ]]; then
  echo "gateway measurement failed (exit ${gw_rc}):" >&2
  cat "$WORK/gateway.json" >&2 || true
  exit "$gw_rc"
fi

echo "Holding 12 slow streams..."
py saturate.py \
  --base http://synaplan-backend:8090 \
  --admin-url http://synaplan-backend:2020 \
  --model test-model \
  --streams 12 \
  --prompt hello \
  --max-tokens 64 \
  >"$WORK/saturate.json"

PHASE_ROW=$(compose exec -T db mariadb -N -usynaplan_user -psynaplan_password synaplan -e "SELECT CONCAT(IFNULL(m.BSERVICE,'none'),' ',IFNULL(m.BPROVID,'')) FROM BCONFIG c LEFT JOIN BMODELS m ON m.BID = CAST(c.BVALUE AS SIGNED) WHERE c.BGROUP='DEFAULTMODEL' AND c.BSETTING='CHAT' AND c.BOWNERID IN (0, ${USER_ID}) ORDER BY c.BOWNERID DESC LIMIT 1;" | tr -d '\r')
PHASE_SERVICE=${PHASE_ROW%% *}
if [[ "$WITH_PROVIDERS" -eq 0 && "$PHASE_SERVICE" != "test" ]]; then
  python3 - "$PHASE_ROW" >"$WORK/phases.json" <<'PY'
import json, sys
print(json.dumps({
    "skipped": True,
    "reason": "default chat model is not the test provider (" + (sys.argv[1] or "unset") + "); pass --with-providers to run it",
}))
PY
else
  set +e
  compose exec -T backend php bin/console app:perf:chat-stream --user-id="$USER_ID" --runs=1 >"$WORK/phases.txt"
  phase_rc=$?
  set -e
  python3 - "$phase_rc" "$WORK/phases.txt" >"$WORK/phases.json" <<'PY'
import json, pathlib, sys
text = pathlib.Path(sys.argv[2]).read_text(encoding="utf-8", errors="replace")
print(json.dumps({"skipped": False, "ok": sys.argv[1] == "0", "output": text[-4000:]}))
PY
fi

if [[ "$WITH_PROVIDERS" -eq 1 && -f "$ROOT/backend/.env" ]]; then
  docker run --rm --network "$NETWORK" \
    -e SYNAPLAN_API_KEY \
    -v "$DIR":/work:ro \
    -v "$ROOT/backend/.env":/env:ro \
    -w /work \
    python:3.12-alpine \
    python overhead.py --runs 3 --env-file /env --base http://synaplan-backend:8090 --skip-messages \
    >"$WORK/overhead.txt" || true
fi

python3 "$DIR/check_budgets.py" assemble "$WORK" "$OUT_JSON"
echo "Wrote $OUT_JSON"
set +e
python3 "$DIR/check_budgets.py" check "$OUT_JSON" "$DIR/budgets.json"
check_rc=$?
python3 "$DIR/check_budgets.py" expect-breach "$OUT_JSON" "$DIR/budgets.json" "health.clients1.p95_ms=1"
breach_rc=$?
set -e
if [[ "$breach_rc" -ne 0 ]]; then
  echo "The 1 ms budget override did not fail the checker." >&2
  exit 1
fi
exit "$check_rc"
