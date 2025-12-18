#!/bin/bash
set -euo pipefail

echo "🚀 Starting Synaplan Backend..."

# Source .env file if it exists
if [ -f /var/www/backend/.env ]; then
    echo "📄 Loading environment from .env file..."
    set -a  # automatically export all variables
    source /var/www/backend/.env
    set +a
fi

# ─────────────────────────────────────────────────────────────
# Frontend Runtime Configuration Injection
# Replaces __VITE_*__ placeholders in index.html with actual env values
# ─────────────────────────────────────────────────────────────
FRONTEND_INDEX="/var/www/frontend/index.html"
FRONTEND_ENV="/var/www/frontend/.env"

if [ -f "$FRONTEND_INDEX" ]; then
    # Load frontend env vars if .fe-env is mounted
    if [ -f "$FRONTEND_ENV" ]; then
        echo "📄 Loading frontend environment from $FRONTEND_ENV..."
        set -a
        source "$FRONTEND_ENV"
        set +a
    fi

    # Check if index.html has placeholders to replace
    if grep -q "__VITE_" "$FRONTEND_INDEX"; then
        echo "🔧 Injecting frontend runtime configuration..."
        
        # Replace reCAPTCHA placeholders
        RECAPTCHA_ENABLED="${VITE_RECAPTCHA_ENABLED:-false}"
        RECAPTCHA_SITE_KEY="${VITE_RECAPTCHA_SITE_KEY:-}"
        
        sed -i "s|__VITE_RECAPTCHA_ENABLED__|${RECAPTCHA_ENABLED}|g" "$FRONTEND_INDEX"
        sed -i "s|__VITE_RECAPTCHA_SITE_KEY__|${RECAPTCHA_SITE_KEY}|g" "$FRONTEND_INDEX"
        
        echo "   ✅ reCAPTCHA enabled: ${RECAPTCHA_ENABLED}"
        [ -n "$RECAPTCHA_SITE_KEY" ] && echo "   ✅ reCAPTCHA site key: ${RECAPTCHA_SITE_KEY:0:20}..."
    fi
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

# Ensure upload directory is available before permissions are fixed
UPLOAD_DIR="/var/www/backend/var/uploads"
if [ ! -d "$UPLOAD_DIR" ]; then
    mkdir -p "$UPLOAD_DIR"
fi

# Ensure proper permissions
chown -R www-data:www-data var/ public/up/ 2>/dev/null || true
chmod -R 775 var/ public/up/ 2>/dev/null || true

# Wait for database to be ready
echo "⏳ Waiting for database connection..."
until php bin/console dbal:run-sql "SELECT 1" > /dev/null 2>&1; do
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
