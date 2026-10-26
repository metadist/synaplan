#!/usr/bin/env bash
# Two pools: slow chat streams must not slow the api pool, and a stream that
# cannot get a worker is answered with the server_busy body.
#
# Starts the same worker-mode FrankenPHP as make perf-runtime, with 2 stream
# workers and 2 api workers. Leaves the dev stack as it found it.
#
#   _devextras/perf/runtime/pool-isolation.sh
set -euo pipefail

DIR=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$DIR/../../.." && pwd)
cd "$ROOT"

CHUNK_MS=400
MAX_WAIT=2s

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

NETWORK=$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}' synaplan-backend)
WORK=$(mktemp -d)
STARTED=0
WARMED=0

cleanup() {
  rc=$?
  set +e
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

# The production snippet is a global frankenphp block. This server wraps it
# and repeats the site's 503 bodies so the measurement uses the same pools.
cat >"$WORK/server.Caddyfile" <<'EOF'
{
	admin 0.0.0.0:2020
	auto_https off
	metrics
	import /tmp/perf-worker.Caddyfile
	order php_server before file_server
}

:8090 {
	root * /var/www/backend/public
	reverse_proxy 127.0.0.1:8092 {
		@busy status 503
		handle_response @busy {
			@busy_api path /api/v1/messages/stream* /api/v1/widget/*/message /api/v1/tts/stream
			handle @busy_api {
				header Retry-After "5"
				header Content-Type "application/json"
				respond `{"error":"server_busy","retryAfter":5}` 503
			}
			@busy_openai path /v1/chat/completions /v1/audio/transcriptions/sessions/*/stream
			handle @busy_openai {
				header Retry-After "5"
				header Content-Type "application/json"
				respond `{"error":{"message":"Synaplan is busy. Retry in a few seconds.","type":"server_error","code":"server_busy"}}` 503
			}
			@busy_anthropic path /v1/messages
			handle @busy_anthropic {
				header Retry-After "5"
				header Content-Type "application/json"
				respond `{"type":"error","error":{"type":"overloaded_error","message":"Synaplan is busy. Retry in a few seconds."}}` 503
			}
			@busy_mcp path /mcp
			handle @busy_mcp {
				header Retry-After "5"
				header Content-Type "application/json"
				respond `{"jsonrpc":"2.0","error":{"code":-32000,"message":"Synaplan is busy. Retry in a few seconds."},"id":null}` 503
			}
			handle {
				header Retry-After "5"
				header Content-Type "application/json"
				respond `{"error":"server_busy","retryAfter":5}` 503
			}
		}
		handle_response {
			copy_response
		}
	}
}

:8092 {
	bind 127.0.0.1
	root * /var/www/backend/public
	php_server {
		import /tmp/perf-workers.Caddyfile
	}
}
EOF

compose cp "$ROOT/_docker/backend/synaplan-worker-mode.Caddyfile" backend:/tmp/perf-worker.Caddyfile
compose cp "$ROOT/_docker/backend/synaplan-php-workers.Caddyfile" backend:/tmp/perf-workers.Caddyfile
compose cp "$WORK/server.Caddyfile" backend:/tmp/perf-server.Caddyfile

if ! compose exec -T -e FRANKENPHP_WORKER_NUM=2 -e FRANKENPHP_STREAM_WORKER_NUM=2 -e FRANKENPHP_MAX_WAIT_TIME="$MAX_WAIT" \
  backend frankenphp validate --config /tmp/perf-server.Caddyfile >/tmp/au3-validate.txt 2>&1; then
  echo "The split-pool Caddyfile did not validate." >&2
  cat /tmp/au3-validate.txt >&2
  exit 1
fi

echo "Warming the prod cache..."
WARMED=1
compose exec -T -e APP_ENV=prod -e APP_DEBUG=0 -e SYNAPLAN_PERF_TEST_PROVIDER=1 \
  -e SYNAPLAN_TEST_PROVIDER_CHUNK_MS="$CHUNK_MS" backend php bin/console cache:warmup >/dev/null
compose exec -d \
  -e APP_ENV=prod -e APP_DEBUG=0 -e SYNAPLAN_PERF_TEST_PROVIDER=1 \
  -e SYNAPLAN_TEST_PROVIDER_CHUNK_MS="$CHUNK_MS" \
  -e FRANKENPHP_WORKER_NUM=2 -e FRANKENPHP_STREAM_WORKER_NUM=2 \
  -e FRANKENPHP_MAX_WAIT_TIME="$MAX_WAIT" -e FRANKENPHP_NUM_THREADS=8 \
  backend sh -c 'cd /var/www/backend && echo $$ > /tmp/synaplan-perf-franken.pid; exec frankenphp run --config /tmp/perf-server.Caddyfile > /tmp/synaplan-perf-franken.log 2>&1'
STARTED=1

ready=0
for _ in $(seq 1 60); do
  if compose exec -T backend php -r 'exit(@file_get_contents("http://127.0.0.1:8090/api/health") === false ? 1 : 0);'; then
    ready=1
    break
  fi
  sleep 1
done
if [[ "$ready" -ne 1 ]]; then
  echo "Worker-mode FrankenPHP did not answer /api/health on :8090." >&2
  compose exec -T backend tail -n 60 /tmp/synaplan-perf-franken.log >&2 || true
  exit 1
fi

compose exec -T backend sh -c 'wget -qO- http://127.0.0.1:2020/metrics 2>/dev/null || curl -fsS http://127.0.0.1:2020/metrics' >"$WORK/metrics.txt" || true

docker run --rm --network "$NETWORK" -v "$DIR":/work:ro -w /work python:3.12-alpine \
  python pool_hold.py --base http://synaplan-backend:8090 >"$WORK/holds.json"

python3 - "$WORK/holds.json" "$WORK/metrics.txt" <<'PY'
import json, pathlib, sys
holds = json.loads(pathlib.Path(sys.argv[1]).read_text() or "{}")
metrics = pathlib.Path(sys.argv[2]).read_text(errors="replace")
workers = sorted({line.split("{", 1)[1].split("}", 1)[0] for line in metrics.splitlines() if line.startswith("frankenphp_total_workers{")})
print(json.dumps({"holds": holds, "worker_labels": workers}, indent=2))
probe = (holds.get("probe") or {})
busy = holds.get("busy_count") or 0
p95 = probe.get("p95_ms")
if not workers:
    raise SystemExit("metrics did not name the worker pools")
if "worker=\"streams\"" not in "".join(workers) or "worker=\"api\"" not in "".join(workers):
    raise SystemExit("metrics are missing the streams or api pool: " + ", ".join(workers))
if busy < 1:
    raise SystemExit("no stream was answered with server_busy")
if p95 is None or p95 > 50:
    raise SystemExit(f"api probe p95 was {p95} ms")
PY
