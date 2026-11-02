#!/usr/bin/env bash
# Start the boot-status page, then chat, then the rest.
#
# The page is published on SYNAPLAN_FRONTEND_PORT from the project .env
# (5173 when that variable is unset). `docker compose up` fetches every
# service image first, then starts containers. `depends_on` does not change
# that: MailHog, Qdrant, TTS and the other optional images download before
# the database container exists. This script pulls in three steps so those
# downloads do not hold chat up.
#
#   1. frontend only — the status page binds the port
#   2. chat — db, redis, centrifugo, backend, tika, frontend
#   3. every remaining service (memories, spoken answers, office files,
#      dev tools, and the worker, which waits for a healthy backend)
#
# Worker and scheduler stay in step 3 on purpose. They use the backend
# image, which step 2 already built, and they wait until the backend is
# healthy. Putting them in step 2 would hold step 3's downloads until that
# health check passed.
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

echo "Open http://localhost:${frontend_port} — the status page is up."

# Only services this compose file actually defines. minimal omits some of
# the standard file; an unknown name would fail the whole start.
if ! present="$(docker compose "$@" config --services)"; then
    echo "Could not read the service list. Starting the whole stack in one step."
    docker compose "$@" up -d
    exit 0
fi

chat_wave=(db redis centrifugo backend tika frontend)
selected=()
for name in "${chat_wave[@]}"; do
    if printf '%s\n' "$present" | grep -qx "$name"; then
        selected+=("$name")
    fi
done

if [ "${#selected[@]}" -eq 0 ]; then
    echo "This compose file has no chat services. Starting the whole stack."
    docker compose "$@" up -d
    exit 0
fi

echo "Starting chat (${selected[*]})."
echo "Memories, spoken answers, office files and the dev tools download after this."
docker compose "$@" up -d "${selected[@]}"

echo "Chat is starting at http://localhost:${frontend_port}"
echo "Downloading the rest of the stack…"
docker compose "$@" up -d
