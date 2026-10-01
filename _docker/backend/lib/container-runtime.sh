#!/bin/bash
# Shared runtime helpers for Synaplan's web, worker, and scheduler roles.
#
# This file is sourced by docker-entrypoint.sh and intentionally has no
# top-level side effects so its control flow can be characterized in shell tests.

SYNAPLAN_RUNTIME_DIR="${SYNAPLAN_RUNTIME_DIR:-/var/www/backend/var/run/synaplan}"
SYNAPLAN_WORKER_TRANSPORTS="${SYNAPLAN_WORKER_TRANSPORTS:-async_ai_high async_extract async_index}"
# Composer autoloader used by the first-admin configuration check. Relative to
# the application directory the entrypoint already runs in, like `bin/console`.
SYNAPLAN_AUTOLOAD_PATH="${SYNAPLAN_AUTOLOAD_PATH:-vendor/autoload.php}"

runtime_log() {
    printf '[%s] %s\n' "${SYNAPLAN_ROLE:-web}" "$*"
}

runtime_fatal() {
    runtime_log "FATAL: $*" >&2
    return 1
}

# An operator-relevant warning, marked like every other one in the entrypoint.
# Without the marker a line such as "your credentials were never validated"
# reads like ordinary status output and is lost in the startup log.
runtime_warn() {
    runtime_log "⚠️  $*" >&2
}

# The `|| return 64` is not decoration: the entrypoint runs under `set -e`, where
# runtime_fatal's own non-zero status would end the shell before this function
# reaches its return statement — with exit code 1 instead of the documented 64.
require_console() {
    if [ ! -f bin/console ]; then
        runtime_fatal "bin/console is missing under $(pwd); the application code is not mounted or copied correctly." || return 64
    fi
}

# BOOTSTRAP_ADMIN_EMAIL as the PHP authority sees it.
#
# BootstrapAdminConfiguration trims the email (and only the email) before it
# decides whether the bootstrap is configured at all. Both tiers below normalize
# the same way, otherwise a whitespace-only address would mean "not configured"
# in PHP and "configured" in shell: tier 1 would abort a boot PHP accepts, and
# tier 2 would report an accepted configuration for a bootstrap that never runs.
#
# This is normalization, not a rule: it decides nothing about the value.
bootstrap_admin_trimmed_email() {
    local email="${BOOTSTRAP_ADMIN_EMAIL:-}"

    email="${email#"${email%%[![:space:]]*}"}"
    email="${email%"${email##*[![:space:]]}"}"

    printf '%s' "$email"
}

# Tier 1 of the first-admin bootstrap guard: the pairing rule only.
#
# This is the earliest possible check. It needs nothing but the environment — no
# vendor/, no PHP, no application code — so it can run before the
# /docker-entrypoint.d scripts that populate vendor/ in the dev stack, and it
# catches the most common operator mistake within milliseconds of container
# start. It is deliberately limited to the one rule that cannot drift: whether
# both values are present.
#
# Everything else (email format and length, password length and composition) is
# decided by tier 2, require_valid_bootstrap_admin_config, which calls the
# authoritative PHP validator instead of reimplementing it here. Keep the two
# tiers in that order: tier 1 is free, tier 2 costs one PHP process.
#
# Both variables set is valid, neither set is valid (the bootstrap is then
# skipped); exactly one of them is an operator configuration error.
require_bootstrap_admin_pair() {
    local email password
    email="$(bootstrap_admin_trimmed_email)"
    password="${BOOTSTRAP_ADMIN_PASSWORD:-}"

    if [ -z "$email" ] && [ -z "$password" ]; then
        return 0
    fi
    if [ -n "$email" ] && [ -n "$password" ]; then
        return 0
    fi

    local present="BOOTSTRAP_ADMIN_EMAIL"
    local missing="BOOTSTRAP_ADMIN_PASSWORD"
    if [ -z "$email" ]; then
        present="BOOTSTRAP_ADMIN_PASSWORD"
        missing="BOOTSTRAP_ADMIN_EMAIL"
    fi

    echo "❌ ERROR: Incomplete first-admin bootstrap configuration!" >&2
    echo "   ${present} is set, but ${missing} is empty." >&2
    echo "   BOOTSTRAP_ADMIN_EMAIL and BOOTSTRAP_ADMIN_PASSWORD must either both be set or both be empty." >&2
    echo "   Set ${missing} to bootstrap the first administrator, or unset ${present} to skip the bootstrap." >&2
    return 78
}

# Tier 2 of the first-admin bootstrap guard: the COMPLETE rule set.
#
# Tier 1 above can only decide the pairing rule. Everything else — email format,
# email length, password length, password composition — lives in
# App\Service\Admin\BootstrapAdminConfiguration, and this guard calls THAT class
# instead of mirroring it in shell. A shell mirror is free to drift, and an early
# check that is looser than the authority is worse than no early check at all:
# it accepts a value the bootstrap later rejects and so reintroduces exactly the
# crash loop this guard exists to prevent.
#
# No Symfony kernel is booted. The validator has no dependencies, so a single
# plain PHP process with the Composer autoloader decides the same rules in a few
# tens of milliseconds, and it cannot fail for reasons unrelated to the
# credentials (a missing service, an unreachable database, a cold cache).
#
# Requirements on the call site, all satisfied in docker-entrypoint.sh:
#   - vendor/ must exist, so this must run after the /docker-entrypoint.d block
#     (the dev stack installs the Composer dependencies there);
#   - it must precede the database wait, the migrations and the seeders;
#   - it applies to web, worker and scheduler alike, so it runs before the role
#     dispatch.
#
# The values are read from the environment inside PHP and never passed as
# arguments, so the password never reaches the process list, and only the
# validator's own message is printed — never a configured value.
require_valid_bootstrap_admin_config() {
    # An unconfigured bootstrap is a valid, supported choice, so stay completely
    # inert: no PHP process is started at all when neither value is present. The
    # email is normalized exactly like the authority normalizes it, so a
    # whitespace-only address without a password is "not configured" here too,
    # instead of spawning PHP and logging an accepted configuration for a
    # bootstrap that will never run.
    local email
    email="$(bootstrap_admin_trimmed_email)"
    if [ -z "$email" ] && [ -z "${BOOTSTRAP_ADMIN_PASSWORD:-}" ]; then
        return 0
    fi

    # Without the autoloader the authority is unreachable. Warn and continue
    # instead of failing: this guard may never be the reason a container stops.
    # The bootstrap command still validates the same values later.
    if [ ! -r "$SYNAPLAN_AUTOLOAD_PATH" ]; then
        runtime_warn "Skipping the early first-admin configuration check: ${SYNAPLAN_AUTOLOAD_PATH} is not readable from $(pwd)."
        return 0
    fi

    local program
    program="$(
        cat <<'PHP'
require getenv('SYNAPLAN_AUTOLOAD_PATH');

try {
    App\Service\Admin\BootstrapAdminConfiguration::fromConfiguration(
        (string) getenv('BOOTSTRAP_ADMIN_EMAIL'),
        (string) getenv('BOOTSTRAP_ADMIN_PASSWORD'),
    );
} catch (InvalidArgumentException $violation) {
    fwrite(STDERR, $violation->getMessage());
    exit(1);
} catch (Throwable $unexpected) {
    // Exit 2 is treated leniently below, and only "the authority could not run"
    // deserves that. The class name is part of the message so the log also
    // distinguishes the other possibility: a rule violation thrown as something
    // other than InvalidArgumentException, which the branch above would miss.
    // BootstrapAdminConfigurationTest locks that contract on the PHP side.
    fwrite(STDERR, sprintf('%s: %s', get_class($unexpected), $unexpected->getMessage()));
    exit(2);
}
PHP
    )"

    local message verdict=0
    message="$(SYNAPLAN_AUTOLOAD_PATH="$SYNAPLAN_AUTOLOAD_PATH" php -r "$program" 2>&1)" || verdict=$?

    if [ "$verdict" -eq 0 ]; then
        runtime_log "First-admin bootstrap configuration accepted."
        return 0
    fi

    # 1, not 78: exit 78 stays reserved for the half-configured pair that tier 1
    # rejects, so the two failures remain distinguishable from the exit code
    # alone. 1 is also what the bootstrap command itself returns for a rejected
    # value, so the documented contract is unchanged — only the timing improves.
    if [ "$verdict" -eq 1 ]; then
        echo "❌ ERROR: Invalid first-admin bootstrap configuration!" >&2
        printf '   %s\n' "$message" >&2
        echo "   Fix the value in your deployment configuration, then start the container again." >&2
        echo "   Nothing was written to the database: this check runs before the database wait, the migrations and the seeders." >&2
        return 1
    fi

    # Any other status means the check itself could not run (for example an
    # incomplete autoloader). Never block the boot on that; the bootstrap
    # command validates the same values with the same class later on. It is a
    # warning, not status output: the configured credentials are unvalidated at
    # this point, and the message below names what the authority reported.
    runtime_warn "Could not run the early first-admin configuration check; continuing startup."
    if [ -n "$message" ]; then
        printf '   %s\n' "$message" >&2
    fi
    return 0
}

# Wait until the application database accepts a trivial query.
#
# Args:
#   $1 maximum attempts (0 means unlimited)
#   $2 delay in seconds
wait_for_database() {
    local max_attempts="${1:-0}"
    local delay="${2:-3}"
    local attempt=0
    local env="${APP_ENV:-prod}"

    runtime_log "Waiting for database connection..."
    while ! php bin/console --env="$env" dbal:run-sql 'SELECT 1' >/dev/null 2>&1; do
        attempt=$((attempt + 1))
        if [ "$max_attempts" -gt 0 ] && [ "$attempt" -ge "$max_attempts" ]; then
            runtime_log "Database did not become ready after $((max_attempts * delay)) seconds." >&2
            php bin/console --env="$env" dbal:run-sql 'SELECT 1' >&2 || true
            return 66
        fi
        if [ $((attempt % 10)) -eq 1 ]; then
            if [ "$max_attempts" -gt 0 ]; then
                runtime_log "Database is not ready (attempt ${attempt}/${max_attempts})."
            else
                # The web role waits without a limit, so there is no denominator
                # to show. "attempt 1/0" read like a broken counter.
                runtime_log "Database is not ready (attempt ${attempt}, waiting indefinitely)."
            fi
        fi
        sleep "$delay"
    done
    runtime_log "Database is ready."
}

# Worker and scheduler containers must not race the web container's migrations.
# Doctrine's command returns non-zero while migrations remain pending.
wait_for_web_initialization() {
    local max_attempts="${SYNAPLAN_INIT_WAIT_ATTEMPTS:-120}"
    local delay="${SYNAPLAN_INIT_WAIT_SECONDS:-3}"
    local attempt=0
    local env="${APP_ENV:-prod}"

    wait_for_database "$max_attempts" "$delay" || return

    runtime_log "Waiting for web database initialization..."
    while ! php bin/console --env="$env" doctrine:migrations:up-to-date --no-interaction >/dev/null 2>&1; do
        attempt=$((attempt + 1))
        if [ "$max_attempts" -gt 0 ] && [ "$attempt" -ge "$max_attempts" ]; then
            runtime_log "Web initialization did not complete after $((max_attempts * delay)) seconds." >&2
            runtime_log "The database is reachable, but Doctrine still reports pending migrations, so the web role never finished applying them." >&2
            runtime_log "This role refuses to start against a half-migrated schema. Check the web container's log (for example 'docker compose logs backend') for the error that stopped its startup sequence." >&2
            runtime_log "Output of the pending-migrations check that kept failing:" >&2
            php bin/console --env="$env" doctrine:migrations:up-to-date --no-interaction >&2 || true
            return 67
        fi
        if [ $((attempt % 10)) -eq 1 ]; then
            runtime_log "Migrations are still pending (attempt ${attempt}/${max_attempts})."
        fi
        sleep "$delay"
    done
    runtime_log "Web database initialization is complete."

    wait_for_web_health "$max_attempts" "$delay"
}

wait_for_web_health() {
    local max_attempts="${1:-120}"
    local delay="${2:-3}"
    local attempt=0
    local health_url="${SYNAPLAN_WEB_HEALTH_URL:-http://backend/api/health}"

    runtime_log "Waiting for web health endpoint (${health_url})..."
    while ! curl --fail --silent --max-time 5 "$health_url" >/dev/null 2>&1; do
        attempt=$((attempt + 1))
        if [ "$max_attempts" -gt 0 ] && [ "$attempt" -ge "$max_attempts" ]; then
            runtime_log "Web health endpoint did not become ready after $((max_attempts * delay)) seconds." >&2
            runtime_log "Migrations are applied, so the web container is started but not serving ${health_url}. Check the web container's log (for example 'docker compose logs backend') and whether that URL is reachable from this container." >&2
            return 68
        fi
        if [ $((attempt % 10)) -eq 1 ]; then
            runtime_log "Web endpoint is not healthy (attempt ${attempt}/${max_attempts})."
        fi
        sleep "$delay"
    done
    runtime_log "Web endpoint is healthy."
}

prepare_role_cache() {
    local env="${APP_ENV:-prod}"

    runtime_log "Clearing and warming ${env} cache..."
    rm -rf "var/cache/${env}"
    php bin/console --env="$env" cache:warmup
}

run_worker_role() {
    local env="${APP_ENV:-prod}"

    require_console || return
    prepare_role_cache || return
    wait_for_web_initialization || return

    mkdir -p "$SYNAPLAN_RUNTIME_DIR"
    date +%s > "${SYNAPLAN_RUNTIME_DIR}/worker.started"
    runtime_log "Starting Messenger consumer (env=${env})."

    # Word splitting is intentional: transports are a space-separated list of
    # trusted Symfony transport names, with the current three as the default.
    # shellcheck disable=SC2086
    exec php bin/console --env="$env" messenger:consume \
        $SYNAPLAN_WORKER_TRANSPORTS \
        --time-limit="${SYNAPLAN_WORKER_TIME_LIMIT:-3600}" \
        --memory-limit="${SYNAPLAN_WORKER_MEMORY_LIMIT:-512M}" -v
}

_scheduler_stopping=0
_scheduler_sleep_pid=''
_scheduler_child_pid=''
_scheduler_tick_pid=''
_scheduler_tasks_pid=''
_scheduler_hourly_pid=''
_scheduler_daily_pid=''
_scheduler_health_pid=''
_scheduler_next_hourly=0
_scheduler_next_daily=0
_scheduler_next_health=0

stop_scheduler() {
    _scheduler_stopping=1
    if [ -n "$_scheduler_sleep_pid" ]; then
        kill "$_scheduler_sleep_pid" 2>/dev/null || true
    fi
    scheduler_signal_lanes TERM
    return 0
}

# The lane shell forwards TERM to the job it is waiting on, then leaves.
# $_scheduler_child_pid is the timeout (or php) process of that lane only.
scheduler_lane_on_term() {
    if [ -n "${_scheduler_child_pid}" ]; then
        kill -TERM "$_scheduler_child_pid" 2>/dev/null || true
    fi
    exit 143
}

# kill -0 is true for a zombie. A finished lane stays in the process table
# until it is reaped, and must not count as still running.
scheduler_pid_alive() {
    local pid="$1"
    local stat_line state

    if [ -z "$pid" ]; then
        return 1
    fi
    if [ ! -r "/proc/${pid}/stat" ]; then
        return 1
    fi
    stat_line="$(cat "/proc/${pid}/stat" 2>/dev/null)" || return 1
    state="${stat_line##*) }"
    state="${state%% *}"
    if [ -z "$state" ] || [ "$state" = "Z" ]; then
        return 1
    fi
    return 0
}

scheduler_reap_lane() {
    local slot="$1"
    local pid=''

    case "$slot" in
        tick) pid="$_scheduler_tick_pid" ;;
        tasks) pid="$_scheduler_tasks_pid" ;;
        hourly) pid="$_scheduler_hourly_pid" ;;
        daily) pid="$_scheduler_daily_pid" ;;
        health) pid="$_scheduler_health_pid" ;;
        *) return 0 ;;
    esac
    if [ -z "$pid" ]; then
        return 0
    fi
    if scheduler_pid_alive "$pid"; then
        return 0
    fi
    wait "$pid" 2>/dev/null || true
    case "$slot" in
        tick) _scheduler_tick_pid='' ;;
        tasks) _scheduler_tasks_pid='' ;;
        hourly) _scheduler_hourly_pid='' ;;
        daily) _scheduler_daily_pid='' ;;
        health) _scheduler_health_pid='' ;;
    esac
    return 0
}

scheduler_lane_alive() {
    local pid=''

    case "$1" in
        tick) pid="$_scheduler_tick_pid" ;;
        tasks) pid="$_scheduler_tasks_pid" ;;
        hourly) pid="$_scheduler_hourly_pid" ;;
        daily) pid="$_scheduler_daily_pid" ;;
        health) pid="$_scheduler_health_pid" ;;
        *) return 1 ;;
    esac
    scheduler_pid_alive "$pid"
}

scheduler_any_lane_alive() {
    local slot

    for slot in tick tasks hourly daily health; do
        if scheduler_lane_alive "$slot"; then
            return 0
        fi
    done
    return 1
}

scheduler_signal_lanes() {
    local signal="$1"
    local slot pid=''

    for slot in tick tasks hourly daily health; do
        pid=''
        case "$slot" in
            tick) pid="$_scheduler_tick_pid" ;;
            tasks) pid="$_scheduler_tasks_pid" ;;
            hourly) pid="$_scheduler_hourly_pid" ;;
            daily) pid="$_scheduler_daily_pid" ;;
            health) pid="$_scheduler_health_pid" ;;
        esac
        if scheduler_pid_alive "$pid"; then
            # The lane is a session leader, so the group includes timeout's child.
            kill "-${signal}" -- "-${pid}" 2>/dev/null || kill "-${signal}" "$pid" 2>/dev/null || true
        fi
    done
    return 0
}

scheduler_wait_lanes() {
    local slot pid=''

    for slot in tick tasks hourly daily health; do
        pid=''
        case "$slot" in
            tick) pid="$_scheduler_tick_pid" ;;
            tasks) pid="$_scheduler_tasks_pid" ;;
            hourly) pid="$_scheduler_hourly_pid" ;;
            daily) pid="$_scheduler_daily_pid" ;;
            health) pid="$_scheduler_health_pid" ;;
        esac
        if [ -n "$pid" ]; then
            wait "$pid" 2>/dev/null || true
        fi
    done
    _scheduler_tick_pid=''
    _scheduler_tasks_pid=''
    _scheduler_hourly_pid=''
    _scheduler_daily_pid=''
    _scheduler_health_pid=''
    return 0
}

scheduler_wait_lanes_bounded() {
    local seconds="$1"
    local deadline

    deadline="$(( $(date +%s) + seconds ))"
    while scheduler_any_lane_alive; do
        if [ "$(date +%s)" -ge "$deadline" ]; then
            return 1
        fi
        sleep 0.2
    done
    scheduler_wait_lanes
    return 0
}

scheduler_spawn_lane() {
    local slot="$1"
    local fn="$2"
    shift 2
    local runtime_lib pid

    runtime_lib="${BASH_SOURCE[0]}"
    # setsid makes the lane its own process group, so the stop path's KILL
    # reaches the command `timeout` is waiting on and not only this shell.
    # shellcheck disable=SC2016
    setsid -- "$BASH" -c '
        set -euo pipefail
        # exec starts a new shell, which does not inherit the entrypoint options.
        # shellcheck disable=SC1090
        . "$1"
        shift
        lane_fn="$1"
        shift
        "$lane_fn" "$@"
    ' _ "$runtime_lib" "$fn" "$@" &
    pid=$!
    case "$slot" in
        tick) _scheduler_tick_pid=$pid ;;
        tasks) _scheduler_tasks_pid=$pid ;;
        hourly) _scheduler_hourly_pid=$pid ;;
        daily) _scheduler_daily_pid=$pid ;;
        health) _scheduler_health_pid=$pid ;;
    esac
    return 0
}

scheduler_ensure_lane() {
    local slot="$1"
    shift

    scheduler_reap_lane "$slot"
    if scheduler_lane_alive "$slot"; then
        return 0
    fi
    scheduler_spawn_lane "$slot" "$@"
}

# $1 is the cap in seconds. 0 runs php directly: a timeout would kill the process.
run_scheduler_job() {
    local cap="$1"
    shift
    local status=0
    local command_name=''
    local arg

    for arg in "$@"; do
        case "$arg" in
            app:*)
                command_name="$arg"
                ;;
        esac
    done

    if [ "$cap" -gt 0 ]; then
        timeout --signal=TERM --kill-after=30 "$cap" php "$@" &
    else
        php "$@" &
    fi
    _scheduler_child_pid=$!
    wait "$_scheduler_child_pid" 2>/dev/null || status=$?
    _scheduler_child_pid=''

    if [ "$status" -eq 0 ]; then
        return 0
    fi
    # wait returns 128+signal when this lane is stopped. That is not a job failure.
    if [ "$status" -eq 143 ] || [ "$status" -eq 130 ]; then
        exit "$status"
    fi
    if [ "$status" -eq 124 ]; then
        runtime_log "${command_name} was stopped after ${cap} seconds." >&2
        return 0
    fi
    runtime_log "${command_name} failed (exit ${status}); it will be retried on the next run." >&2
    return 0
}

scheduler_trim() {
    local value="$1"

    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    printf '%s\n' "$value"
}

scheduler_is_positive_integer() {
    case "$1" in
        ''|*[!0-9]*|0) return 1 ;;
        *) return 0 ;;
    esac
}

scheduler_slot_next() {
    case "$1" in
        hourly) printf '%s\n' "$_scheduler_next_hourly" ;;
        daily) printf '%s\n' "$_scheduler_next_daily" ;;
        health) printf '%s\n' "$_scheduler_next_health" ;;
        *) printf '%s\n' 0 ;;
    esac
}

scheduler_set_slot_next() {
    case "$1" in
        hourly) _scheduler_next_hourly="$2" ;;
        daily) _scheduler_next_daily="$2" ;;
        health) _scheduler_next_health="$2" ;;
    esac
}

# Ask the shared slot store. Exit 0 starts the lane, exit 3 defers it, and any
# other status skips the slot: several nodes run this loop, so falling back to
# "run it anyway" would double-run the jobs.
scheduler_claim_slot() {
    local slot="$1"
    local mode="$2"
    local value="$3"
    local now="$4"
    local env="$5"
    local extra="$6"
    local next
    local claim_output=''
    local claim_status=0
    local until_due

    next="$(scheduler_slot_next "$slot")"
    scheduler_reap_lane "$slot"
    if [ "$now" -lt "$next" ]; then
        return 0
    fi
    if scheduler_lane_alive "$slot"; then
        return 0
    fi

    claim_output="$(php bin/console --env="$env" app:scheduler:claim "$slot" "--${mode}=${value}" --no-interaction)" || claim_status=$?

    if [ "$claim_status" -eq 0 ]; then
        case "$slot" in
            hourly) scheduler_ensure_lane hourly run_scheduler_hourly_lane "$env" ;;
            daily) scheduler_ensure_lane daily run_scheduler_daily_lane "$env" "$extra" ;;
            health) scheduler_ensure_lane health run_scheduler_health_lane "$env" "$extra" ;;
        esac
        if [ "$slot" = "daily" ]; then
            scheduler_set_slot_next daily "$((now + 3600))"
        else
            scheduler_set_slot_next "$slot" "$((now + value))"
        fi
        return 0
    fi

    if [ "$claim_status" -eq 3 ]; then
        until_due="$(scheduler_trim "$claim_output")"
        if scheduler_is_positive_integer "$until_due"; then
            if [ "$until_due" -gt 3600 ]; then
                until_due=3600
            fi
            scheduler_set_slot_next "$slot" "$((now + until_due))"
        fi
        return 0
    fi

    runtime_log "Could not check whether the ${slot} jobs are due (exit ${claim_status}); asking again on the next tick." >&2
    return 0
}

# Invoked by name in the lane shell (scheduler_spawn_lane); shellcheck cannot see that call.
# shellcheck disable=SC2317
run_scheduler_tick_lane() {
    local env="$1"
    local smart_mailbox="$2"

    trap scheduler_lane_on_term TERM

    run_scheduler_job 300 bin/console --env="$env" app:media:reap-jobs --no-interaction
    run_scheduler_job 300 bin/console --env="$env" app:chat:reap-stuck --no-interaction
    run_scheduler_job 300 bin/console --env="$env" app:desktop:reap-jobs --no-interaction
    run_scheduler_job 300 bin/console --env="$env" app:process-mail-handlers --no-interaction
    if [ "$smart_mailbox" = "1" ]; then
        run_scheduler_job 300 bin/console --env="$env" app:process-emails --no-interaction
    fi
}

# shellcheck disable=SC2317
run_scheduler_tasks_lane() {
    local env="$1"

    trap scheduler_lane_on_term TERM

    # No cap: the command runs due AI tasks inline, and killing it would strand a run.
    run_scheduler_job 0 bin/console --env="$env" app:saved-tasks:tick --no-interaction
}

# shellcheck disable=SC2317
run_scheduler_hourly_lane() {
    local env="$1"

    trap scheduler_lane_on_term TERM

    run_scheduler_job 900 bin/console --env="$env" app:files:reap-ephemeral --no-interaction

    # Tool approvals nobody decided within their deadline (default 72 h)
    # flip to `expired` and fail the paused Saved Task run. Cheap: one
    # indexed query, a no-op while approvals are disabled.
    run_scheduler_job 900 bin/console --env="$env" app:approvals:expire --no-interaction

    # New-model detection. Opt-in via MODEL_DISCOVERY_ENABLED (command
    # is a no-op when false). Read-only: reports pending upstream ids
    # to Discord, never writes BMODELS. Hourly so a release is posted
    # within the hour; BCONFIG state keeps each id to one post.
    run_scheduler_job 900 bin/console --env="$env" app:models:discover --notify --no-interaction
}

# shellcheck disable=SC2317
run_scheduler_daily_lane() {
    local env="$1"
    local price_sync="$2"

    trap scheduler_lane_on_term TERM

    # Release-notice detection. Detection only: it stores the published
    # version in BCONFIG and never touches the installation, so a failure
    # (offline instance, unreachable manifest) is expected and is simply
    # retried on the next interval.
    run_scheduler_job 3600 bin/console --env="$env" app:updates:check --no-interaction

    # Discontinued-model detection. Read-only and reports only: it asks
    # the providers the operator already configured a key for which
    # models they still serve, and never changes a row. Installs without
    # cloud keys make no outbound request at all.
    run_scheduler_job 3600 bin/console --env="$env" app:models:check-availability --notify --no-interaction

    # Message digest: out-of-band deep-memory indexing of new user
    # messages (self-locking, per-user cost caps). A failure is
    # harmless — the per-user cursor means the next run resumes
    # exactly where this one stopped.
    run_scheduler_job 10800 bin/console --env="$env" app:digest:run --no-interaction

    # Official documentation corpus for the self-aware chat (owner 0 /
    # SYSTEM:synaplan). Failure is expected on air-gapped installs and
    # is retried on the next interval; the previous corpus stays.
    run_scheduler_job 3600 bin/console --env="$env" app:selfaware:sync-docs --no-interaction

    # One mail per user who chose "daily digest" for pending tool
    # approvals. Users on "instant" were mailed when the row was created.
    run_scheduler_job 3600 bin/console --env="$env" app:approvals:digest --no-interaction

    if [ "$price_sync" = "1" ]; then
        run_scheduler_job 3600 bin/console --env="$env" app:sync-model-prices --no-interaction
    fi
}

# shellcheck disable=SC2317
run_scheduler_health_lane() {
    local env="$1"
    local health_jitter="$2"

    trap scheduler_lane_on_term TERM

    # Runtime health of the models this install actually uses. Distinct from
    # the daily availability check above: that one reports catalog drift for
    # a human to act on, this one reacts within minutes to a provider that
    # started failing, which is why it runs on a much shorter interval.
    # Every provider is asked once through its free "list your models"
    # endpoint — no inference, so this costs nothing to run on a schedule.
    # The jitter keeps a fleet of installs from hitting the same provider
    # APIs on the same minute. A failure just means one provider was
    # unreachable and is retried on the next interval.
    run_scheduler_job 600 bin/console --env="$env" app:model:health-check --jitter="$health_jitter" --no-interaction
}

run_scheduler_role() {
    local env="${APP_ENV:-prod}"
    local tick_seconds="${SYNAPLAN_SCHEDULER_TICK_SECONDS:-60}"
    local hourly_seconds="${SYNAPLAN_SCHEDULER_HOURLY_SECONDS:-3600}"
    local daily_at="${SYNAPLAN_SCHEDULER_DAILY_AT:-03:30}"
    local health_seconds="${SYNAPLAN_SCHEDULER_MODEL_HEALTH_SECONDS:-900}"
    local health_jitter="${SYNAPLAN_SCHEDULER_MODEL_HEALTH_JITTER:-120}"
    local smart_mailbox="${SYNAPLAN_SCHEDULER_SMART_MAILBOX:-0}"
    local price_sync="${SYNAPLAN_SCHEDULER_PRICE_SYNC:-0}"
    local max_cycles="${SYNAPLAN_SCHEDULER_MAX_CYCLES:-0}"
    local now
    local cycles=0

    _scheduler_stopping=0
    _scheduler_sleep_pid=''
    _scheduler_child_pid=''
    _scheduler_tick_pid=''
    _scheduler_tasks_pid=''
    _scheduler_hourly_pid=''
    _scheduler_daily_pid=''
    _scheduler_health_pid=''
    _scheduler_next_hourly=0
    _scheduler_next_daily=0
    _scheduler_next_health=0

    require_console || return
    prepare_role_cache || return
    wait_for_web_initialization || return
    mkdir -p "$SYNAPLAN_RUNTIME_DIR"
    if [ -n "${SYNAPLAN_ROLE:-}" ]; then
        export SYNAPLAN_ROLE
    fi
    trap stop_scheduler TERM INT

    runtime_log "Starting scheduler (tick lane every ${tick_seconds}s, Saved Tasks lane every ${tick_seconds}s, hourly lane every ${hourly_seconds}s, daily lane at ${daily_at} UTC, model health lane every ${health_seconds}s)."
    while [ "$_scheduler_stopping" -eq 0 ]; do
        now="$(date +%s)"
        printf '%s\n' "$now" > "${SYNAPLAN_RUNTIME_DIR}/scheduler.heartbeat"

        scheduler_ensure_lane tick run_scheduler_tick_lane "$env" "$smart_mailbox"
        scheduler_ensure_lane tasks run_scheduler_tasks_lane "$env"
        scheduler_claim_slot hourly interval "$hourly_seconds" "$now" "$env" ""
        scheduler_claim_slot daily at "$daily_at" "$now" "$env" "$price_sync"
        scheduler_claim_slot health interval "$health_seconds" "$now" "$env" "$health_jitter"

        cycles=$((cycles + 1))
        if [ "$max_cycles" -gt 0 ] && [ "$cycles" -ge "$max_cycles" ]; then
            break
        fi
        if [ "$_scheduler_stopping" -ne 0 ]; then
            break
        fi

        sleep "$tick_seconds" &
        _scheduler_sleep_pid=$!
        wait "$_scheduler_sleep_pid" 2>/dev/null || true
        _scheduler_sleep_pid=''
    done

    if [ "$_scheduler_stopping" -ne 0 ]; then
        scheduler_signal_lanes TERM
        if ! scheduler_wait_lanes_bounded 60; then
            scheduler_signal_lanes KILL
            scheduler_wait_lanes_bounded 5 || true
        fi
    else
        scheduler_wait_lanes
    fi

    runtime_log "Scheduler stopped."
}
