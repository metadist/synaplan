# Runtime spike (2026-10-21)

Measurements behind [`../02_measurements.md`](../02_measurements.md). Every
number there comes from one of these scripts, run against the local dev
stack on `main` at `4dd1b26da`. Nothing here is product code; nothing here is
deployed.

| Path | What it measures | Needs |
| ---- | ---------------- | ----- |
| [`runtime/saturate.py`](./runtime/saturate.py) | Holds N streaming AI requests open (`POST /v1/chat/completions`, `stream: true`) and times a cheap PHP request (`GET /api/health`) every 250 ms while they run. Samples FrankenPHP's per-thread state from the Caddy admin API | Python 3 (stdlib), a Synaplan API key with `desktop:messages` |
| [`runtime/overhead.py`](./runtime/overhead.py) | Time to first token: the provider directly vs. the same call through Synaplan's gateways | Provider keys in `backend/.env` (read, never printed) |
| [`runtime/microreq.py`](./runtime/microreq.py) | Throughput of short PHP requests on keep-alive connections | Python 3 |
| [`runtime/worker-mode.Caddyfile`](./runtime/worker-mode.Caddyfile) | A second FrankenPHP inside the dev backend container, in production worker mode, on `:8090` (admin `:2020`, Docker network only) | the dev stack |
| [`runtime/split-pools.Caddyfile`](./runtime/split-pools.Caddyfile) | Two worker pools (`match /v1/chat/completions` → `streams`, the rest → `api`) and `max_wait_time` | the dev stack |
| [`relay/`](./relay/main.go) | A Go relay (stdlib only) holding 100 … 2,000 slow streams from a stub upstream; memory and CPU per stream | Docker (`golang:1.25.8`) |
| [`pump/pump.php`](./pump/pump.php) | One PHP CLI process holding 100 … 1,000 upstream streams at once with Symfony HttpClient (`stream()` over curl_multi) | the backend container, the relay stub as upstream |

## What counts as a result

After the Copilot review of [#2623](https://github.com/metadist/synaplan/pull/2623)
the scripts are strict, and every table in `02` comes from the strict re-run:

- a stream is successful only with HTTP 200, answer text and its end marker
  (`[DONE]`); everything else is counted as failed, never averaged in;
- time to first token is the first non-empty **text** delta, not a role or
  metadata event; `overhead.py` reuses one warm keep-alive connection per
  destination (after a warm-up call) so the direct call and Synaplan's own
  provider connection are compared fairly, and asks for `max_tokens 400` so a
  reasoning model reaches text;
- the pump reports the process's resident memory (`VmRSS` / `VmHWM`), which
  includes libcurl's buffers, next to the PHP heap;
- `RACE=1 relay/run.sh` builds the relay with the Go race detector.

## Reproduce

```bash
# 0. Dev stack up, then create a temporary key for a test account
#    (Settings → API keys, scope desktop:messages), save the JSON response:
#    {"api_key": {"key": "sk_…", …}} → /tmp/syn-key.json

# 1. Classic mode (the dev backend as it runs): 20 threads on a 10-core host
python3 runtime/saturate.py --streams 20 --model claude-haiku-4-5-20251001
python3 runtime/saturate.py --streams 30 --model claude-haiku-4-5-20251001

# 2. Gateway overhead
python3 runtime/overhead.py --runs 10 --env-file ../../../../backend/.env

# 3. Production worker mode, inside the dev backend container
docker exec synaplan-backend sh -c 'cd /var/www/backend && APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup'
docker cp runtime/worker-mode.Caddyfile synaplan-backend:/tmp/spike-worker.Caddyfile
docker exec -d synaplan-backend sh -c 'cd /var/www/backend && APP_ENV=prod APP_DEBUG=0 SPIKE_WORKER_NUM=8 frankenphp run --config /tmp/spike-worker.Caddyfile'
docker run --rm --network synaplan_synaplan-network -v "$PWD/runtime":/spike:ro -v /tmp/syn-key.json:/key.json:ro \
  python:3.12-slim python /spike/saturate.py --base http://synaplan-backend:8090 \
  --admin-url http://synaplan-backend:2020 --key-file /key.json --streams 12 --model claude-haiku-4-5-20251001

# 4. Go relay
relay/run.sh

# 5. PHP stream pump (start the relay stub as upstream on the Synaplan network first)
docker run -d --name spike-upstream --network synaplan_synaplan-network -v "$PWD/relay":/src -w /src \
  golang:1.25.8 sh -c 'go build -o /tmp/relay main.go && /tmp/relay --mode upstream --listen :9001'
docker cp pump/pump.php synaplan-backend:/tmp/pump.php
docker exec synaplan-backend php -d memory_limit=1G /tmp/pump.php http://spike-upstream:9001/stream 1000
```

## Clean up (what the 2026-10-21 run did afterwards)

- Stop the spike FrankenPHP (`kill` its PID; `ps` and `pkill` are not in the image), `rm -rf var/cache/prod`.
- `docker rm -f spike-upstream`.
- Revoke the temporary API keys; delete test chats.
- `MESSAGES_GATEWAY.ENABLED` and `ALLOW_OPERATOR_KEY` were set to 1 for one measurement and back to 0 at once.
- Usage rows of the runs stay in the dev database (about 150 short turns on three test accounts).

The spike FrankenPHP writes `/config/caddy/autosave.json` in the container; the
dev backend does not start with `--resume`, so this has no effect.
