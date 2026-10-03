#!/usr/bin/env bash
# Warn about project .env lines that silently reshape the Docker stack.
#
# Docker Compose reads the project .env (next to docker-compose.yml) for
# ${VAR} substitution only. Two kinds of lines end up there by accident,
# usually when a backend/.env or backend/.env.test is copied one level up:
#
#   - a variable the compose files substitute (OLLAMA_BASE_URL, LOCK_DSN, ...)
#     replaces the dev default for every service that uses it, with no
#     visible trace in `docker compose up`;
#   - a variable no compose file references (APP_SECRET, DATABASE_*_URL, ...)
#     does nothing at all, yet looks like it configures the app.
#
# SYNAPLAN_*_PORT and COMPOSE_PROFILES are the documented knobs (see
# .env.example) and are never reported. The check only warns; it never stops
# the start, and it prints variable names, never values.
#
# Usage:
#   scripts/check-project-env.sh <env-file> [global docker-compose flags...]
set -uo pipefail

env_file="${1:-}"
[ -n "$env_file" ] && [ -f "$env_file" ] || exit 0
shift

MAX_LISTED=8

file_keys=$(
    sed -nE 's/^[[:space:]]*(export[[:space:]]+)?([A-Za-z_][A-Za-z0-9_]*)=.*/\2/p' "$env_file" \
        | grep -vE '^(SYNAPLAN_[A-Z0-9_]*_PORT|COMPOSE_PROFILES)$' \
        | sort -u
)
[ -n "$file_keys" ] || exit 0

# Older Compose releases lack `config --variables`; skip quietly there.
compose_vars=$(docker compose "$@" config --variables 2>/dev/null | awk 'NR > 1 { print $1 }' | sort -u) || exit 0
[ -n "$compose_vars" ] || exit 0

overrides=$(comm -12 <(printf '%s\n' "$file_keys") <(printf '%s\n' "$compose_vars"))
ignored=$(comm -23 <(printf '%s\n' "$file_keys") <(printf '%s\n' "$compose_vars"))

join_names() {
    local names count shown
    names=$(printf '%s\n' "$1" | sed '/^$/d')
    count=$(printf '%s\n' "$names" | wc -l | tr -d ' ')
    shown=$(printf '%s\n' "$names" | head -n "$MAX_LISTED" | paste -sd ',' - | sed 's/,/, /g')
    if [ "$count" -gt "$MAX_LISTED" ]; then
        shown="${shown}, +$((count - MAX_LISTED)) more"
    fi
    printf '%s' "$shown"
}

count_names() {
    printf '%s\n' "$1" | sed '/^$/d' | wc -l | tr -d ' '
}

name="${env_file##*/}"

if [ -n "$overrides" ]; then
    echo "WARNING: ${name} replaces $(count_names "$overrides") Docker default(s) for every service that uses them:" >&2
    echo "    $(join_names "$overrides")" >&2
    echo "    Keep only the lines you set on purpose; app settings belong in backend/.env." >&2
fi

if [ -n "$ignored" ]; then
    echo "WARNING: ${name} has $(count_names "$ignored") setting(s) Docker Compose never reads:" >&2
    echo "    $(join_names "$ignored")" >&2
    echo "    This file is for SYNAPLAN_*_PORT and compose overrides (see .env.example)." >&2
    echo "    Move app settings to backend/.env, otherwise they have no effect." >&2
fi

exit 0
