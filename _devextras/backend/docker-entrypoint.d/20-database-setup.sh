#!/bin/bash
set -euo pipefail

echo "🔧 [dev] Checking database schema..."

# Wait for database to be ready
max_attempts=30
attempt=0
while ! php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ $attempt -ge $max_attempts ]; then
        echo "❌ [dev] Database connection timeout after ${max_attempts} attempts"
        exit 1
    fi
    echo "⏳ [dev] Waiting for database connection (attempt $attempt/$max_attempts)..."
    sleep 2
done

echo "✅ [dev] Database connection established"

# Check if schema needs to be created/updated
if php bin/console doctrine:schema:update --dump-sql 2>&1 | grep -q "Nothing to update"; then
    echo "✅ [dev] Database schema is up to date"
else
    echo "📦 [dev] Updating database schema..."
    php bin/console doctrine:schema:update --force
    echo "✅ [dev] Database schema updated"
fi

# Check if fixtures need to be loaded (check if users table is empty)
user_count=$(php bin/console doctrine:query:sql "SELECT COUNT(*) as count FROM BUSER" --quiet 2>/dev/null | grep -oE '[0-9]+' | head -1 || echo "0")

if [ "$user_count" = "0" ]; then
    echo "📦 [dev] Loading database fixtures (test users, config, etc.)..."
    php bin/console doctrine:fixtures:load --no-interaction
    echo "✅ [dev] Database fixtures loaded"
else
    echo "✅ [dev] Database already contains data (${user_count} users)"
fi
