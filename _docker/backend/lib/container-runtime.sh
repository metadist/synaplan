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

stop_scheduler() {
    _scheduler_stopping=1
    if [ -n "$_scheduler_child_pid" ]; then
        kill -TERM "$_scheduler_child_pid" 2>/dev/null || true
    fi
    if [ -n "$_scheduler_sleep_pid" ]; then
        kill "$_scheduler_sleep_pid" 2>/dev/null || true
    fi
}

run_scheduler_command() {
    php "$@" &
    _scheduler_child_pid=$!
    wait "$_scheduler_child_pid"
    local status=$?
    _scheduler_child_pid=''
    return "$status"
}

run_scheduler_role() {
    local env="${APP_ENV:-prod}"
    local tick_seconds="${SYNAPLAN_SCHEDULER_TICK_SECONDS:-60}"
    local hourly_seconds="${SYNAPLAN_SCHEDULER_HOURLY_SECONDS:-3600}"
    local daily_seconds="${SYNAPLAN_SCHEDULER_DAILY_SECONDS:-86400}"
    local health_seconds="${SYNAPLAN_SCHEDULER_MODEL_HEALTH_SECONDS:-900}"
    local health_jitter="${SYNAPLAN_SCHEDULER_MODEL_HEALTH_JITTER:-120}"
    local now
    local next_hourly=0
    local next_daily=0
    local next_health=0
    local cycles=0
    local max_cycles="${SYNAPLAN_SCHEDULER_MAX_CYCLES:-0}"

    require_console || return
    prepare_role_cache || return
    wait_for_web_initialization || return
    mkdir -p "$SYNAPLAN_RUNTIME_DIR"
    trap stop_scheduler TERM INT

    runtime_log "Starting scheduler (media reaper every ${tick_seconds}s, ephemeral-file reaper every ${hourly_seconds}s, model health check every ${health_seconds}s, update + model-availability check every ${daily_seconds}s)."
    while [ "$_scheduler_stopping" -eq 0 ]; do
        now="$(date +%s)"
        printf '%s\n' "$now" > "${SYNAPLAN_RUNTIME_DIR}/scheduler.heartbeat"

        if ! run_scheduler_command bin/console --env="$env" app:media:reap-jobs --no-interaction; then
            runtime_log "Media reaper failed; it will be retried on the next tick." >&2
        fi

        if ! run_scheduler_command bin/console --env="$env" app:saved-tasks:tick --no-interaction; then
            runtime_log "Saved Tasks tick failed; it will be retried on the next tick." >&2
        fi

        if [ "$now" -ge "$next_hourly" ]; then
            if ! run_scheduler_command bin/console --env="$env" app:files:reap-ephemeral --no-interaction; then
                runtime_log "Ephemeral-file reaper failed; it will be retried next hour." >&2
            fi
            next_hourly=$((now + hourly_seconds))
        fi

        # Release-notice detection. Detection only: it stores the published
        # version in BCONFIG and never touches the installation, so a failure
        # (offline instance, unreachable manifest) is expected and is simply
        # retried on the next interval.
        if [ "$now" -ge "$next_daily" ]; then
            if ! run_scheduler_command bin/console --env="$env" app:updates:check --no-interaction; then
                runtime_log "Update check failed; it will be retried on the next daily interval." >&2
            fi

            # Discontinued-model detection. Read-only and reports only: it asks
            # the providers the operator already configured a key for which
            # models they still serve, and never changes a row. Installs without
            # cloud keys make no outbound request at all.
            if ! run_scheduler_command bin/console --env="$env" app:models:check-availability --notify --no-interaction; then
                runtime_log "Model availability check failed; it will be retried on the next daily interval." >&2
            fi

            # Message digest: out-of-band deep-memory indexing of new user
            # messages (self-locking, per-user cost caps). A failure is
            # harmless — the per-user cursor means the next run resumes
            # exactly where this one stopped.
            if ! run_scheduler_command bin/console --env="$env" app:digest:run --no-interaction; then
                runtime_log "Message digest run failed; it will be retried on the next daily interval." >&2
            fi

            # Official documentation corpus for the self-aware chat (owner 0 /
            # SYSTEM:synaplan). Failure is expected on air-gapped installs and
            # is retried on the next interval; the previous corpus stays.
            if ! run_scheduler_command bin/console --env="$env" app:selfaware:sync-docs --no-interaction; then
                runtime_log "Platform docs sync failed; it will be retried on the next daily interval." >&2
            fi

            next_daily=$((now + daily_seconds))
        fi

        # Runtime health of the models this install actually uses. Distinct from
        # the daily availability check above: that one reports catalog drift for
        # a human to act on, this one reacts within minutes to a provider that
        # started failing, which is why it runs on a much shorter interval.
        # Every provider is asked once through its free "list your models"
        # endpoint — no inference, so this costs nothing to run on a schedule.
        # The jitter keeps a fleet of installs from hitting the same provider
        # APIs on the same minute. A failure just means one provider was
        # unreachable and is retried on the next interval.
        if [ "$now" -ge "$next_health" ]; then
            if ! run_scheduler_command bin/console --env="$env" app:model:health-check --jitter="$health_jitter" --no-interaction; then
                runtime_log "Model health check failed; it will be retried on the next interval." >&2
            fi
            next_health=$((now + health_seconds))
        fi

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

    runtime_log "Scheduler stopped."
}
