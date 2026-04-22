#!/bin/bash
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
#
# The test doubles simulate the DB by maintaining $DB_STATE in-process. `php`
# (and therefore `dbal:run-sql`) is shadowed via a function override, and
# `_count_sql` / `_register_baseline_migration` / `_create_metadata_table` are
# redefined to update the fake state without touching a real server.
#
# Run:  bash _docker/backend/tests/test-migrations-bootstrap.sh

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
#   HAS_VERSIONS_TABLE 1 if doctrine_migration_versions table exists
#   HAS_BASELINE_ROW   1 if baseline version is registered
#   VERSION_ROW_COUNT  total number of rows in the versions table
#   CREATE_CALLS       number of times _create_metadata_table was invoked
#   REGISTER_CALLS     number of times _register_baseline_migration was invoked
declare -A DB_STATE

reset_state() {
    DB_STATE[HAS_BUSER]=0
    DB_STATE[HAS_VERSIONS_TABLE]=0
    DB_STATE[HAS_BASELINE_ROW]=0
    DB_STATE[VERSION_ROW_COUNT]=0
    DB_STATE[CREATE_CALLS]=0
    DB_STATE[REGISTER_CALLS]=0
}

# Override _count_sql to answer from DB_STATE based on which query it sees.
_count_sql() {
    local _sql="$1"
    case "$_sql" in
        *"table_name = 'BUSER'"*)
            echo "${DB_STATE[HAS_BUSER]}"
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

echo "▶ Case 1: fresh DB (no BUSER) — bootstrap must be a no-op"
reset_state
bootstrap_migrations_metadata "" "test-fresh" >/dev/null
assert_eq 0 "${DB_STATE[CREATE_CALLS]}"   "_create_metadata_table NOT called"
assert_eq 0 "${DB_STATE[REGISTER_CALLS]}" "_register_baseline_migration NOT called"

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

echo "▶ Case 5: healthy DB — bootstrap is idempotent and does nothing"
reset_state
DB_STATE[HAS_BUSER]=1
DB_STATE[HAS_VERSIONS_TABLE]=1
DB_STATE[HAS_BASELINE_ROW]=1
DB_STATE[VERSION_ROW_COUNT]=3
bootstrap_migrations_metadata "" "test-healthy" >/dev/null
assert_eq 0 "${DB_STATE[CREATE_CALLS]}"   "_create_metadata_table NOT called"
assert_eq 0 "${DB_STATE[REGISTER_CALLS]}" "_register_baseline_migration NOT called"

echo "▶ Case 6: double invocation on a broken DB is safe (second call is a no-op)"
reset_state
DB_STATE[HAS_BUSER]=1
DB_STATE[HAS_VERSIONS_TABLE]=0
bootstrap_migrations_metadata "" "test-double-1" >/dev/null
bootstrap_migrations_metadata "" "test-double-2" >/dev/null
assert_eq 1 "${DB_STATE[CREATE_CALLS]}"   "_create_metadata_table called exactly once across two invocations"
assert_eq 1 "${DB_STATE[REGISTER_CALLS]}" "_register_baseline_migration called exactly once across two invocations"

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
