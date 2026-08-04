#!/usr/bin/env bash

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

assert_healthy() {
    local service="$1"
    local container_id status
    container_id="$(compose ps -q "$service")"
    [[ -n "$container_id" ]] || {
        echo "$service is not running" >&2
        return 1
    }
    status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_id")"
    [[ "$status" == "healthy" || "$status" == "running" ]] || {
        echo "$service is $status" >&2
        return 1
    }
    printf 'ok: %s\n' "$service"
}

compose exec -T backend curl -fsS -o /dev/null http://localhost/api/health
printf 'ok: api health\n'
compose exec -T backend curl -fsS -o /dev/null http://localhost/
printf 'ok: web ui\n'

assert_healthy backend
assert_healthy worker
assert_healthy scheduler

[[ "$(compose exec -T redis redis-cli ping)" == "PONG" ]]
printf 'ok: redis\n'

app_tool "curl -fsS http://qdrant:6333/readyz >/dev/null"
printf 'ok: qdrant\n'
app_tool "curl -fsS http://tika:9998/version >/dev/null"
printf 'ok: tika\n'
app_tool "curl -fsS http://centrifugo:8000/health >/dev/null"
printf 'ok: centrifugo\n'

echo "Synaplan smoke test passed."
