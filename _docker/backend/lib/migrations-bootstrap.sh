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

# INSERT IGNORE the baseline migration row. No-op if the row already exists.
_register_baseline_migration() {
    local _env_flag="${1:-}"
    php bin/console dbal:run-sql ${_env_flag} \
        "INSERT IGNORE INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES ('${BASELINE_MIGRATION}', NOW(), 0)" \
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

    # Fresh database: let doctrine:migrations:migrate create the full schema.
    if [ "${_has_buser:-0}" -eq 0 ]; then
        return 0
    fi

    _has_versions=$(_count_sql \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'doctrine_migration_versions'" \
        "$_env_flag")

    # Legacy schema present. Ensure the metadata table exists before checking its contents.
    if [ "${_has_versions:-0}" -eq 0 ]; then
        echo "📌 [$_label] Existing schema detected without migration metadata — creating doctrine_migration_versions"
        _create_metadata_table "$_env_flag"
    else
        _has_baseline=$(_count_sql \
            "SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = '${BASELINE_MIGRATION}'" \
            "$_env_flag")
    fi

    # Register the baseline whenever the legacy schema exists but the baseline row is
    # missing. Covers all three broken states (missing table / empty table / table with
    # unrelated rows). INSERT IGNORE is a no-op if the row is already there.
    if [ "${_has_baseline:-0}" -eq 0 ]; then
        echo "📌 [$_label] Baseline (${BASELINE_MIGRATION}) not registered but schema exists — marking as applied so its DDL is not replayed"
        _register_baseline_migration "$_env_flag"
    fi
}
