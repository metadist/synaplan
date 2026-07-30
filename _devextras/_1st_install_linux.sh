#!/bin/bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_ROOT"

echo ""
echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║           🚀 Synaplan First Installation                      ║"
echo "╚═══════════════════════════════════════════════════════════════╝"
echo ""

# =============================================================================
# Check Docker
# =============================================================================
MIN_DOCKER_MAJOR=24

if ! command -v docker >/dev/null 2>&1; then
    echo "❌ Docker is required. Install it from https://docs.docker.com/get-docker/"
    exit 1
fi

DOCKER_VERSION=$(docker version --format '{{.Server.Version}}' 2>/dev/null || docker --version 2>/dev/null | awk '{print $3}' | tr -d ',')
DOCKER_MAJOR=${DOCKER_VERSION%%.*}

if [ -z "$DOCKER_MAJOR" ] || [ "$DOCKER_MAJOR" -lt "$MIN_DOCKER_MAJOR" ]; then
    echo "❌ Docker $MIN_DOCKER_MAJOR.x or newer is required (found ${DOCKER_VERSION:-unknown})."
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "❌ Docker Compose plugin is missing. Update Docker to get 'docker compose'."
    exit 1
fi

echo "✅ Docker $(docker --version | cut -d' ' -f3 | tr -d ',')"

# =============================================================================
# AI Provider Setup
# =============================================================================
echo ""
echo "═══════════════════ AI Provider Setup ═══════════════════"
echo ""
echo "  1) Local Ollama (bge-m3 + gpt-oss:20b) - needs ~24GB GPU RAM"
echo "  2) Groq Cloud API (recommended - fast & free tier)"
echo ""
read -rp "Select provider [1/2, default=2]: " AI_CHOICE
AI_CHOICE=${AI_CHOICE:-2}

USE_GROQ=0
GROQ_API_KEY=""
if [ "$AI_CHOICE" != "1" ]; then
    USE_GROQ=1
    echo ""
    echo "Great! Get a free API key at: https://console.groq.com/keys"
    echo ""
    while :; do
        read -rp "Enter your GROQ_API_KEY: " GROQ_API_KEY
        [ -n "$GROQ_API_KEY" ] && break
        echo "Key cannot be empty."
    done
fi

# =============================================================================
# Create backend/.env if needed
# =============================================================================
ENV_FILE="backend/.env"
if [ ! -f "$ENV_FILE" ]; then
    cp backend/.env.example "$ENV_FILE" 2>/dev/null || touch "$ENV_FILE"
fi

# Set GROQ_API_KEY if provided
if [ -n "$GROQ_API_KEY" ]; then
    if grep -q "^GROQ_API_KEY=" "$ENV_FILE" 2>/dev/null; then
        if [[ "$OSTYPE" == "darwin"* ]]; then
            sed -i '' "s/^GROQ_API_KEY=.*/GROQ_API_KEY=$GROQ_API_KEY/" "$ENV_FILE"
        else
            sed -i "s/^GROQ_API_KEY=.*/GROQ_API_KEY=$GROQ_API_KEY/" "$ENV_FILE"
        fi
    else
        echo "GROQ_API_KEY=$GROQ_API_KEY" >> "$ENV_FILE"
    fi
    echo "✅ GROQ_API_KEY saved to $ENV_FILE"
fi

# =============================================================================
# Start Docker Compose
# =============================================================================
echo ""
echo "═══════════════════ Starting Services ═══════════════════"
echo ""

# Stop any existing containers
docker compose down 2>/dev/null || true

# Set environment for this run
export AUTO_DOWNLOAD_MODELS=true
if [ "$USE_GROQ" -eq 1 ]; then
    export ENABLE_LOCAL_GPT_OSS=false
    echo "🚀 Starting with Groq Cloud (downloading bge-m3 for embeddings)..."
else
    export ENABLE_LOCAL_GPT_OSS=true
    echo "🚀 Starting with local Ollama (downloading bge-m3 + gpt-oss:20b)..."
fi

docker compose up -d

if [ "$USE_GROQ" -eq 1 ]; then
    echo ""
    echo "═══════════════════ Configuring Groq Defaults ═══════════════════"
    echo ""

    # The GROQ_API_KEY from backend/.env is imported into the encrypted
    # provider-key store automatically on first use. Here we only switch the
    # default models to Groq via the catalog-key based console command (no raw
    # SQL, no hardcoded model IDs).
    READY=0
    echo "⏳ Waiting for backend console availability..."
    for _ in {1..30}; do
        if docker compose exec backend php bin/console about >/dev/null 2>&1; then
            READY=1
            break
        fi
        sleep 2
    done

    if [ "$READY" -eq 1 ]; then
        # Wait for seed data (BCONFIG rows) so the defaults command has a base to update
        echo "⏳ Waiting for database seed data..."
        FIXTURES_READY=0
        for _ in {1..30}; do
            BCONFIG_COUNT=$(docker compose exec -T backend php bin/console dbal:run-sql "SELECT COUNT(*) as count FROM BCONFIG" 2>/dev/null | grep -oE '[0-9]+' | tail -1 || echo "0")
            if [ "${BCONFIG_COUNT:-0}" -gt "0" ]; then
                FIXTURES_READY=1
                break
            fi
            sleep 2
        done

        if [ "$FIXTURES_READY" -eq 1 ]; then
            echo "⚙️ Switching default models to Groq..."
            if docker compose exec -T backend php bin/console app:provider:apply-defaults groq; then
                echo "✅ Groq set as default AI provider"
            else
                echo "⚠️ Failed to apply Groq defaults — you can do it in the UI under Admin → AI Providers"
            fi
        else
            echo "⚠️ Database not seeded yet; once the backend is up, either run:"
            echo "  docker compose exec backend php bin/console app:provider:apply-defaults groq"
            echo "  or open Admin → AI Providers in the UI (http://localhost:5173/admin/setup)"
        fi
    else
        echo "⚠️ Backend console did not become ready; once it is, either run:"
        echo "  docker compose exec backend php bin/console app:provider:apply-defaults groq"
        echo "  or open Admin → AI Providers in the UI (http://localhost:5173/admin/setup)"
    fi
fi

# =============================================================================
# Done!
# =============================================================================
echo ""
echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║  ✅ Setup complete!                                           ║"
echo "╠═══════════════════════════════════════════════════════════════╣"
echo "║                                                               ║"
echo "║  🌐 Frontend: http://localhost:5173                           ║"
echo "║  🔧 Backend:  http://localhost:8000                           ║"
echo "║                                                               ║"
echo "║  👤 Login:    admin@synaplan.com / admin123                   ║"
echo "║                                                               ║"
echo "║  🔑 Connect AI providers: Admin → AI Providers                ║"
echo "║     http://localhost:5173/admin/setup                        ║"
echo "║                                                               ║"
echo "║  ⏳ First startup takes ~1-2 minutes for database setup       ║"
echo "║     Watch progress: docker compose logs -f backend            ║"
echo "║                                                               ║"
echo "╚═══════════════════════════════════════════════════════════════╝"
echo ""
