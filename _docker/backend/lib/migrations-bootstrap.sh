#!/bin/bash
# Self-healing bootstrap for Doctrine migration metadata.
#
# Sourced by docker-entrypoint.sh before doctrine:migrations:migrate runs.
# Also sourced by _docker/backend/tests/test-migrations-bootstrap.sh to unit-test
# the control flow without touching a real database.
#
# Guarantees on legacy production databases (BUSER exists):
#   - `doctrine_migration_versions` is created if missing.
#   - The baseline migration row is INSERTed if missing — regardless of whether
#     the metadata table was absent, empty, or populated with unrelated rows.
#   - All post-baseline migrations stay unregistered, so the normal migrate path
#     applies them on top of the legacy schema.
#
# On a fresh database (no BUSER) this function is a no-op.

# Baseline = the snapshot migration that captures the legacy production schema.
# Only this version is pre-marked as applied on legacy databases; everything newer
# runs through the normal migrate path.
BASELINE_MIGRATION="${BASELINE_MIGRATION:-DoctrineMigrations\\Version20260417000000}"

# Helper: count rows from a SELECT COUNT(*) statement (handles dbal:run-sql output noise).
# Overridable in tests by redefining after sourcing this file.
_count_sql() {
    local _sql="$1"
    local _env_flag="${2:-}"
    php bin/console dbal:run-sql ${_env_flag} "$_sql" 2>/dev/null | grep -oE '[0-9]+' | tail -1
}

# Pre-create doctrine_migration_versions. We bypass doctrine:migrations:sync-metadata-storage
# because the DBAL MariaDB schema comparator wrongly reports the auto-created table as
# "not up to date" (column-level charset drift on `version`), which then breaks every
# subsequent migrations command.
#
# Charset/collation is aligned with the baseline migration (utf8mb4 + utf8mb4_unicode_ci)
# to avoid collation drift across the database.
_create_metadata_table() {
    local _env_flag="${1:-}"
    php bin/console dbal:run-sql ${_env_flag} \
        "CREATE TABLE IF NOT EXISTS doctrine_migration_versions (
            version VARCHAR(191) NOT NULL,
            executed_at DATETIME DEFAULT NULL,
            execution_time INT(11) DEFAULT NULL,
            PRIMARY KEY(version)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB" \
        >/dev/null 2>&1 || true
}

# Escape backslashes for MySQL string literals.
#
# Why: the baseline version FQN contains a `\` (the PHP namespace separator).
# MySQL treats backslash as an escape character inside single-quoted strings
# (NO_BACKSLASH_ESCAPES is off by default), so `'\V'` is parsed as the unknown
# escape sequence "V" and the backslash is silently stripped. Without doubling
# them up, the stored version becomes `DoctrineMigrationsVersion...` (no
# separator) and Doctrine can no longer match the row to its class — it logs
# "not a registered migration" and happily replays the baseline DDL, which
# blows up on `CREATE TABLE BAPIKEYS` because the table already exists.
_mysql_escape_baseline() {
    # Replace every single backslash with two so MySQL's parser resolves them
    # back to a single `\` when storing / comparing. Use printf rather than
    # echo because some echo implementations interpret backslash sequences.
    printf '%s' "${BASELINE_MIGRATION//\\/\\\\}"
}

# Stripped form of the baseline version that matches what the previous,
# buggy bootstrap actually wrote into `doctrine_migration_versions`
# (backslash eaten by MySQL's escape processing). Used exclusively for
# self-healing that legacy row.
_mysql_legacy_stripped_baseline() {
    printf '%s' "${BASELINE_MIGRATION//\\/}"
}

# Drop every table in the current database.
#
# Used exclusively to recover from a half-applied baseline (see the partial
# detection in bootstrap_migrations_metadata). The whole batch runs as a single
# dbal:run-sql call: pdo_mysql executes the `;`-separated statements as one
# multi-statement, so `SET FOREIGN_KEY_CHECKS=0` stays in effect for the DROP
# and we don't have to know the FK dependency order. The table list is built
# dynamically from information_schema so it never goes stale as the schema
# evolves. 0x60 is a backtick — quoting the identifiers without tangling with
# the surrounding shell/SQL quoting.
#
# Overridable in tests by redefining after sourcing this file.
_drop_all_tables() {
    local _env_flag="${1:-}"
    php bin/console dbal:run-sql ${_env_flag} \
        "SET FOREIGN_KEY_CHECKS=0; SET @tbls = (SELECT GROUP_CONCAT(CONCAT(0x60, table_name, 0x60)) FROM information_schema.tables WHERE table_schema = DATABASE()); SET @sql = IF(@tbls IS NULL, 'DO 0', CONCAT('DROP TABLE ', @tbls)); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s; SET FOREIGN_KEY_CHECKS=1" \
        >/dev/null 2>&1 || true
}

# INSERT IGNORE the baseline migration row. No-op if the row already exists.
#
# Also self-heals databases bootstrapped by the previous release where the
# backslash-less "DoctrineMigrationsVersion..." row was inserted — we drop that
# stray row before (re)inserting the correctly-escaped one, otherwise Doctrine
# keeps warning about an unregistered migration on every subsequent start.
_register_baseline_migration() {
    local _env_flag="${1:-}"
    local _escaped
    local _legacy
    _escaped=$(_mysql_escape_baseline)
    _legacy=$(_mysql_legacy_stripped_baseline)

    # Only delete when the legacy form is actually different from the
    # correct one (guards against a future BASELINE_MIGRATION without any
    # backslashes deleting the very row we are about to insert).
    if [ "$_escaped" != "$_legacy" ]; then
        php bin/console dbal:run-sql ${_env_flag} \
            "DELETE FROM doctrine_migration_versions WHERE version = '${_legacy}'" \
            >/dev/null 2>&1 || true
    fi

    php bin/console dbal:run-sql ${_env_flag} \
        "INSERT IGNORE INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES ('${_escaped}', NOW(), 0)" \
        >/dev/null 2>&1 || true
}

# Main entrypoint for the bootstrap. Call before doctrine:migrations:migrate.
#
# Args:
#   $1 - optional Symfony env flag (e.g. "--env=test"); pass "" for the main DB.
#   $2 - optional human label for log output (e.g. "main" / "test").
bootstrap_migrations_metadata() {
    local _env_flag="${1:-}"
    local _label="${2:-database}"
    local _has_versions
    local _has_buser
    local _has_baseline=0

    _has_buser=$(_count_sql \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'BUSER'" \
        "$_env_flag")

    if [ "${_has_buser:-0}" -eq 0 ]; then
        # Before treating a BUSER-less database as fresh, guard against a
        # half-applied baseline. The baseline migration (Version20260417000000)
        # is non-transactional — MariaDB can't roll back DDL — and creates
        # BAPIKEYS as its FIRST statement but BUSER only near the END. If that
        # first migrate run dies in between (transient DB drop, OOM/kill, ^C,
        # an unsupported column type, ...) the early tables persist while
        # Doctrine never records the version row. On the next start the naive
        # "no BUSER => fresh" check would let migrate replay the baseline, which
        # then crashes on `CREATE TABLE BAPIKEYS ... already exists` — and with
        # `restart: unless-stopped` that becomes an infinite crash loop.
        #
        # "BAPIKEYS present but BUSER absent" is an unambiguous fingerprint of
        # this broken state on an otherwise-fresh DB: no BUSER means no user
        # data to lose, so we recover by dropping every orphan table and letting
        # the migrate below rebuild the schema from scratch.
        local _has_partial_baseline
        _has_partial_baseline=$(_count_sql \
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'BAPIKEYS'" \
            "$_env_flag")
        if [ "${_has_partial_baseline:-0}" -gt 0 ]; then
            echo "⚠️  [$_label] Half-applied baseline detected (BAPIKEYS exists but BUSER does not) — dropping orphan tables so migrations can re-run cleanly"
            _drop_all_tables "$_env_flag"
        fi

        # Fresh (or just-cleaned) database: let doctrine:migrations:migrate
        # create the full schema.
        return 0
    fi

    _has_versions=$(_count_sql \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'doctrine_migration_versions'" \
        "$_env_flag")

    # Legacy schema present. Ensure the metadata table exists before checking its contents.
    if [ "${_has_versions:-0}" -eq 0 ]; then
        echo "📌 [$_label] Existing schema detected without migration metadata — creating doctrine_migration_versions"
        _create_metadata_table "$_env_flag"
        # NOTE: `_has_baseline` stays at its init value 0 here. The table was
        # just created and is empty by definition, so we intentionally fall
        # through to `_register_baseline_migration` below.
    else
        # Same `\\` dance as in _register_baseline_migration: compare against
        # the escaped form so MySQL resolves the literal back to a single
        # backslash and matches the correctly-stored class FQN.
        local _escaped_baseline
        local _legacy_baseline
        _escaped_baseline=$(_mysql_escape_baseline)
        _legacy_baseline=$(_mysql_legacy_stripped_baseline)
        _has_baseline=$(_count_sql \
            "SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = '${_escaped_baseline}'" \
            "$_env_flag")
        # Detect the legacy buggy row (no backslash). If only that form is
        # present, force a re-register so the self-healing DELETE+INSERT path
        # runs and replaces it with the correctly-escaped version.
        if [ "${_has_baseline:-0}" -eq 0 ] && [ "$_escaped_baseline" != "$_legacy_baseline" ]; then
            local _has_legacy
            _has_legacy=$(_count_sql \
                "SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = '${_legacy_baseline}'" \
                "$_env_flag")
            if [ "${_has_legacy:-0}" -gt 0 ]; then
                echo "📌 [$_label] Detected legacy baseline row with stripped namespace separator — will rewrite"
            fi
        fi
    fi

    # Register the baseline whenever the legacy schema exists but the baseline row is
    # missing. Covers all three broken states (missing table / empty table / table with
    # unrelated rows). INSERT IGNORE is a no-op if the row is already there.
    if [ "${_has_baseline:-0}" -eq 0 ]; then
        echo "📌 [$_label] Baseline (${BASELINE_MIGRATION}) not registered but schema exists — marking as applied so its DDL is not replayed"
        _register_baseline_migration "$_env_flag"

        # When the metadata table was just created (no prior migration history),
        # the schema was set up via doctrine:schema:update --force (dev entrypoint)
        # and already reflects the FINAL state — all incremental migrations are
        # already applied at the DDL level. Mark every available migration as
        # executed so doctrine:migrations:migrate doesn't replay them.
        if [ "${_has_versions:-0}" -eq 0 ]; then
            echo "📌 [$_label] Fresh schema detected — marking all migrations as applied"
            php bin/console doctrine:migrations:version --add --all --no-interaction ${_env_flag} \
                >/dev/null 2>&1 || true
        fi
    fi
}

# Run doctrine:migrations:migrate once. Isolated in its own function so the
# retry wrapper below stays pure control-flow and the bash test suite can
# override it to simulate transient failures without a real database.
#
# Returns the migrate command's own exit status.
_run_doctrine_migrate() {
    local _env_flag="${1:-}"
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration ${_env_flag}
}

# Apply migrations with bounded retries, re-running the idempotent metadata
# bootstrap before EACH attempt. This is what makes a failed first start
# self-healing *within a single container start* rather than relying on the
# Docker restart loop:
#
#   - Transient failures (DB still warming up behind the SELECT 1 probe, lock
#     contention, deadlocks, two app instances racing the same migration) get
#     a few more chances instead of crash-exiting the container immediately.
#   - A crash mid-baseline leaves "BAPIKEYS without BUSER"; because the
#     bootstrap re-runs before the next attempt it drops the orphan tables and
#     the retry rebuilds the schema cleanly — no operator action, no restart.
#
# Tunables (env): MIGRATION_MAX_ATTEMPTS (default 5),
#                 MIGRATION_RETRY_DELAY_SECONDS (default 5).
#
# Returns 0 once migrations apply cleanly, 1 after exhausting all attempts.
#
# Args:
#   $1 - optional Symfony env flag (e.g. "--env=test"); pass "" for the main DB.
#   $2 - optional human label for log output (e.g. "main" / "test").
run_migrations_with_retry() {
    local _env_flag="${1:-}"
    local _label="${2:-database}"
    local _max_attempts="${MIGRATION_MAX_ATTEMPTS:-5}"
    local _delay="${MIGRATION_RETRY_DELAY_SECONDS:-5}"
    local _attempt=1

    while :; do
        bootstrap_migrations_metadata "$_env_flag" "$_label"

        if _run_doctrine_migrate "$_env_flag"; then
            return 0
        fi

        if [ "$_attempt" -ge "$_max_attempts" ]; then
            echo "❌ [$_label] Migrations still failing after ${_attempt} attempt(s) — giving up"
            return 1
        fi

        echo "⚠️  [$_label] Migration attempt ${_attempt}/${_max_attempts} failed — re-checking metadata and retrying in ${_delay}s..."
        sleep "$_delay"
        _attempt=$((_attempt + 1))
    done
}
