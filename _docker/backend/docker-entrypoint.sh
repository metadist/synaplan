#!/bin/bash
set -euo pipefail

echo "🚀 Starting Synaplan Backend..."

# Create empty .env file if it doesn't exist
# This is needed for Symfony's Dotenv component to work properly
if [ ! -f /var/www/backend/.env ]; then
    echo "📝 Creating empty .env file..."
    touch /var/www/backend/.env
fi

# Source .env file as defaults (existing env vars from docker-compose take precedence)
if [ -f /var/www/backend/.env ]; then
    echo "📄 Loading defaults from .env file (existing env vars take precedence)..."
    while IFS='=' read -r key value; do
        # Skip comments and empty lines
        [[ -z "$key" || "$key" =~ ^[[:space:]]*# ]] && continue
        # Trim whitespace from key
        key="$(echo "$key" | tr -d '[:space:]')"
        # Skip invalid variable names
        [[ "$key" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]] || continue
        # Only export if not already set in environment
        if [ -z "${!key+x}" ]; then
            # Strip surrounding quotes from value
            value="${value#\"}" ; value="${value%\"}"
            value="${value#\'}" ; value="${value%\'}"
            export "${key}=${value}"
        fi
    done < /var/www/backend/.env
fi

# Validate required environment variables
if [ -z "${APP_URL:-}" ]; then
    echo "❌ ERROR: APP_URL is not set!"
    echo "   APP_URL is required for the application to work correctly."
    echo "   Please set it in your environment or .env file."
    echo "   Example: APP_URL=https://synaplan.example.com"
    exit 1
fi

# Build DATABASE URLs from environment variables if not already set
# Fallback for deployments that provide DB credentials as separate env vars
if [ -z "${DATABASE_WRITE_URL:-}" ] && [ -n "${DB_HOST:-}" ]; then
    # URL-encode the password to handle special characters (using jq)
    DB_PASSWORD_ENCODED=$(printf %s "${DB_PASSWORD:-}" | jq -sRr @uri)
    export DATABASE_WRITE_URL="mysql://${DB_USER:-synaplan}:${DB_PASSWORD_ENCODED}@${DB_HOST}:${DB_PORT:-3306}/${DB_NAME:-synaplan}?serverVersion=${DB_SERVER_VERSION:-11.8}&charset=utf8mb4"
    export DATABASE_READ_URL="${DATABASE_WRITE_URL}"
    echo "✅ Built DATABASE URLs from environment variables"
fi

# Run additional startup scripts from docker-entrypoint.d (if any)
# Useful for dev environments to mount custom initialization scripts
if [ -d "/docker-entrypoint.d" ]; then
    # Check if directory has any files (avoid empty glob expansion)
    if compgen -G "/docker-entrypoint.d/*" > /dev/null; then
        echo "🔧 Running additional startup scripts from /docker-entrypoint.d/..."
        for f in /docker-entrypoint.d/*; do
            # In dev mode, skip ownership check (scripts are mounted from host with host user ownership)
            # In production, verify the file is owned by root and not world-writable
            if [ "${APP_ENV:-prod}" != "dev" ]; then
                if [ ! -O "$f" ] || [ -k "$f" ]; then
                    echo "   ⚠️  Skipping unsafe file: $(basename "$f") (not owned by current user or has sticky bit)"
                    continue
                fi
            fi
            if [ -x "$f" ]; then
                echo "   Executing: $(basename "$f")"
                "$f"
            elif [ -f "$f" ]; then
                echo "   ⚠️  Skipping non-executable: $(basename "$f")"
            fi
        done
        echo "✅ Additional startup scripts completed"
    fi
fi

if [ -z "${GROQ_API_KEY:-}" ]; then
cat <<'EOM'
=====================================================================
🚀  GROQ TIP
💥  groq.com has fast, cheap and good models - get your free key there
💥  and put it into backend/.env (GROQ_API_KEY=...) to unlock them!
=====================================================================
EOM
fi

# Ensure writable directories exist and have correct permissions
# This runs on every startup to handle volume mounts (Mac/Linux compatibility)
mkdir -p /var/www/backend/var/cache /var/www/backend/var/log /var/www/backend/var/uploads /var/www/backend/public/up

# Fix ownership and permissions recursively (works on both Mac and Linux)
# -R flag handles all subdirectories and files, no need for separate find commands
chown -R www-data:www-data /var/www/backend/var /var/www/backend/public/up 2>/dev/null || true
chmod -R 775 /var/www/backend/var /var/www/backend/public/up 2>/dev/null || true

# Regenerate autoloader if plugins directory exists and has content
# This MUST happen BEFORE any php bin/console commands because Symfony bootstrapping
# will try to compile services from plugins. The Docker image is built without /plugins
# (it's mounted at runtime) and --classmap-authoritative prevents Composer from
# scanning for new classes at runtime.
PLUGINS_DIR="${PLUGINS_DIR:-/plugins}"
if [ -d "$PLUGINS_DIR" ] && [ "$(ls -A "$PLUGINS_DIR" 2>/dev/null)" ]; then
    echo "🔌 Plugins detected in $PLUGINS_DIR - regenerating autoloader..."
    # Use --optimize to maintain performance, but without --classmap-authoritative
    # so that plugin classes can be discovered at runtime
    composer dump-autoload --optimize --no-interaction 2>&1 || {
        echo "⚠️  Failed to regenerate autoloader for plugins"
        echo "   This may cause plugin loading failures"
    }
    echo "✅ Autoloader regenerated with plugin support"
fi

# Wait for database to be ready
echo "⏳ Waiting for database connection..."
until php bin/console dbal:run-sql "SELECT 1" 2>&1; do
    echo "   Database is not ready yet - sleeping..."
    sleep 2
done
echo "✅ Database is ready!"

# Run database schema update (until we have proper migrations)
echo "🔄 Running database schema update..."
php bin/console doctrine:schema:update --force
echo "✅ Database schema ready!"

# Load fixtures on first run (dev/test only)
FIXTURES_MARKER="/var/www/backend/var/.fixtures_loaded"

if [ "$APP_ENV" = "dev" ] || [ "$APP_ENV" = "test" ]; then
    # If marker exists but DB is empty (e.g. tmpfs wipe, or marker on host volume after down/up), remove stale marker so we load fixtures
    if [ -f "$FIXTURES_MARKER" ]; then
        _uc=$(php bin/console dbal:run-sql "SELECT COUNT(*) as count FROM BUSER" 2>/dev/null | grep -oE '[0-9]+' | tail -1)
        if [ "${_uc:-0}" -eq 0 ]; then
            rm -f "$FIXTURES_MARKER" 2>/dev/null || true
        fi
    fi

    if [ -f "$FIXTURES_MARKER" ]; then
        echo "✅ Fixtures already loaded (marker present)"
        echo "   👤 Login: admin@synaplan.com / admin123"
        echo "   💡 To reload: rm backend/var/.fixtures_loaded && docker compose restart backend"
    else
        # Check if users actually exist in database (not just marker file)
        # If table doesn't exist, command will fail and we get 0
        USER_COUNT=$(php bin/console dbal:run-sql "SELECT COUNT(*) as count FROM BUSER" 2>/dev/null | grep -oE '[0-9]+' | tail -1)
        USER_COUNT=${USER_COUNT:-0}  # Default to 0 if empty

        if [ "$USER_COUNT" -eq 0 ]; then
            echo "🌱 Loading test data (current users: $USER_COUNT)..."

            # Ensure schema is complete
            echo "   Updating database schema..."
            php bin/console doctrine:schema:update --force --complete || true

            # Load fixtures
            # Note: Not using --purge-with-truncate because TRUNCATE fails with foreign key constraints
            # Even on empty tables, MariaDB/MySQL blocks TRUNCATE if FK constraints exist
            echo "   Loading fixtures..."
            if php bin/console doctrine:fixtures:load --no-interaction 2>&1 | tee /tmp/fixtures.log; then
                if grep -q "loading App" /tmp/fixtures.log; then
                    touch "$FIXTURES_MARKER"
                    echo ""
                    echo "✅ Fixtures loaded successfully!"
                    echo "   👤 Admin: admin@synaplan.com / admin123"
                    echo "   👤 Demo: demo@synaplan.com / demo123"
                    echo "   👤 Test: test@example.com / test123"
                else
                    echo "⚠️  Fixtures might have failed - check logs"
                fi
            else
                echo "❌ Fixtures loading failed!"
                echo "   Please run manually: docker compose exec backend php bin/console doctrine:fixtures:load"
            fi
        else
            touch "$FIXTURES_MARKER"
            echo "✅ Fixtures already loaded ($USER_COUNT users)"
            echo "   👤 Login: admin@synaplan.com / admin123"
            echo "   💡 To reload: rm backend/var/.fixtures_loaded && docker compose restart backend"
        fi
    fi
fi

# Ollama model downloads (optional, only if AUTO_DOWNLOAD_MODELS=true)
if [ -n "${OLLAMA_BASE_URL:-}" ] && [ "${AUTO_DOWNLOAD_MODELS:-false}" = "true" ]; then
    echo ""
    echo "🤖 AUTO_DOWNLOAD_MODELS=true - Starting AI model downloads in background..."

    (
        echo "[Background] Waiting for Ollama service..."
        until curl -f "$OLLAMA_BASE_URL/api/tags" > /dev/null 2>&1; do
            sleep 3
        done
        echo "[Background] ✅ Ollama ready, downloading models..."

        MODELS=("bge-m3")
        if [ "${ENABLE_LOCAL_GPT_OSS:-true}" = "true" ]; then
            MODELS+=("gpt-oss:20b")
        fi
        for MODEL in "${MODELS[@]}"; do
            if ! curl -s "$OLLAMA_BASE_URL/api/tags" | grep -q "\"name\":\"$MODEL\""; then
                echo "[Background] 📥 Downloading $MODEL..."
                if curl -sS -N "$OLLAMA_BASE_URL/api/pull" \
                    -H "Content-Type: application/json" \
                    -d "{\"name\":\"$MODEL\"}" | while IFS= read -r line; do
                        [ -z "$line" ] && continue

                        # Parse JSON fields using sed
                        STATUS=$(echo "$line" | sed -n 's/.*"status":"\([^"]*\)".*/\1/p')
                        COMPLETED=$(echo "$line" | sed -n 's/.*"completed":\([0-9]*\).*/\1/p')
                        TOTAL=$(echo "$line" | sed -n 's/.*"total":\([0-9]*\).*/\1/p')

                        if [ -n "$COMPLETED" ] && [ -n "$TOTAL" ] && [ "$TOTAL" -gt 0 ]; then
                            PERCENT=$((COMPLETED * 100 / TOTAL))
                            MB_COMPLETED=$((COMPLETED / 1048576))
                            MB_TOTAL=$((TOTAL / 1048576))

                            # Only print at 10, 20, 30...90, 100% milestones
                            MILESTONE=$((PERCENT - PERCENT % 10))
                            if [ $PERCENT -gt 0 ] && [ $((PERCENT % 10)) -lt 2 ]; then
                                echo "[Background] [${MODEL}] ${STATUS} - ${MB_COMPLETED}MB/${MB_TOTAL}MB (${MILESTONE}%)"
                            fi
                        elif [ -n "$STATUS" ]; then
                            # Show important status changes
                            case "$STATUS" in
                                "pulling manifest"|"verifying sha256"|"success")
                                    echo "[Background] [${MODEL}] ${STATUS}"
                                    ;;
                            esac
                        fi
                    done; then
                    echo "[Background] ✅ $MODEL downloaded!"
                else
                    echo "[Background] ⚠️  $MODEL download failed"
                fi
            else
                echo "[Background] ✅ $MODEL already available"
            fi
        done
        echo "[Background] 🎉 Model downloads completed!"
    ) &

    echo "✅ Model download started in background"
else
    echo ""
    echo "⏭️  Skipping automatic model downloads"
    echo "   💡 Tip: Use 'AUTO_DOWNLOAD_MODELS=true docker compose up -d'"
    echo "   Models will download automatically when first used"
fi

# Clear and warmup cache
echo "🧹 Clearing cache..."
php bin/console cache:clear
echo "✅ Cache ready!"

# Start FrankenPHP
echo ""
echo "🎉 Backend ready! Starting FrankenPHP..."
echo "   🌐 Frontend: ${APP_URL}"
echo "   🌐 API: ${APP_URL}/api"
echo "   📚 Swagger: ${APP_URL}/api/doc"
echo ""

exec frankenphp run --config /etc/caddy/Caddyfile
