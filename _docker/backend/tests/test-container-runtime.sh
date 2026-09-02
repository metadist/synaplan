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

echo "Case 2: scheduler runs every slot's command initially and writes a heartbeat"
: > "$COMMAND_LOG"
(
    cd "$TMP_DIR/app" || exit
    export PATH="$TMP_DIR/bin:$PATH"
    export COMMAND_LOG
    export SYNAPLAN_ROLE=scheduler
    export SYNAPLAN_RUNTIME_DIR="$TMP_DIR/runtime"
    export SYNAPLAN_SCHEDULER_MAX_CYCLES=1
    # shellcheck disable=SC1090
    . "$RUNTIME_LIB"
    prepare_role_cache() { :; }
    wait_for_web_initialization() { :; }
    php() {
        printf '%s\n' "$*" >> "$COMMAND_LOG"
    }
    run_scheduler_role
)
assert_contains "app:media:reap-jobs --no-interaction" "$COMMAND_LOG" "scheduler runs media reaper"
assert_contains "app:files:reap-ephemeral --no-interaction" "$COMMAND_LOG" "scheduler runs ephemeral-file reaper"
assert_contains "app:updates:check --no-interaction" "$COMMAND_LOG" "scheduler runs the daily update check"
assert_contains "app:models:check-availability --notify --no-interaction" "$COMMAND_LOG" "scheduler runs the daily model availability check"
assert_contains "app:selfaware:sync-docs --no-interaction" "$COMMAND_LOG" "scheduler runs the daily platform docs sync"
assert_contains "app:model:health-check --jitter=" "$COMMAND_LOG" "scheduler runs the model health check with request jitter"
if [ -s "$TMP_DIR/runtime/scheduler.heartbeat" ]; then
    assert_eq 1 1 "scheduler writes liveness heartbeat"
else
    assert_eq 1 0 "scheduler writes liveness heartbeat"
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
