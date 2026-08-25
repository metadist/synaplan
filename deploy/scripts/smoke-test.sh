#!/usr/bin/env bash

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

# Documented as directly runnable, and every probe below is a Compose call.
ensure_deployment_secrets

# "starting" is not a verdict: it is Docker's healthcheck start window (up to
# start_period, 300s for the backend), and it resolves to healthy or unhealthy
# on its own. A smoke test run right after boot — which is exactly when one is
# run — used to fail on it while the API was already answering. So: wait out
# "starting" up to the longest start_period, fail immediately on any real
# verdict.
HEALTH_VERDICT_TIMEOUT=300

assert_healthy() {
    local service="$1"
    local container_id status waited=0
    container_id="$(compose ps -q "$service")"
    [[ -n "$container_id" ]] || {
        echo "$service is not running" >&2
        return 1
    }
    while :; do
        status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container_id")"
        case "$status" in
            healthy|running)
                printf 'ok: %s\n' "$service"
                return 0
                ;;
            starting)
                if (( waited >= HEALTH_VERDICT_TIMEOUT )); then
                    echo "$service is still starting after ${waited}s; its healthcheck never passed" >&2
                    return 1
                fi
                (( waited % 30 == 0 )) && echo "$service is starting; waiting for its healthcheck to decide"
                sleep 5
                waited=$((waited + 5))
                ;;
            *)
                echo "$service is $status" >&2
                return 1
                ;;
        esac
    done
}

compose exec -T backend curl -fsS -o /dev/null http://localhost/api/health
printf 'ok: api health\n'
compose exec -T backend curl -fsS -o /dev/null http://localhost/
printf 'ok: web ui\n'

assert_healthy backend
assert_healthy worker
assert_healthy scheduler

[[ "$(compose exec -T redis redis-cli ping)" == "PONG" ]] || {
    echo "redis did not answer PING with PONG" >&2
    exit 1
}
printf 'ok: redis\n'

app_tool "curl -fsS http://qdrant:6333/readyz >/dev/null"
printf 'ok: qdrant\n'
app_tool "curl -fsS http://tika:9998/version >/dev/null"
printf 'ok: tika\n'
app_tool "curl -fsS http://centrifugo:8000/health >/dev/null"
printf 'ok: centrifugo\n'

echo "Synaplan smoke test passed."
