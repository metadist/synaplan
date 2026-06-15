#!/usr/bin/env bash
# Bash-level tests for lib/migrations-bootstrap.sh.
#
# Covers the four production-relevant DB states the entrypoint must self-heal
# without calling a real database:
#
#   1. Fresh DB              (BUSER missing)                         → no-op
#   2. Legacy DB, no table   (metadata table missing entirely)       → create table + register baseline
#   3. Legacy DB, empty tbl  (metadata table empty)                  → register baseline
#   4. Legacy DB, unrelated  (metadata table has non-baseline rows)  → register baseline
#   5. Legacy DB, healthy    (baseline row already present)          → no-op
#   8. Half-applied baseline (BAPIKEYS present, BUSER absent)        → drop orphan tables, then no-op
#
# The test doubles simulate the DB by maintaining $DB_STATE in-process. `php`
# (and therefore `dbal:run-sql`) is shadowed via a function override, and
# `_count_sql` / `_register_baseline_migration` / `_create_metadata_table` are
# redefined to update the fake state without touching a real server.
#
# Requirements: bash 4+ (uses associative arrays via `declare -A`). macOS ships
# bash 3.2 at /bin/bash by default — install a newer bash via Homebrew
# (`brew install bash`) or run with `/opt/homebrew/bin/bash` / `/usr/local/bin/bash`.
#
# Run:  bash _docker/backend/tests/test-migrations-bootstrap.sh

if (( BASH_VERSINFO[0] < 4 )); then
    echo "❌ This test requires bash 4+ (found ${BASH_VERSION:-unknown})." >&2
    echo "   macOS default /bin/bash is 3.2.x and does not support associative arrays." >&2
    echo "   Install a newer bash (e.g. \`brew install bash\`) and re-run with that binary." >&2
    exit 2
fi

set -u

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LIB="${SCRIPT_DIR}/../lib/migrations-bootstrap.sh"

if [ ! -r "$LIB" ]; then
    echo "FAIL: cannot find library at $LIB" >&2
    exit 2
fi

# shellcheck disable=SC1090
. "$LIB"

# ---------------------------------------------------------------------------
# In-memory fake DB + test doubles
# ---------------------------------------------------------------------------

# DB_STATE fields:
#   HAS_BUSER          1 if BUSER table exists
#   HAS_BAPIKEYS       1 if BAPIKEYS table exists (baseline's first table)
#   HAS_VERSIONS_TABLE 1 if doctrine_migration_versions table exists
#   HAS_BASELINE_ROW   1 if baseline version is registered
#   VERSION_ROW_COUNT  total number of rows in the versions table
#   CREATE_CALLS       number of times _create_metadata_table was invoked
#   REGISTER_CALLS     number of times _register_baseline_migration was invoked
#   DROP_CALLS         number of times _drop_all_tables was invoked
declare -A DB_STATE

reset_state() {
    DB_STATE[HAS_BUSER]=0
    DB_STATE[HAS_BAPIKEYS]=0
    DB_STATE[HAS_VERSIONS_TABLE]=0
    DB_STATE[HAS_BASELINE_ROW]=0
    DB_STATE[VERSION_ROW_COUNT]=0
    DB_STATE[CREATE_CALLS]=0
    DB_STATE[REGISTER_CALLS]=0
    DB_STATE[DROP_CALLS]=0
}

# Override _count_sql to answer from DB_STATE based on which query it sees.
_count_sql() {
    local _sql="$1"
    case "$_sql" in
        *"table_name = 'BUSER'"*)
            echo "${DB_STATE[HAS_BUSER]}"
            ;;
        *"table_name = 'BAPIKEYS'"*)
            echo "${DB_STATE[HAS_BAPIKEYS]}"
            ;;
        *"table_name = 'doctrine_migration_versions'"*)
            echo "${DB_STATE[HAS_VERSIONS_TABLE]}"
            ;;
        *"FROM doctrine_migration_versions WHERE version"*)
            echo "${DB_STATE[HAS_BASELINE_ROW]}"
            ;;
        *)
            echo "0"
            ;;
    esac
}

# Override the DDL/DML helpers to just bump counters and mutate DB_STATE.
_create_metadata_table() {
    DB_STATE[CREATE_CALLS]=$((DB_STATE[CREATE_CALLS] + 1))
    DB_STATE[HAS_VERSIONS_TABLE]=1
}

_register_baseline_migration() {
    DB_STATE[REGISTER_CALLS]=$((DB_STATE[REGISTER_CALLS] + 1))
    DB_STATE[HAS_BASELINE_ROW]=1
    DB_STATE[VERSION_ROW_COUNT]=$((DB_STATE[VERSION_ROW_COUNT] + 1))
}

_drop_all_tables() {
    DB_STATE[DROP_CALLS]=$((DB_STATE[DROP_CALLS] + 1))
    # Simulate the post-drop state: the schema is now empty.
    DB_STATE[HAS_BUSER]=0
    DB_STATE[HAS_BAPIKEYS]=0
    DB_STATE[HAS_VERSIONS_TABLE]=0
    DB_STATE[HAS_BASELINE_ROW]=0
    DB_STATE[VERSION_ROW_COUNT]=0
}

# ---------------------------------------------------------------------------
# Assertion helpers
# ---------------------------------------------------------------------------

PASS=0
FAIL=0

assert_eq() {
    local _expected="$1"
    local _actual="$2"
    local _what="$3"
    if [ "$_expected" = "$_actual" ]; then
        PASS=$((PASS + 1))
        echo "   ✅ ${_what}: ${_actual}"
    else
        FAIL=$((FAIL + 1))
        echo "   ❌ ${_what}: expected=${_expected} actual=${_actual}" >&2
    fi
}

# ---------------------------------------------------------------------------
# Test cases
# ---------------------------------------------------------------------------

echo "▶ Case 1: fresh DB (no BUSER, no BAPIKEYS) — bootstrap must be a no-op"
reset_state
bootstrap_migrations_metadata "" "test-fresh" >/dev/null
assert_eq 0 "${DB_STATE[CREATE_CALLS]}"   "_create_metadata_table NOT called"
assert_eq 0 "${DB_STATE[REGISTER_CALLS]}" "_register_baseline_migration NOT called"
assert_eq 0 "${DB_STATE[DROP_CALLS]}"     "_drop_all_tables NOT called"

echo "▶ Case 2: legacy DB, no metadata table — bootstrap must create table AND register baseline"
reset_state
DB_STATE[HAS_BUSER]=1
DB_STATE[HAS_VERSIONS_TABLE]=0
bootstrap_migrations_metadata "" "test-legacy-no-table" >/dev/null
assert_eq 1 "${DB_STATE[CREATE_CALLS]}"   "_create_metadata_table called exactly once"
assert_eq 1 "${DB_STATE[REGISTER_CALLS]}" "_register_baseline_migration called exactly once"
assert_eq 1 "${DB_STATE[HAS_BASELINE_ROW]}" "baseline row registered"

echo "▶ Case 3: legacy DB, empty metadata table — bootstrap must register baseline only"
reset_state
DB_STATE[HAS_BUSER]=1
DB_STATE[HAS_VERSIONS_TABLE]=1
DB_STATE[HAS_BASELINE_ROW]=0
bootstrap_migrations_metadata "" "test-legacy-empty" >/dev/null
assert_eq 0 "${DB_STATE[CREATE_CALLS]}"   "_create_metadata_table NOT called"
assert_eq 1 "${DB_STATE[REGISTER_CALLS]}" "_register_baseline_migration called exactly once"
assert_eq 1 "${DB_STATE[HAS_BASELINE_ROW]}" "baseline row registered"

echo "▶ Case 4: legacy DB, metadata table has unrelated rows — bootstrap must still register baseline"
reset_state
DB_STATE[HAS_BUSER]=1
DB_STATE[HAS_VERSIONS_TABLE]=1
DB_STATE[HAS_BASELINE_ROW]=0
DB_STATE[VERSION_ROW_COUNT]=3   # e.g. some older baseline + 2 post-baseline rows
bootstrap_migrations_metadata "" "test-legacy-unrelated" >/dev/null
assert_eq 0 "${DB_STATE[CREATE_CALLS]}"   "_create_metadata_table NOT called"
assert_eq 1 "${DB_STATE[REGISTER_CALLS]}" "_register_baseline_migration called exactly once"
assert_eq 1 "${DB_STATE[HAS_BASELINE_ROW]}" "baseline row registered alongside unrelated rows"

echo "▶ Case 5: healthy DB — bootstrap is a no-op (idempotent on healthy state)"
reset_state
DB_STATE[HAS_BUSER]=1
DB_STATE[HAS_VERSIONS_TABLE]=1
DB_STATE[HAS_BASELINE_ROW]=1
DB_STATE[VERSION_ROW_COUNT]=3
bootstrap_migrations_metadata "" "test-healthy" >/dev/null
# Both assertions expect 0: the versions table already exists so we skip CREATE,
# and the baseline row is already registered so we skip the INSERT IGNORE.
assert_eq 0 "${DB_STATE[CREATE_CALLS]}"   "_create_metadata_table NOT called (table already exists)"
assert_eq 0 "${DB_STATE[REGISTER_CALLS]}" "_register_baseline_migration NOT called (row already registered)"

echo "▶ Case 6: double invocation on a broken DB is safe (second call is a no-op)"
reset_state
DB_STATE[HAS_BUSER]=1
DB_STATE[HAS_VERSIONS_TABLE]=0
bootstrap_migrations_metadata "" "test-double-1" >/dev/null
bootstrap_migrations_metadata "" "test-double-2" >/dev/null
assert_eq 1 "${DB_STATE[CREATE_CALLS]}"   "_create_metadata_table called exactly once across two invocations"
assert_eq 1 "${DB_STATE[REGISTER_CALLS]}" "_register_baseline_migration called exactly once across two invocations"

echo "▶ Case 8: half-applied baseline (BAPIKEYS present, BUSER absent) — bootstrap must drop orphan tables then no-op"
reset_state
DB_STATE[HAS_BUSER]=0
DB_STATE[HAS_BAPIKEYS]=1            # baseline's first table got created
DB_STATE[HAS_VERSIONS_TABLE]=0     # ...but the run died before recording the version
bootstrap_migrations_metadata "" "test-partial-baseline" >/dev/null
assert_eq 1 "${DB_STATE[DROP_CALLS]}"     "_drop_all_tables called exactly once"
assert_eq 0 "${DB_STATE[CREATE_CALLS]}"   "_create_metadata_table NOT called (left to migrate)"
assert_eq 0 "${DB_STATE[REGISTER_CALLS]}" "_register_baseline_migration NOT called (left to migrate)"
assert_eq 0 "${DB_STATE[HAS_BAPIKEYS]}"   "orphan tables cleared so migrate re-runs clean"

echo "▶ Case 9: double invocation on a half-applied baseline is safe (second call is a no-op)"
reset_state
DB_STATE[HAS_BUSER]=0
DB_STATE[HAS_BAPIKEYS]=1
DB_STATE[HAS_VERSIONS_TABLE]=0
bootstrap_migrations_metadata "" "test-partial-1" >/dev/null
bootstrap_migrations_metadata "" "test-partial-2" >/dev/null
assert_eq 1 "${DB_STATE[DROP_CALLS]}"     "_drop_all_tables called exactly once across two invocations"

echo "▶ Case 9b: half-applied baseline where the orphan-table drop FAILS — bootstrap must abort (non-zero), not fall through to migrate"
reset_state
DB_STATE[HAS_BUSER]=0
DB_STATE[HAS_BAPIKEYS]=1
DB_STATE[HAS_VERSIONS_TABLE]=0
# Temporarily shadow the drop helper with a failing variant.
_drop_all_tables() {
    DB_STATE[DROP_CALLS]=$((DB_STATE[DROP_CALLS] + 1))
    return 1
}
bootstrap_migrations_metadata "" "test-partial-drop-fails" >/dev/null 2>&1
_rc=$?
assert_eq 1 "$_rc"                        "bootstrap returns non-zero when the orphan-table drop fails"
assert_eq 1 "${DB_STATE[DROP_CALLS]}"     "_drop_all_tables attempted exactly once"
assert_eq 1 "${DB_STATE[HAS_BAPIKEYS]}"   "orphan tables left intact (drop failed) — failure is surfaced, not masked"
# Restore the success variant for any later cases relying on it.
_drop_all_tables() {
    DB_STATE[DROP_CALLS]=$((DB_STATE[DROP_CALLS] + 1))
    DB_STATE[HAS_BUSER]=0
    DB_STATE[HAS_BAPIKEYS]=0
    DB_STATE[HAS_VERSIONS_TABLE]=0
    DB_STATE[HAS_BASELINE_ROW]=0
    DB_STATE[VERSION_ROW_COUNT]=0
}

# ---------------------------------------------------------------------------
# Retry wrapper (run_migrations_with_retry)
#
# Stub both the metadata bootstrap and the migrate call so we can drive the
# control flow deterministically: count how many times each runs and make the
# migrate succeed/fail on demand. No sleeps (delay forced to 0).
# ---------------------------------------------------------------------------

# Counters + scripted behaviour for the stubs.
BOOTSTRAP_CALLS=0
MIGRATE_CALLS=0
MIGRATE_FAIL_UNTIL=0   # _run_doctrine_migrate fails while MIGRATE_CALLS <= this
BOOTSTRAP_FAILS=0      # when 1, bootstrap_migrations_metadata returns non-zero

bootstrap_migrations_metadata() {
    BOOTSTRAP_CALLS=$((BOOTSTRAP_CALLS + 1))
    if [ "$BOOTSTRAP_FAILS" -eq 1 ]; then
        return 1
    fi
    return 0
}

_run_doctrine_migrate() {
    MIGRATE_CALLS=$((MIGRATE_CALLS + 1))
    if [ "$MIGRATE_CALLS" -le "$MIGRATE_FAIL_UNTIL" ]; then
        return 1
    fi
    return 0
}

# Keep the suite fast — no real backoff sleeps.
export MIGRATION_RETRY_DELAY_SECONDS=0

echo "▶ Case 10: migrate succeeds first try — bootstrap + migrate run exactly once, returns 0"
BOOTSTRAP_CALLS=0 ; MIGRATE_CALLS=0 ; MIGRATE_FAIL_UNTIL=0
MIGRATION_MAX_ATTEMPTS=5 run_migrations_with_retry "" "test-retry-ok" >/dev/null
_rc=$?
assert_eq 0 "$_rc"               "run_migrations_with_retry returns 0"
assert_eq 1 "$BOOTSTRAP_CALLS"   "bootstrap run once"
assert_eq 1 "$MIGRATE_CALLS"     "migrate run once"

echo "▶ Case 11: migrate fails twice then succeeds — bootstrap re-runs before EACH attempt, returns 0"
BOOTSTRAP_CALLS=0 ; MIGRATE_CALLS=0 ; MIGRATE_FAIL_UNTIL=2
MIGRATION_MAX_ATTEMPTS=5 run_migrations_with_retry "" "test-retry-transient" >/dev/null
_rc=$?
assert_eq 0 "$_rc"               "run_migrations_with_retry eventually returns 0"
assert_eq 3 "$MIGRATE_CALLS"     "migrate retried until success (3 calls)"
assert_eq 3 "$BOOTSTRAP_CALLS"   "bootstrap re-ran before every attempt (3 calls)"

echo "▶ Case 12: migrate always fails — gives up after MIGRATION_MAX_ATTEMPTS, returns 1"
BOOTSTRAP_CALLS=0 ; MIGRATE_CALLS=0 ; MIGRATE_FAIL_UNTIL=999 ; BOOTSTRAP_FAILS=0
MIGRATION_MAX_ATTEMPTS=3 run_migrations_with_retry "" "test-retry-broken" >/dev/null
_rc=$?
assert_eq 1 "$_rc"               "run_migrations_with_retry returns 1 after exhausting attempts"
assert_eq 3 "$MIGRATE_CALLS"     "migrate attempted exactly MIGRATION_MAX_ATTEMPTS times"
assert_eq 3 "$BOOTSTRAP_CALLS"   "bootstrap ran before every attempt"

echo "▶ Case 13: bootstrap itself fails — retry wrapper aborts immediately without ever calling migrate"
BOOTSTRAP_CALLS=0 ; MIGRATE_CALLS=0 ; MIGRATE_FAIL_UNTIL=0 ; BOOTSTRAP_FAILS=1
MIGRATION_MAX_ATTEMPTS=5 run_migrations_with_retry "" "test-retry-bootstrap-fail" >/dev/null 2>&1
_rc=$?
BOOTSTRAP_FAILS=0
assert_eq 1 "$_rc"               "run_migrations_with_retry returns 1 when bootstrap fails"
assert_eq 1 "$BOOTSTRAP_CALLS"   "bootstrap attempted exactly once (no retry on unrecoverable setup failure)"
assert_eq 0 "$MIGRATE_CALLS"     "migrate never attempted after a failed bootstrap"

# ---------------------------------------------------------------------------
# Case 7: MySQL backslash escape regression test
#
# Re-source the library so we get the REAL _register_baseline_migration /
# _count_sql helpers (the earlier test cases replaced them with in-memory
# stubs). Then shadow `php` so we can capture the SQL the bootstrap would send
# to `bin/console dbal:run-sql` and assert that the emitted INSERT contains a
# properly-doubled `\\Version` literal — otherwise MySQL would strip the
# backslash while parsing the single-quoted string and Doctrine would fail to
# recognise the stored row as the baseline migration.
# ---------------------------------------------------------------------------

echo "▶ Case 7: emitted INSERT SQL escapes the namespace separator for MySQL"

# Re-source to restore the real helpers that the stubs above replaced.
# shellcheck disable=SC1090
. "$LIB"

CAPTURED_SQL_FILE="$(mktemp)"
trap 'rm -f "$CAPTURED_SQL_FILE"' EXIT

# Stub out `php bin/console dbal:run-sql` by shadowing `php` in this shell.
# The bootstrap library happens to only invoke `php` through this exact path.
php() {
    # The SQL statement is always the last positional argument we care about.
    # `dbal:run-sql` takes either (sql) or (--env=..., sql), so capture the
    # final arg which the bootstrap uses for the SQL string.
    local _last
    for _last in "$@"; do :; done
    printf '%s\n' "$_last" >> "$CAPTURED_SQL_FILE"
}
export -f php

: > "$CAPTURED_SQL_FILE"
_register_baseline_migration ""

# Expect two statements: the self-healing DELETE of the legacy stripped row,
# followed by the INSERT IGNORE with properly-escaped backslashes.
CAPTURED_SQL="$(cat "$CAPTURED_SQL_FILE")"

if grep -Fq "INSERT IGNORE INTO doctrine_migration_versions" <<<"$CAPTURED_SQL" && \
   grep -Fq "'DoctrineMigrations\\\\Version20260417000000'" <<<"$CAPTURED_SQL"; then
    PASS=$((PASS + 1))
    echo "   ✅ INSERT emits 'DoctrineMigrations\\\\Version...' (MySQL will store single backslash)"
else
    FAIL=$((FAIL + 1))
    echo "   ❌ INSERT SQL missing doubled backslash; captured:" >&2
    printf '      %s\n' "$CAPTURED_SQL" >&2
fi

if grep -Fq "DELETE FROM doctrine_migration_versions" <<<"$CAPTURED_SQL" && \
   grep -Fq "'DoctrineMigrationsVersion20260417000000'" <<<"$CAPTURED_SQL"; then
    PASS=$((PASS + 1))
    echo "   ✅ Self-healing DELETE targets legacy stripped row"
else
    FAIL=$((FAIL + 1))
    echo "   ❌ Self-healing DELETE for stripped row missing; captured:" >&2
    printf '      %s\n' "$CAPTURED_SQL" >&2
fi

unset -f php
rm -f "$CAPTURED_SQL_FILE"
trap - EXIT

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

TOTAL=$((PASS + FAIL))
echo ""
echo "──────────────────────────────────────"
echo "  tests passed: ${PASS} / ${TOTAL}"
if [ "$FAIL" -gt 0 ]; then
    echo "  tests failed: ${FAIL}"
    exit 1
fi
echo "  ALL GREEN"
