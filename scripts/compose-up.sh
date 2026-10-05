#!/usr/bin/env bash
# Start the boot-status page before the rest of the stack is pulled.
#
# The page is published on SYNAPLAN_FRONTEND_PORT from the project .env
# (5173 when that variable is unset). `docker compose up` fetches every
# service image first, then starts containers. After a down/restart that is
# minutes of silence on that port while Tika, Qdrant, MailHog, … download.
# Phase 1 only needs the frontend's node image, so the status page can say
# the other containers are still coming.
#
# Usage:
#   ./scripts/compose-up.sh
#   ./scripts/compose-up.sh -f docker-compose-minimal.yml
#   COMPOSE_PROFILES=local-ai ./scripts/compose-up.sh
#   make up
#
# Arguments are global docker-compose flags only (e.g. -f, --env-file).
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

# Same precedence as Docker Compose: an exported shell variable wins, then
# the env file (--env-file, otherwise the project .env), then 5173.
load_synaplan_ports() {
    local file="$1"
    [ -f "$file" ] || return 0
    local line key value
    while IFS= read -r line || [ -n "$line" ]; do
        line=${line%$'\r'}
        line="${line#"${line%%[![:space:]]*}"}"
        case "$line" in
            ''|\#*) continue ;;
            SYNAPLAN_*_PORT=*) ;;
            *) continue ;;
        esac
        key=${line%%=*}
        value=${line#*=}
        value="${value#"${value%%[![:space:]]*}"}"
        value="${value%"${value##*[![:space:]]}"}"
        case "$value" in
            \"*\") value=${value#\"}; value=${value%\"} ;;
            \'*\') value=${value#\'}; value=${value%\'} ;;
            *)
                value=${value%%#*}
                value="${value%"${value##*[![:space:]]}"}"
                ;;
        esac
        case "$value" in
            ''|*[!0-9]*) continue ;;
        esac
        if [ -z "${!key:-}" ]; then
            export "$key=$value"
        fi
    done < "$file"
}

env_file=".env"
prev=""
for arg in "$@"; do
    if [ "$prev" = "--env-file" ]; then
        env_file="$arg"
    fi
    case "$arg" in
        --env-file=*) env_file="${arg#--env-file=}" ;;
    esac
    prev="$arg"
done
case "$env_file" in
    /*) ;;
    *) env_file="${root}/${env_file}" ;;
esac
load_synaplan_ports "$env_file"
"${root}/scripts/check-project-env.sh" "$env_file" "$@"
frontend_port="${SYNAPLAN_FRONTEND_PORT:-5173}"

echo "Starting the status page (http://localhost:${frontend_port})…"
docker compose "$@" up -d frontend

echo "Open http://localhost:${frontend_port} — other images may still be downloading."
echo "Starting the rest of the stack…"
docker compose "$@" up -d
