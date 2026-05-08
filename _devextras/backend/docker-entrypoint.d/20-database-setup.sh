#!/bin/bash
set -euo pipefail

# Dev database setup — intentionally minimal.
#
# Schema creation, migrations, fixtures, and seeding are all handled by the
# main docker-entrypoint.sh (in the correct order: migrations → fixtures →
# app:seed). This script only verifies the database connection is reachable
# early so the dev gets a clear error before the heavier startup steps run.
#
# IMPORTANT: Do NOT run doctrine:schema:update --force here. The schema must
# be managed exclusively by Doctrine Migrations. Running schema:update before
# migrations creates all tables in their final state, which then causes
# incremental migrations to fail with duplicate index/table errors and puts
# the backend into a crash loop. See PR #877.

echo "🔧 [dev] Checking database connection..."

max_attempts=30
attempt=0
while ! php bin/console dbal:run-sql "SELECT 1" > /dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ $attempt -ge $max_attempts ]; then
        echo "❌ [dev] Database connection timeout after ${max_attempts} attempts"
        exit 1
    fi
    echo "⏳ [dev] Waiting for database connection (attempt $attempt/$max_attempts)..."
    sleep 2
done

echo "✅ [dev] Database connection established"
