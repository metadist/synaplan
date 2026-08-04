#!/bin/sh
set -eu

role="${SYNAPLAN_ROLE:-web}"
runtime_dir="${SYNAPLAN_RUNTIME_DIR:-/var/www/backend/var/run/synaplan}"

process_exists() {
    needle="$1"

    for cmdline in /proc/[0-9]*/cmdline; do
        [ -r "$cmdline" ] || continue
        if tr '\000' ' ' < "$cmdline" | grep -Fq "$needle"; then
            return 0
        fi
    done

    return 1
}

case "$role" in
    web)
        exec curl --fail --silent --show-error \
            --max-time "${SYNAPLAN_HEALTH_TIMEOUT_SECONDS:-5}" \
            "http://127.0.0.1/api/health"
        ;;
    worker)
        process_exists 'messenger:consume' || {
            echo "Messenger consumer process is not running." >&2
            exit 1
        }
        ;;
    scheduler)
        heartbeat="${runtime_dir}/scheduler.heartbeat"
        max_age="${SYNAPLAN_SCHEDULER_HEALTH_MAX_AGE_SECONDS:-180}"
        [ -r "$heartbeat" ] || {
            echo "Scheduler heartbeat is missing." >&2
            exit 1
        }

        now="$(date +%s)"
        last_alive="$(tr -cd '0-9' < "$heartbeat")"
        [ -n "$last_alive" ] && [ "$last_alive" -le "$now" ] || {
            echo "Scheduler heartbeat is invalid." >&2
            exit 1
        }
        [ $((now - last_alive)) -le "$max_age" ] || {
            echo "Scheduler heartbeat is stale." >&2
            exit 1
        }
        process_exists 'docker-entrypoint.sh' || {
            echo "Scheduler entrypoint process is not running." >&2
            exit 1
        }
        ;;
    *)
        echo "Unsupported SYNAPLAN_ROLE: ${role}" >&2
        exit 64
        ;;
esac
