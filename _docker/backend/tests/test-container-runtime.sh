#!/usr/bin/env bash
set -u

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
RUNTIME_LIB="${SCRIPT_DIR}/../lib/container-runtime.sh"
HEALTHCHECK="${SCRIPT_DIR}/../container-healthcheck.sh"

# shellcheck disable=SC1090
. "$RUNTIME_LIB"

PASS=0
FAIL=0

assert_eq() {
    local expected="$1"
    local actual="$2"
    local message="$3"

    if [ "$expected" = "$actual" ]; then
        PASS=$((PASS + 1))
        echo "PASS: ${message}"
    else
        FAIL=$((FAIL + 1))
        echo "FAIL: ${message} (expected=${expected}, actual=${actual})" >&2
    fi
}

assert_contains() {
    local needle="$1"
    local file="$2"
    local message="$3"

    if grep -Fq -- "$needle" "$file"; then
        PASS=$((PASS + 1))
        echo "PASS: ${message}"
    else
        FAIL=$((FAIL + 1))
        echo "FAIL: ${message} (missing '${needle}')" >&2
    fi
}

assert_not_contains() {
    local needle="$1"
    local file="$2"
    local message="$3"

    if grep -Fq -- "$needle" "$file"; then
        FAIL=$((FAIL + 1))
        echo "FAIL: ${message} (found '${needle}')" >&2
    else
        PASS=$((PASS + 1))
        echo "PASS: ${message}"
    fi
}

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
mkdir -p "$TMP_DIR/bin" "$TMP_DIR/app/bin" "$TMP_DIR/runtime"
touch "$TMP_DIR/app/bin/console"
COMMAND_LOG="$TMP_DIR/commands.log"

cat > "$TMP_DIR/bin/php" <<'EOF'
#!/bin/sh
printf '%s\n' "$*" >> "$COMMAND_LOG"
exit 0
EOF
cat > "$TMP_DIR/bin/curl" <<'EOF'
#!/bin/sh
exit 0
EOF
chmod +x "$TMP_DIR/bin/php" "$TMP_DIR/bin/curl"

echo "Case 1: worker waits for initialization and preserves Messenger defaults"
(
    cd "$TMP_DIR/app" || exit
    export PATH="$TMP_DIR/bin:$PATH"
    export COMMAND_LOG
    export SYNAPLAN_ROLE=worker
    export SYNAPLAN_RUNTIME_DIR="$TMP_DIR/runtime"
    # shellcheck disable=SC1090
    . "$RUNTIME_LIB"
    run_worker_role
)
assert_contains "doctrine:migrations:up-to-date --no-interaction" "$COMMAND_LOG" "worker waits until migrations are current"
assert_contains "messenger:consume async_ai_high async_extract async_index --time-limit=3600 --memory-limit=512M -v" "$COMMAND_LOG" "worker consumes all current transports with current limits"

# PATH stubs: `timeout` does not see a shell function named php, and the claim
# call may be either a direct php or a child of timeout. Both go through PATH.
cat > "$TMP_DIR/bin/php" <<'EOF'
#!/bin/sh
printf '%s\n' "$*" >> "$COMMAND_LOG"
case "$*" in
    *app:scheduler:claim*)
        if [ -n "${CLAIM_SLEEP:-}" ]; then
            sleep "$CLAIM_SLEEP"
        fi
        if [ -n "${CLAIM_OUT:-}" ]; then
            printf '%s\n' "$CLAIM_OUT"
        fi
        exit "${CLAIM_EXIT:-0}"
        ;;
    *app:saved-tasks:tick*)
        if [ -n "${SAVED_TASKS_SLEEP:-}" ]; then
            sleep "$SAVED_TASKS_SLEEP" &
            sleep_pid=$!
            trap 'kill "$sleep_pid" 2>/dev/null || true; exit 143' TERM INT
            wait "$sleep_pid" || exit $?
        fi
        exit 0
        ;;
    *app:media:reap-jobs*)
        if [ -n "${MEDIA_REAP_EXIT:-}" ]; then
            exit "$MEDIA_REAP_EXIT"
        fi
        ;;
    *app:chat:reap-stuck*)
        if [ -n "${CHAT_REAP_EXIT:-}" ]; then
            exit "$CHAT_REAP_EXIT"
        fi
        ;;
    *app:desktop:reap-jobs*)
        if [ -n "${DESKTOP_REAP_EXIT:-}" ]; then
            exit "$DESKTOP_REAP_EXIT"
        fi
        ;;
esac
exit 0
EOF
cat > "$TMP_DIR/bin/timeout" <<'EOF'
#!/bin/sh
printf 'TIMEOUT %s\n' "$*" >> "$COMMAND_LOG"
while [ $# -gt 0 ]; do
    case "$1" in
        --)
            shift
            break
            ;;
        --*)
            shift
            ;;
        -*)
            shift
            ;;
        *)
            shift
            break
            ;;
    esac
done
if [ $# -eq 0 ]; then
    exit 127
fi
exec "$@"
EOF
chmod +x "$TMP_DIR/bin/php" "$TMP_DIR/bin/timeout"

reset_scheduler_env() {
    unset CLAIM_EXIT CLAIM_OUT CLAIM_SLEEP SAVED_TASKS_SLEEP MEDIA_REAP_EXIT CHAT_REAP_EXIT DESKTOP_REAP_EXIT
    unset SYNAPLAN_SCHEDULER_SMART_MAILBOX SYNAPLAN_SCHEDULER_PRICE_SYNC
    unset SYNAPLAN_SCHEDULER_DAILY_AT SYNAPLAN_SCHEDULER_HOURLY_SECONDS
    unset SYNAPLAN_SCHEDULER_TICK_SECONDS SYNAPLAN_SCHEDULER_MAX_CYCLES
    unset SYNAPLAN_SCHEDULER_MODEL_HEALTH_SECONDS SYNAPLAN_SCHEDULER_MODEL_HEALTH_JITTER
    unset SYNAPLAN_SCHEDULER_DAILY_SECONDS
}

run_scheduler_for_test() {
    local log_file="$1"
    local status=0

    (
        set -euo pipefail
        cd "$TMP_DIR/app" || exit 1
        export PATH="$TMP_DIR/bin:$PATH"
        export COMMAND_LOG APP_ENV=prod
        export SYNAPLAN_ROLE=scheduler
        export SYNAPLAN_RUNTIME_DIR="$TMP_DIR/runtime"
        # shellcheck disable=SC1090
        . "$RUNTIME_LIB"
        prepare_role_cache() { :; }
        wait_for_web_initialization() { :; }
        run_scheduler_role
    ) >"$log_file" 2>&1 || status=$?
    if [ "$status" -ne 0 ]; then
        echo "scheduler run failed (exit ${status}); log follows:" >&2
        cat "$log_file" >&2
    fi
    return "$status"
}

count_in() {
    local needle="$1"
    local file="$2"
    local count=0

    count="$(grep -c -F -- "$needle" "$file" || true)"
    printf '%s\n' "$count"
}

# timeout logs the same command text before exec, so a raw count double-counts.
count_php_invocations() {
    local needle="$1"
    local count=0

    count="$(grep -F -- "$needle" "$COMMAND_LOG" | grep -cv '^TIMEOUT ' || true)"
    printf '%s\n' "$count"
}

echo "Case 2: every lane runs when claims succeed, and opt-in jobs stay off"
reset_scheduler_env
: > "$COMMAND_LOG"
rm -f "$TMP_DIR/runtime/scheduler.heartbeat"
export SYNAPLAN_SCHEDULER_MAX_CYCLES=1
# The removed daily-seconds knob must not bring the daily slot back.
export SYNAPLAN_SCHEDULER_DAILY_SECONDS=10
CASE2_LOG="$TMP_DIR/scheduler-case2.log"
CASE2_STATUS=0
run_scheduler_for_test "$CASE2_LOG" || CASE2_STATUS=$?
assert_eq 0 "$CASE2_STATUS" "scheduler completes one cycle under set -e"
assert_contains "app:media:reap-jobs --no-interaction" "$COMMAND_LOG" "scheduler runs media reaper"
assert_contains "app:chat:reap-stuck --no-interaction" "$COMMAND_LOG" "scheduler runs stuck-chat reaper"
assert_contains "app:desktop:reap-jobs --no-interaction" "$COMMAND_LOG" "scheduler runs desktop job reaper"
assert_contains "app:process-mail-handlers --no-interaction" "$COMMAND_LOG" "scheduler runs mail handlers"
assert_contains "app:saved-tasks:tick --no-interaction" "$COMMAND_LOG" "scheduler runs Saved Tasks tick"
assert_contains "app:files:reap-ephemeral --no-interaction" "$COMMAND_LOG" "scheduler runs ephemeral-file reaper"
assert_contains "app:approvals:expire --no-interaction" "$COMMAND_LOG" "scheduler runs approval expiry"
assert_contains "app:models:discover --notify --no-interaction" "$COMMAND_LOG" "scheduler runs the model discovery check"
assert_contains "app:updates:check --no-interaction" "$COMMAND_LOG" "scheduler runs the daily update check"
assert_contains "app:models:check-availability --notify --no-interaction" "$COMMAND_LOG" "scheduler runs the daily model availability check"
assert_contains "app:digest:run --no-interaction" "$COMMAND_LOG" "scheduler runs the message digest"
assert_contains "app:selfaware:sync-docs --no-interaction" "$COMMAND_LOG" "scheduler runs the daily platform docs sync"
assert_contains "app:approvals:digest --no-interaction" "$COMMAND_LOG" "scheduler runs the approval digest"
assert_contains "app:model:health-check --jitter=120" "$COMMAND_LOG" "scheduler runs the model health check with request jitter"
assert_not_contains "app:process-emails" "$COMMAND_LOG" "smart mailbox stays off unless SYNAPLAN_SCHEDULER_SMART_MAILBOX=1"
assert_not_contains "app:sync-model-prices" "$COMMAND_LOG" "price sync stays off unless SYNAPLAN_SCHEDULER_PRICE_SYNC=1"
assert_contains "app:scheduler:claim hourly --interval=3600 --no-interaction" "$COMMAND_LOG" "hourly claim uses the interval"
assert_contains "app:scheduler:claim daily --at=03:30 --no-interaction" "$COMMAND_LOG" "daily claim defaults to 03:30 UTC"
assert_contains "app:scheduler:claim health --interval=900 --no-interaction" "$COMMAND_LOG" "health claim uses its interval"
assert_contains "TIMEOUT --signal=TERM --kill-after=5 30 php bin/console --env=prod app:scheduler:claim daily --at=03:30 --no-interaction" "$COMMAND_LOG" "slot claims are capped at 30s"
assert_contains "TIMEOUT --signal=TERM --kill-after=30 300 php bin/console --env=prod app:media:reap-jobs --no-interaction" "$COMMAND_LOG" "tick jobs are capped at 300s"
assert_contains "TIMEOUT --signal=TERM --kill-after=30 300 php bin/console --env=prod app:chat:reap-stuck --no-interaction" "$COMMAND_LOG" "stuck-chat reaper is capped at 300s"
assert_contains "TIMEOUT --signal=TERM --kill-after=30 300 php bin/console --env=prod app:desktop:reap-jobs --no-interaction" "$COMMAND_LOG" "desktop reaper is capped at 300s"
assert_contains "TIMEOUT --signal=TERM --kill-after=30 300 php bin/console --env=prod app:process-mail-handlers --no-interaction" "$COMMAND_LOG" "mail handlers are capped at 300s"
assert_contains "TIMEOUT --signal=TERM --kill-after=30 900 php bin/console --env=prod app:files:reap-ephemeral --no-interaction" "$COMMAND_LOG" "hourly jobs are capped at 900s"
assert_contains "TIMEOUT --signal=TERM --kill-after=30 10800 php bin/console --env=prod app:digest:run --no-interaction" "$COMMAND_LOG" "message digest is capped at 10800s"
assert_contains "TIMEOUT --signal=TERM --kill-after=30 3600 php bin/console --env=prod app:updates:check --no-interaction" "$COMMAND_LOG" "other daily jobs are capped at 3600s"
assert_contains "TIMEOUT --signal=TERM --kill-after=30 600 php bin/console --env=prod app:model:health-check --jitter=120 --no-interaction" "$COMMAND_LOG" "model health check is capped at 600s"
assert_eq 0 "$(grep -c 'TIMEOUT .*app:saved-tasks:tick' "$COMMAND_LOG" || true)" "Saved Tasks tick is not wrapped in timeout"
if [ -s "$TMP_DIR/runtime/scheduler.heartbeat" ]; then
    assert_eq 1 1 "scheduler writes liveness heartbeat"
else
    assert_eq 1 0 "scheduler writes liveness heartbeat"
fi
unset SYNAPLAN_SCHEDULER_DAILY_SECONDS

echo "Case 2b: opt-in mail and price-sync jobs run when their flags are 1"
reset_scheduler_env
: > "$COMMAND_LOG"
export SYNAPLAN_SCHEDULER_MAX_CYCLES=1
export SYNAPLAN_SCHEDULER_SMART_MAILBOX=1
export SYNAPLAN_SCHEDULER_PRICE_SYNC=1
CASE2B_STATUS=0
run_scheduler_for_test "$TMP_DIR/scheduler-case2b.log" || CASE2B_STATUS=$?
assert_eq 0 "$CASE2B_STATUS" "scheduler completes a flagged cycle under set -e"
assert_contains "app:process-emails --no-interaction" "$COMMAND_LOG" "smart mailbox runs when the flag is 1"
assert_contains "app:sync-model-prices --no-interaction" "$COMMAND_LOG" "price sync runs when the flag is 1"
assert_contains "TIMEOUT --signal=TERM --kill-after=30 300 php bin/console --env=prod app:process-emails --no-interaction" "$COMMAND_LOG" "smart mailbox is capped at 300s"
assert_contains "TIMEOUT --signal=TERM --kill-after=30 3600 php bin/console --env=prod app:sync-model-prices --no-interaction" "$COMMAND_LOG" "price sync is capped at 3600s"

echo "Case 2c: a claim that is not due skips that slot and backs off"
reset_scheduler_env
: > "$COMMAND_LOG"
export SYNAPLAN_SCHEDULER_MAX_CYCLES=2
export SYNAPLAN_SCHEDULER_TICK_SECONDS=0
export CLAIM_EXIT=3
export CLAIM_OUT=3600
CASE2C_STATUS=0
run_scheduler_for_test "$TMP_DIR/scheduler-case2c.log" || CASE2C_STATUS=$?
assert_eq 0 "$CASE2C_STATUS" "a not-due claim does not abort the scheduler under set -e"
assert_contains "app:media:reap-jobs" "$COMMAND_LOG" "tick lane still runs when slots are not due"
assert_contains "app:saved-tasks:tick" "$COMMAND_LOG" "Saved Tasks lane still runs when slots are not due"
assert_not_contains "app:files:reap-ephemeral" "$COMMAND_LOG" "hourly jobs do not run when the claim is not due"
assert_not_contains "app:approvals:expire" "$COMMAND_LOG" "approval expiry does not run when the claim is not due"
assert_not_contains "app:models:discover" "$COMMAND_LOG" "model discovery does not run when the claim is not due"
assert_not_contains "app:updates:check" "$COMMAND_LOG" "daily jobs do not run when the claim is not due"
assert_not_contains "app:digest:run" "$COMMAND_LOG" "message digest does not run when the claim is not due"
assert_not_contains "app:model:health-check" "$COMMAND_LOG" "health check does not run when the claim is not due"
assert_eq 1 "$(count_php_invocations 'app:scheduler:claim hourly')" "hourly claim waits for the reported delay"
assert_eq 1 "$(count_php_invocations 'app:scheduler:claim daily')" "daily claim waits for the reported delay"
assert_eq 1 "$(count_php_invocations 'app:scheduler:claim health')" "health claim waits for the reported delay"

echo "Case 2d: a claim answer that is not a delay is asked again next tick"
reset_scheduler_env
: > "$COMMAND_LOG"
export SYNAPLAN_SCHEDULER_MAX_CYCLES=2
export SYNAPLAN_SCHEDULER_TICK_SECONDS=0
export CLAIM_EXIT=3
export CLAIM_OUT=soon
CASE2D_STATUS=0
run_scheduler_for_test "$TMP_DIR/scheduler-case2d.log" || CASE2D_STATUS=$?
assert_eq 0 "$CASE2D_STATUS" "a non-numeric not-due claim does not abort the scheduler"
assert_eq 2 "$(count_php_invocations 'app:scheduler:claim hourly')" "a non-numeric delay is asked again on the next tick"
assert_not_contains "app:files:reap-ephemeral" "$COMMAND_LOG" "a non-numeric not-due claim does not run hourly jobs"

echo "Case 2e: a failed claim skips the slot and is asked again next tick"
reset_scheduler_env
: > "$COMMAND_LOG"
export SYNAPLAN_SCHEDULER_MAX_CYCLES=2
export SYNAPLAN_SCHEDULER_TICK_SECONDS=0
export CLAIM_EXIT=1
CASE2E_LOG="$TMP_DIR/scheduler-case2e.log"
CASE2E_STATUS=0
run_scheduler_for_test "$CASE2E_LOG" || CASE2E_STATUS=$?
assert_eq 0 "$CASE2E_STATUS" "a failed claim does not abort the scheduler under set -e"
assert_not_contains "app:files:reap-ephemeral" "$COMMAND_LOG" "hourly jobs do not run when the claim fails"
assert_not_contains "app:updates:check" "$COMMAND_LOG" "daily jobs do not run when the claim fails"
assert_not_contains "app:model:health-check" "$COMMAND_LOG" "health check does not run when the claim fails"
assert_contains "app:media:reap-jobs" "$COMMAND_LOG" "tick lane still runs when a claim fails"
assert_contains "Could not check whether the hourly jobs are due (exit 1); asking again on the next tick." "$CASE2E_LOG" "a failed hourly claim is logged"
assert_contains "Could not check whether the daily jobs are due (exit 1); asking again on the next tick." "$CASE2E_LOG" "a failed daily claim is logged"
assert_contains "Could not check whether the health jobs are due (exit 1); asking again on the next tick." "$CASE2E_LOG" "a failed health claim is logged"
assert_eq 2 "$(count_in 'Could not check whether the hourly jobs are due (exit 1)' "$CASE2E_LOG")" "a failed claim is asked again on the next tick"

echo "Case 2f: daily and hourly claim arguments follow their env knobs"
reset_scheduler_env
: > "$COMMAND_LOG"
export SYNAPLAN_SCHEDULER_MAX_CYCLES=1
export SYNAPLAN_SCHEDULER_DAILY_AT=04:15
export SYNAPLAN_SCHEDULER_HOURLY_SECONDS=120
CASE2F_STATUS=0
run_scheduler_for_test "$TMP_DIR/scheduler-case2f.log" || CASE2F_STATUS=$?
assert_eq 0 "$CASE2F_STATUS" "custom slot knobs do not abort the scheduler"
assert_contains "app:scheduler:claim daily --at=04:15 --no-interaction" "$COMMAND_LOG" "daily claim uses SYNAPLAN_SCHEDULER_DAILY_AT"
assert_contains "app:scheduler:claim hourly --interval=120 --no-interaction" "$COMMAND_LOG" "hourly claim uses SYNAPLAN_SCHEDULER_HOURLY_SECONDS"
assert_not_contains "app:scheduler:claim daily --at=03:30" "$COMMAND_LOG" "the default daily time is replaced by the env knob"

echo "Case 2g: a live lane is not started again, and a slow lane does not block the tick"
reset_scheduler_env
: > "$COMMAND_LOG"
export SYNAPLAN_SCHEDULER_MAX_CYCLES=3
export SYNAPLAN_SCHEDULER_TICK_SECONDS=0
export SAVED_TASKS_SLEEP=2
# The real claim boots PHP and yields the CPU. The stub returns instantly, so
# without this pause a tick-0 loop can finish before the first tick lane does.
export CLAIM_SLEEP=0.2
export CLAIM_EXIT=0
CASE2G_STATUS=0
CASE2G_START=$SECONDS
run_scheduler_for_test "$TMP_DIR/scheduler-case2g.log" || CASE2G_STATUS=$?
CASE2G_ELAPSED=$((SECONDS - CASE2G_START))
assert_eq 0 "$CASE2G_STATUS" "overlapping lanes still finish under set -e"
assert_eq 1 "$(count_php_invocations 'app:saved-tasks:tick')" "a live Saved Tasks lane is not started again"
MEDIA_RUNS="$(count_php_invocations 'app:media:reap-jobs')"
if [ "$MEDIA_RUNS" -ge 2 ]; then
    assert_eq 1 1 "tick lane runs again while Saved Tasks is still in its first run"
else
    assert_eq 2 "$MEDIA_RUNS" "tick lane runs again while Saved Tasks is still in its first run"
fi
if [ "$CASE2G_ELAPSED" -lt 8 ]; then
    assert_eq 1 1 "three cycles do not wait out the Saved Tasks sleep each time"
else
    assert_eq 7 "$CASE2G_ELAPSED" "three cycles do not wait out the Saved Tasks sleep each time"
fi

echo "Case 2h: a job failure names the command and a timeout names the cap"
reset_scheduler_env
: > "$COMMAND_LOG"
export SYNAPLAN_SCHEDULER_MAX_CYCLES=1
export MEDIA_REAP_EXIT=1
export CHAT_REAP_EXIT=124
export DESKTOP_REAP_EXIT=137
CASE2H_LOG="$TMP_DIR/scheduler-case2h.log"
CASE2H_STATUS=0
run_scheduler_for_test "$CASE2H_LOG" || CASE2H_STATUS=$?
assert_eq 0 "$CASE2H_STATUS" "a failing job does not abort the scheduler under set -e"
assert_contains "app:media:reap-jobs failed (exit 1); it will be retried on the next run." "$CASE2H_LOG" "a failed job is logged with its exit code"
assert_contains "app:chat:reap-stuck was stopped after 300 seconds." "$CASE2H_LOG" "exit 124 is logged as stopped after the cap"
assert_contains "app:desktop:reap-jobs was killed (exit 137): it ignored the stop signal after its 300-second limit, or it ran out of memory." "$CASE2H_LOG" "exit 137 is logged as a forced kill, not a plain failure"
assert_contains "app:process-mail-handlers" "$COMMAND_LOG" "later tick jobs still run after a failure"

echo "Case 2i: TERM stops a running lane"
reset_scheduler_env
: > "$COMMAND_LOG"
export SYNAPLAN_SCHEDULER_MAX_CYCLES=0
export SYNAPLAN_SCHEDULER_TICK_SECONDS=30
export SAVED_TASKS_SLEEP=30
export CLAIM_EXIT=0
CASE2I_LOG="$TMP_DIR/scheduler-case2i.log"
(
    set -euo pipefail
    cd "$TMP_DIR/app" || exit 1
    export PATH="$TMP_DIR/bin:$PATH"
    export COMMAND_LOG APP_ENV=prod
    export SYNAPLAN_ROLE=scheduler
    export SYNAPLAN_RUNTIME_DIR="$TMP_DIR/runtime"
    # shellcheck disable=SC1090
    . "$RUNTIME_LIB"
    prepare_role_cache() { :; }
    wait_for_web_initialization() { :; }
    run_scheduler_role
) >"$CASE2I_LOG" 2>&1 &
CASE2I_PID=$!
CASE2I_SEEN=0
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
    if grep -q 'app:saved-tasks:tick' "$COMMAND_LOG"; then
        CASE2I_SEEN=1
        break
    fi
    sleep 0.1
done
kill -TERM "$CASE2I_PID" 2>/dev/null || true
CASE2I_EXITED=0
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
    if ! kill -0 "$CASE2I_PID" 2>/dev/null; then
        CASE2I_EXITED=1
        break
    fi
    sleep 0.25
done
if [ "$CASE2I_EXITED" -eq 0 ]; then
    case2i_child=''
    for case2i_child in $(pgrep -P "$CASE2I_PID" 2>/dev/null || true); do
        kill -KILL -- "-${case2i_child}" 2>/dev/null || kill -KILL "$case2i_child" 2>/dev/null || true
    done
    kill -KILL "$CASE2I_PID" 2>/dev/null || true
fi
wait "$CASE2I_PID" 2>/dev/null || true
assert_eq 1 "$CASE2I_SEEN" "TERM is sent while a lane is running"
assert_eq 1 "$CASE2I_EXITED" "scheduler exits within a few seconds of TERM"
assert_contains "Scheduler stopped." "$CASE2I_LOG" "scheduler logs that it stopped"

echo "Case 2j: lane commands match ScheduledJobs.php"
SCHEDULED_JOBS_PHP="${SCRIPT_DIR}/../../../backend/src/Service/Scheduler/ScheduledJobs.php"
# app:scheduler:claim is the slot gate, not a lane job, so it is outside this range.
SHELL_JOBS="$(
    awk '
        /^run_scheduler_role\(\)/ {p=0}
        p {print}
        /^run_scheduler_tick_lane\(\)/ {p=1}
    ' "$RUNTIME_LIB" | grep -oE 'app:[A-Za-z0-9:-]+' | sort -u
)"
if [ ! -f "$SCHEDULED_JOBS_PHP" ]; then
    assert_eq 1 0 "scheduler commands match ScheduledJobs.php"
else
    PHP_JOBS="$(grep -oE "'app:[A-Za-z0-9:-]+'" "$SCHEDULED_JOBS_PHP" | tr -d "'" | sort -u)"
    if [ "$SHELL_JOBS" = "$PHP_JOBS" ]; then
        assert_eq 1 1 "scheduler commands match ScheduledJobs.php"
    else
        echo "shell jobs:" >&2
        printf '%s\n' "$SHELL_JOBS" >&2
        echo "php jobs:" >&2
        printf '%s\n' "$PHP_JOBS" >&2
        assert_eq 1 0 "scheduler commands match ScheduledJobs.php"
    fi
fi

echo "Case 3: initialization wait retries pending migrations"
DB_CALLS=0
MIGRATION_CALLS=0
HEALTH_CALLS=0
sleep() { :; }
curl() { HEALTH_CALLS=$((HEALTH_CALLS + 1)); return 0; }
php() {
    case "$*" in
        *dbal:run-sql*) DB_CALLS=$((DB_CALLS + 1)); return 0 ;;
        *doctrine:migrations:up-to-date*)
            MIGRATION_CALLS=$((MIGRATION_CALLS + 1))
            [ "$MIGRATION_CALLS" -ge 3 ]
            ;;
    esac
}
SYNAPLAN_INIT_WAIT_ATTEMPTS=5 SYNAPLAN_INIT_WAIT_SECONDS=0 wait_for_web_initialization >/dev/null
assert_eq 1 "$DB_CALLS" "database readiness is checked"
assert_eq 3 "$MIGRATION_CALLS" "pending migrations are retried"
assert_eq 1 "$HEALTH_CALLS" "web health is checked after migrations"
unset -f php curl sleep

echo "Case 4: healthcheck rejects unsupported roles and stale scheduler heartbeats"
if SYNAPLAN_ROLE=invalid bash "$HEALTHCHECK" >/dev/null 2>&1; then
    assert_eq 1 0 "unsupported role is rejected"
else
    assert_eq 1 1 "unsupported role is rejected"
fi
printf '1\n' > "$TMP_DIR/runtime/scheduler.heartbeat"
if SYNAPLAN_ROLE=scheduler SYNAPLAN_RUNTIME_DIR="$TMP_DIR/runtime" bash "$HEALTHCHECK" >/dev/null 2>&1; then
    assert_eq 1 0 "stale scheduler heartbeat is rejected"
else
    assert_eq 1 1 "stale scheduler heartbeat is rejected"
fi

if [ -d /proc/1 ]; then
    echo "Case 5: Linux process probes recognize live worker and scheduler roles"
    bash -c 'exec -a "php bin/console messenger:consume" sleep 10' &
    worker_pid=$!
    sleep 0.1
    if SYNAPLAN_ROLE=worker bash "$HEALTHCHECK" >/dev/null 2>&1; then
        assert_eq 1 1 "worker process probe recognizes Messenger consumer"
    else
        assert_eq 1 0 "worker process probe recognizes Messenger consumer"
    fi
    kill "$worker_pid" 2>/dev/null || true
    wait "$worker_pid" 2>/dev/null || true

    date +%s > "$TMP_DIR/runtime/scheduler.heartbeat"
    bash -c 'exec -a "docker-entrypoint.sh scheduler" sleep 10' &
    scheduler_pid=$!
    sleep 0.1
    if SYNAPLAN_ROLE=scheduler SYNAPLAN_RUNTIME_DIR="$TMP_DIR/runtime" bash "$HEALTHCHECK" >/dev/null 2>&1; then
        assert_eq 1 1 "scheduler probe accepts fresh heartbeat and live process"
    else
        assert_eq 1 0 "scheduler probe accepts fresh heartbeat and live process"
    fi
    kill "$scheduler_pid" 2>/dev/null || true
    wait "$scheduler_pid" 2>/dev/null || true
fi

echo "Case 6: first-admin bootstrap credentials are validated as a pair"

# $3 is a per-case log path: sharing one file would make every assert_contains
# depend on the call immediately above it.
bootstrap_pair_status() {
    local log="$3"
    local status=0

    # Subshell so the credentials never leak into the following assertions.
    (
        export BOOTSTRAP_ADMIN_EMAIL="$1"
        export BOOTSTRAP_ADMIN_PASSWORD="$2"
        require_bootstrap_admin_pair
    ) > "$log" 2>&1 || status=$?

    printf '%s\n' "$status"
}

BOTH_EMPTY_LOG="$TMP_DIR/bootstrap-both-empty.log"
BOTH_SET_LOG="$TMP_DIR/bootstrap-both-set.log"
EMAIL_ONLY_LOG="$TMP_DIR/bootstrap-email-only.log"
PASSWORD_ONLY_LOG="$TMP_DIR/bootstrap-password-only.log"
BLANK_EMAIL_LOG="$TMP_DIR/bootstrap-blank-email.log"

assert_eq 0 "$(bootstrap_pair_status "" "" "$BOTH_EMPTY_LOG")" "both variables empty keeps the bootstrap optional"
assert_eq 0 "$(bootstrap_pair_status "admin@example.com" "Str0ngPass" "$BOTH_SET_LOG")" "both variables set is accepted"
assert_eq 78 "$(bootstrap_pair_status "admin@example.com" "" "$EMAIL_ONLY_LOG")" "only BOOTSTRAP_ADMIN_EMAIL set is rejected"
assert_contains "BOOTSTRAP_ADMIN_PASSWORD is empty" "$EMAIL_ONLY_LOG" "the error names the missing password variable"
assert_eq 78 "$(bootstrap_pair_status "" "Str0ngPass" "$PASSWORD_ONLY_LOG")" "only BOOTSTRAP_ADMIN_PASSWORD set is rejected"
assert_contains "BOOTSTRAP_ADMIN_EMAIL is empty" "$PASSWORD_ONLY_LOG" "the error names the missing email variable"
# BootstrapAdminService trims the email before its emptiness check, so a
# whitespace-only email without a password is "not configured" there — the
# shell guard must not be stricter and abort the boot instead.
assert_eq 0 "$(bootstrap_pair_status "   " "" "$BLANK_EMAIL_LOG")" "whitespace-only email without a password matches the PHP validator"

echo "Case 7: the full configuration check delegates to the PHP validator"

# A php stub whose exit status and stderr are driven by the environment: this
# case characterizes how the shell REACTS to the authority's verdict, never the
# rules themselves — those are owned by BootstrapAdminConfiguration and tested in
# backend/tests/Unit/Service/Admin/BootstrapAdminConfigurationTest.php.
mkdir -p "$TMP_DIR/php-stub" "$TMP_DIR/app/vendor"
: > "$TMP_DIR/app/vendor/autoload.php"
PHP_STUB_LOG="$TMP_DIR/php-stub-invocations.log"
cat > "$TMP_DIR/php-stub/php" <<'EOF'
#!/bin/sh
printf 'invoked\n' >> "$PHP_STUB_LOG"
if [ -n "${PHP_STUB_MESSAGE:-}" ]; then
    printf '%s' "$PHP_STUB_MESSAGE" >&2
fi
exit "${PHP_STUB_EXIT:-0}"
EOF
chmod +x "$TMP_DIR/php-stub/php"

# $1 email  $2 password  $3 stub exit  $4 stub stderr  $5 log path
bootstrap_config_status() {
    local status=0

    : > "$PHP_STUB_LOG"
    (
        cd "$TMP_DIR/app" || exit 70
        export PATH="$TMP_DIR/php-stub:$PATH"
        export PHP_STUB_LOG
        export BOOTSTRAP_ADMIN_EMAIL="$1"
        export BOOTSTRAP_ADMIN_PASSWORD="$2"
        export PHP_STUB_EXIT="$3"
        export PHP_STUB_MESSAGE="$4"
        # shellcheck disable=SC1090
        . "$RUNTIME_LIB"
        require_valid_bootstrap_admin_config
    ) > "$5" 2>&1 || status=$?

    printf '%s\n' "$status"
}

CONFIG_INERT_LOG="$TMP_DIR/config-inert.log"
CONFIG_BLANK_EMAIL_LOG="$TMP_DIR/config-blank-email.log"
CONFIG_VALID_LOG="$TMP_DIR/config-valid.log"
CONFIG_INVALID_LOG="$TMP_DIR/config-invalid.log"
CONFIG_UNAVAILABLE_LOG="$TMP_DIR/config-unavailable.log"
CONFIG_NO_AUTOLOAD_LOG="$TMP_DIR/config-no-autoload.log"

assert_eq 0 "$(bootstrap_config_status "" "" 1 "should never run" "$CONFIG_INERT_LOG")" \
    "an unconfigured bootstrap is accepted without consulting PHP"
if [ -s "$PHP_STUB_LOG" ]; then
    assert_eq 1 0 "an unconfigured bootstrap starts no PHP process"
else
    assert_eq 1 1 "an unconfigured bootstrap starts no PHP process"
fi

# The authority trims the email before deciding whether the bootstrap is
# configured, so a whitespace-only address without a password is not configured
# at all. Without the same normalization the guard spawns PHP and then reports an
# accepted configuration for a bootstrap that never runs.
assert_eq 0 "$(bootstrap_config_status "   " "" 1 "should never run" "$CONFIG_BLANK_EMAIL_LOG")" \
    "a whitespace-only email without a password stays unconfigured"
if [ -s "$PHP_STUB_LOG" ]; then
    assert_eq 1 0 "a whitespace-only email without a password starts no PHP process"
else
    assert_eq 1 1 "a whitespace-only email without a password starts no PHP process"
fi
assert_not_contains "First-admin bootstrap configuration accepted." "$CONFIG_BLANK_EMAIL_LOG" \
    "an unconfigured bootstrap never claims that a configuration was accepted"

assert_eq 0 "$(bootstrap_config_status "admin@example.com" "Str0ngPass" 0 "" "$CONFIG_VALID_LOG")" \
    "a configuration the validator accepts continues the startup"
if [ -s "$PHP_STUB_LOG" ]; then
    assert_eq 1 1 "a configured bootstrap is validated by the PHP authority"
else
    assert_eq 1 0 "a configured bootstrap is validated by the PHP authority"
fi

assert_eq 1 "$(bootstrap_config_status "not-an-email" "Str0ngPass" 1 "BOOTSTRAP_ADMIN_EMAIL must be a valid email address of at most 128 characters." "$CONFIG_INVALID_LOG")" \
    "a rejected value aborts the startup with exit code 1"
assert_contains "BOOTSTRAP_ADMIN_EMAIL must be a valid email address" "$CONFIG_INVALID_LOG" \
    "the validator's own message is surfaced verbatim"
assert_contains "before the database wait, the migrations and the seeders" "$CONFIG_INVALID_LOG" \
    "the error states that nothing was written to the database"

# Exit code 2 means the check itself could not run. It must never be the reason
# a container stops: the bootstrap command still validates the same values.
assert_eq 0 "$(bootstrap_config_status "admin@example.com" "Str0ngPass" 2 "autoloader is incomplete" "$CONFIG_UNAVAILABLE_LOG")" \
    "an unavailable validator does not block the startup"
assert_contains "Could not run the early first-admin configuration check" "$CONFIG_UNAVAILABLE_LOG" \
    "an unavailable validator is reported as a warning"
# This is the one line that tells an operator "your credentials were never
# validated", so it must carry the entrypoint's warning marker instead of
# looking like ordinary status output.
assert_contains "⚠️" "$CONFIG_UNAVAILABLE_LOG" \
    "the unvalidated-configuration warning is marked as a warning"

CONFIG_NO_AUTOLOAD_STATUS=0
(
    cd "$TMP_DIR" || exit 70
    export PATH="$TMP_DIR/php-stub:$PATH"
    export PHP_STUB_LOG
    export BOOTSTRAP_ADMIN_EMAIL="admin@example.com"
    export BOOTSTRAP_ADMIN_PASSWORD="Str0ngPass"
    export SYNAPLAN_AUTOLOAD_PATH="$TMP_DIR/missing/autoload.php"
    # shellcheck disable=SC1090
    . "$RUNTIME_LIB"
    require_valid_bootstrap_admin_config
) > "$CONFIG_NO_AUTOLOAD_LOG" 2>&1 || CONFIG_NO_AUTOLOAD_STATUS=$?
assert_eq 0 "$CONFIG_NO_AUTOLOAD_STATUS" "a missing autoloader skips the check instead of failing"
assert_contains "Skipping the early first-admin configuration check" "$CONFIG_NO_AUTOLOAD_LOG" \
    "the skipped check says why it was skipped"
assert_contains "⚠️" "$CONFIG_NO_AUTOLOAD_LOG" \
    "the skipped check is marked as a warning"

echo "Case 8: the full configuration check owns no rules of its own"

# CONTRACT: the guard must only pass the environment to
# BootstrapAdminConfiguration and report its verdict. The moment either half of
# it grows a rule, that copy can drift looser than the authority — which is
# exactly how an invalid address once survived a preflight and crash-looped the
# backend.
#
# The function has two halves and BOTH are scanned. Splitting them at the
# embedded heredoc is what makes that possible: a scan that simply stops at the
# first line consisting of a closing brace stops at the heredoc's PHP brace and
# silently leaves the entire verdict evaluation unchecked — a shell copy of a
# password rule placed after the heredoc then passes unnoticed.
GUARD_PROGRAM="$TMP_DIR/require-valid-config-program.php"
GUARD_SHELL="$TMP_DIR/require-valid-config-shell.sh"
: > "$GUARD_PROGRAM"
: > "$GUARD_SHELL"
awk -v opener="<<'PHP'" -v program="$GUARD_PROGRAM" -v shell_body="$GUARD_SHELL" '
    !capture {
        if ($0 == "require_valid_bootstrap_admin_config() {") {
            capture = 1
        }
        next
    }
    heredoc {
        if ($0 == "PHP") {
            heredoc = 0
        } else {
            print > program
        }
        next
    }
    index($0, opener) {
        heredoc = 1
        next
    }
    $0 == "}" {
        exit
    }
    {
        print > shell_body
    }
' "$RUNTIME_LIB"

# An empty region would make every "does not reimplement" assertion below pass
# for free, so both halves are first proven to contain what they must.
assert_contains "App\\Service\\Admin\\BootstrapAdminConfiguration::fromConfiguration" "$GUARD_PROGRAM" \
    "the guard calls the authoritative validator"
# The first line after the heredoc and a line just before the function's own
# closing brace: together they prove the second half is scanned to the end.
assert_contains 'local message verdict=0' "$GUARD_SHELL" \
    "the scanned shell half reaches the verdict evaluation behind the heredoc"
assert_contains "Nothing was written to the database" "$GUARD_SHELL" \
    "the scanned shell half reaches the end of the function"

# The PHP program may only call the authority: these are the mechanisms the
# authority's own rules are built from.
for FORBIDDEN_MECHANISM in filter_var FILTER_VALIDATE_EMAIL preg_match strlen; do
    assert_not_contains "$FORBIDDEN_MECHANISM" "$GUARD_PROGRAM" \
        "the PHP program does not reimplement a rule with '${FORBIDDEN_MECHANISM}'"
done

# The shell half is checked by MECHANISM, not by threshold. '${#' is the only way
# shell measures a length, so it covers every length rule (email maximum,
# password minimum and maximum, composition waiver) no matter which variable a
# drifted copy measures; '=~' and a character class are the only ways it inspects
# content. Matching bare numbers instead would miss a rule written against a
# local variable AND false-positive on any legitimate number — a '--max-time 128',
# an 'exit 128', a "128" inside a comment.
for FORBIDDEN_MECHANISM in filter_var FILTER_VALIDATE_EMAIL preg_match '${#' '=~' '[[:upper:]]' '[[:lower:]]' '[[:digit:]]' '*@*'; do
    assert_not_contains "$FORBIDDEN_MECHANISM" "$GUARD_SHELL" \
        "the shell half does not reimplement a rule with '${FORBIDDEN_MECHANISM}'"
done

echo "Case 9: the entrypoint wires both bootstrap guards in before any expensive work"
ENTRYPOINT="${SCRIPT_DIR}/../docker-entrypoint.sh"

# First matching line number, or empty when the pattern is absent.
entrypoint_line_of() {
    grep -n -E -- "$1" "$ENTRYPOINT" | head -n 1 | cut -d: -f1
}

GUARD_LINE="$(entrypoint_line_of '^[[:space:]]*require_bootstrap_admin_pair[[:space:]]*$')"
CONFIG_GUARD_LINE="$(entrypoint_line_of '^[[:space:]]*require_valid_bootstrap_admin_config[[:space:]]*$')"
# The full check needs vendor/, which the dev stack only populates in the
# /docker-entrypoint.d block.
STARTUP_SCRIPTS_LINE="$(entrypoint_line_of '^[[:space:]]*echo "✅ Additional startup scripts completed"')"
# The guards only pay off ahead of the first expensive step: the role dispatch
# for worker/scheduler and the database wait for web.
EXPENSIVE_LINE="$(entrypoint_line_of '^[[:space:]]*(run_worker_role|run_scheduler_role|wait_for_database)')"

if [ -n "$GUARD_LINE" ]; then
    assert_eq 1 1 "the entrypoint calls require_bootstrap_admin_pair"
else
    assert_eq 1 0 "the entrypoint calls require_bootstrap_admin_pair"
fi
if [ -n "$GUARD_LINE" ] && [ -n "$EXPENSIVE_LINE" ] && [ "$GUARD_LINE" -lt "$EXPENSIVE_LINE" ]; then
    assert_eq 1 1 "the pairing guard runs before the role dispatch and the database wait"
else
    assert_eq 1 0 "the pairing guard runs before the role dispatch and the database wait"
fi
if [ -n "$CONFIG_GUARD_LINE" ]; then
    assert_eq 1 1 "the entrypoint calls require_valid_bootstrap_admin_config"
else
    assert_eq 1 0 "the entrypoint calls require_valid_bootstrap_admin_config"
fi
if [ -n "$CONFIG_GUARD_LINE" ] && [ -n "$EXPENSIVE_LINE" ] && [ "$CONFIG_GUARD_LINE" -lt "$EXPENSIVE_LINE" ]; then
    assert_eq 1 1 "the full configuration check runs before the role dispatch and the database wait"
else
    assert_eq 1 0 "the full configuration check runs before the role dispatch and the database wait"
fi
if [ -n "$CONFIG_GUARD_LINE" ] && [ -n "$STARTUP_SCRIPTS_LINE" ] && [ "$CONFIG_GUARD_LINE" -gt "$STARTUP_SCRIPTS_LINE" ]; then
    assert_eq 1 1 "the full configuration check runs after /docker-entrypoint.d has populated vendor/"
else
    assert_eq 1 0 "the full configuration check runs after /docker-entrypoint.d has populated vendor/"
fi
if [ -n "$GUARD_LINE" ] && [ -n "$CONFIG_GUARD_LINE" ] && [ "$GUARD_LINE" -lt "$CONFIG_GUARD_LINE" ]; then
    assert_eq 1 1 "the free pairing guard stays ahead of the PHP-backed check"
else
    assert_eq 1 0 "the free pairing guard stays ahead of the PHP-backed check"
fi

echo "Case 10: a worker timeout explains what never happened and where to look"
TIMEOUT_LOG="$TMP_DIR/init-timeout.log"
(
    # shellcheck disable=SC1090
    . "$RUNTIME_LIB"
    php() {
        case "$*" in
            *dbal:run-sql*) return 0 ;;
            *) return 1 ;;
        esac
    }
    sleep() { :; }
    SYNAPLAN_ROLE=worker SYNAPLAN_INIT_WAIT_ATTEMPTS=2 SYNAPLAN_INIT_WAIT_SECONDS=0 \
        wait_for_web_initialization
) > "$TIMEOUT_LOG" 2>&1
TIMEOUT_STATUS=$?
assert_eq 67 "$TIMEOUT_STATUS" "a pending-migration timeout keeps exit code 67"
assert_contains "Doctrine still reports pending migrations" "$TIMEOUT_LOG" \
    "the timeout names what was being waited for"
assert_contains "docker compose logs backend" "$TIMEOUT_LOG" \
    "the timeout points at the web container's log"

echo "Case 11: the documented exit codes survive the entrypoint's 'set -e'"

# This suite runs with `set -u` only, but the entrypoint that calls these
# functions runs with `set -euo pipefail` — and under `set -e` a helper whose own
# non-zero status is not guarded ends the shell right there, with ITS status
# instead of the code the function was about to return. That is how the
# documented 64 silently became a 1. Each guard is therefore exercised in a
# subshell with the entrypoint's options.
runtime_status_under_set_e() {
    local status=0
    bash -c "
        set -euo pipefail
        . '$RUNTIME_LIB'
        cd '$1'
        $2
    " >/dev/null 2>&1 || status=$?

    printf '%s\n' "$status"
}

assert_eq 64 "$(runtime_status_under_set_e "$TMP_DIR" require_console)" \
    "a missing bin/console keeps exit code 64 under set -e"
assert_eq 0 "$(runtime_status_under_set_e "$TMP_DIR/app" require_console)" \
    "an existing bin/console is accepted under set -e"
assert_eq 78 "$(BOOTSTRAP_ADMIN_EMAIL=admin@example.com BOOTSTRAP_ADMIN_PASSWORD= \
    runtime_status_under_set_e "$TMP_DIR/app" require_bootstrap_admin_pair)" \
    "a half-configured bootstrap pair keeps exit code 78 under set -e"

TOTAL=$((PASS + FAIL))
echo "${PASS}/${TOTAL} assertions passed"
[ "$FAIL" -eq 0 ]
