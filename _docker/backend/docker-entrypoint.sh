#!/bin/bash
set -euo pipefail

echo "🚀 Starting Synaplan Backend..."

# Initialize environment configuration
/usr/local/bin/init-env.sh

if [ -z "${GROQ_API_KEY:-}" ]; then
cat <<'EOM'
=====================================================================
🚀  GROQ TIP
💥  groq.com has fast, cheap and good models - get your free key there
💥  and put it into backend/.env (GROQ_API_KEY=...) to unlock them!
=====================================================================
EOM
fi

# Update Composer dependencies if composer.json changed (handles bind mounts)
echo "📦 Checking Composer dependencies..."
if [ -f "composer.json" ]; then
    # Check if vendor exists and is writable
    if [ ! -d "vendor" ] || [ ! -w "vendor" ]; then
        echo "⚙️  Installing Composer dependencies..."
        composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts --ignore-platform-req=ext-redis
        chown -R www-data:www-data vendor/ var/ 2>/dev/null || true
    else
        # Quick check if dependencies are up to date
        composer check-platform-reqs --ignore-platform-req=ext-redis > /dev/null 2>&1 || {
            echo "⚙️  Updating Composer dependencies..."
            composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts --ignore-platform-req=ext-redis
        }
    fi
    echo "✅ Composer dependencies ready!"
fi

# Ensure runtime directories exist with correct permissions
echo "📁 Ensuring runtime directories exist..."
mkdir -p var/cache var/log var/uploads public/up
chown -R www-data:www-data var/ public/up/ 2>/dev/null || true
chmod -R 775 var/ public/up/ 2>/dev/null || true

# Generate JWT keys if they don't exist
echo "🔑 Checking JWT keys..."
php bin/console lexik:jwt:generate-keypair --skip-if-exists
echo "✅ JWT keys ready!"

# Wait for database to be ready
echo "⏳ Waiting for database connection..."
until php bin/console dbal:run-sql "SELECT 1" > /dev/null 2>&1; do
    echo "   Database is not ready yet - sleeping..."
    sleep 2
done
echo "✅ Database is ready!"

# Run migrations
echo "🔄 Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || {
    echo "⚠️  Migrations failed, trying schema update..."
    php bin/console doctrine:schema:update --force
}
echo "✅ Database schema ready!"

# Load fixtures on first run (dev/test only)
FIXTURES_MARKER="/var/www/html/var/.fixtures_loaded"

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
if [ -n "$OLLAMA_BASE_URL" ] && [ "$AUTO_DOWNLOAD_MODELS" = "true" ]; then
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
                        printf '\r[Background] [%s] %s' "$MODEL" "$line"
                    done; then
                    printf '\n'
                    echo "[Background] ✅ $MODEL downloaded!"
                else
                    printf '\n'
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
echo "   🌐 API: http://localhost:8000"
echo "   📚 Swagger: http://localhost:8000/api/doc"
echo ""

exec frankenphp php-server --listen 0.0.0.0:80 --root public/
