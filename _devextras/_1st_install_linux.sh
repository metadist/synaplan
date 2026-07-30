#!/bin/bash
# =============================================================================
# Optional guided installer for a first Synaplan install on Linux/macOS.
#
# It does exactly what the documented manual path does, with prompts:
#   docker compose up -d
#   → http://localhost:5173  (admin@synaplan.com / admin123)
#   → Admin → AI Providers   (/admin/setup)
#
# Keys entered in the UI are tested live, stored encrypted in the database, and
# apply without a restart. A key in backend/.env is imported into that store the
# first time the backend resolves it (the container must be started or
# restarted after the file changes, because Symfony reads .env at boot).
#
# Non-interactive / CI usage (no prompts):
#   AI_PROVIDER=groq GROQ_API_KEY=gsk_... ./_devextras/_1st_install_linux.sh --yes
#   AI_PROVIDER=ollama ./_devextras/_1st_install_linux.sh --yes
# =============================================================================
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

ASSUME_YES=0
for arg in "$@"; do
    case "$arg" in
        -y|--yes) ASSUME_YES=1 ;;
        -h|--help)
            sed -n '2,18p' "${BASH_SOURCE[0]}"
            exit 0
            ;;
        *)
            echo "Unknown option: $arg (try --help)"
            exit 1
            ;;
    esac
done

# No prompts when asked to assume defaults or when stdin is not a terminal
# (CI, `curl | bash`): every question must have an env-var answer.
if [ ! -t 0 ]; then
    ASSUME_YES=1
fi

echo ""
echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║              Synaplan First Installation Helper               ║"
echo "╠═══════════════════════════════════════════════════════════════╣"
echo "║  Equivalent to: docker compose up -d → Admin → AI Providers   ║"
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
AI_PROVIDER="${AI_PROVIDER:-}"
GROQ_API_KEY="${GROQ_API_KEY:-}"

if [ -z "$AI_PROVIDER" ]; then
    if [ "$ASSUME_YES" -eq 1 ]; then
        AI_PROVIDER="groq"
    else
        echo ""
        echo "═══════════════════ AI Provider Setup ═══════════════════"
        echo ""
        echo "  1) Local Ollama (bge-m3 + gpt-oss:20b) — needs ~14GB free GPU/CPU RAM"
        echo "     and downloads ~15GB of model weights"
        echo "  2) Groq Cloud API (recommended — fast, generous free tier)"
        echo ""
        read -rp "Select provider [1/2, default=2]: " AI_CHOICE
        [ "${AI_CHOICE:-2}" = "1" ] && AI_PROVIDER="ollama" || AI_PROVIDER="groq"
    fi
fi

case "$AI_PROVIDER" in
    groq|ollama) ;;
    *)
        echo "❌ AI_PROVIDER must be 'groq' or 'ollama' (got '$AI_PROVIDER')."
        exit 1
        ;;
esac

if [ "$AI_PROVIDER" = "groq" ] && [ -z "$GROQ_API_KEY" ]; then
    if [ "$ASSUME_YES" -eq 1 ]; then
        echo "❌ AI_PROVIDER=groq needs GROQ_API_KEY in the environment when running without prompts."
        echo "   Get a free key at https://console.groq.com/keys"
        exit 1
    fi
    echo ""
    echo "Get a free API key at: https://console.groq.com/keys"
    echo ""
    while :; do
        # -s: the key must not end up in the terminal scrollback or shell history.
        read -rsp "Enter your GROQ_API_KEY (input hidden): " GROQ_API_KEY
        echo ""
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
# The file holds secrets — never leave it world-readable.
chmod 600 "$ENV_FILE"

# Write KEY='VALUE' without passing the value through a `sed` replacement, where
# `/`, `&` and `\` in a key would corrupt the file (or inject other settings).
# Single quotes stop Symfony's Dotenv from expanding a `$` inside the value.
write_env_var() {
    local name="$1" value="$2" tmp
    tmp="$(mktemp "${ENV_FILE}.XXXXXX")"
    chmod 600 "$tmp"
    grep -v -E "^${name}=" "$ENV_FILE" > "$tmp" || true
    printf "%s='%s'\n" "$name" "$value" >> "$tmp"
    mv "$tmp" "$ENV_FILE"
    chmod 600 "$ENV_FILE"
}

if [ -n "$GROQ_API_KEY" ]; then
    # A quote or newline cannot be represented in a .env line — such a value is
    # not a Groq key anyway (they are base64-ish), so refuse instead of writing
    # a broken file.
    if [[ ! "$GROQ_API_KEY" =~ ^[A-Za-z0-9_.:/+=@~-]+$ ]]; then
        echo "❌ That does not look like an API key (unexpected characters)."
        echo "   Start without a key and add it in the UI: Admin → AI Providers."
        exit 1
    fi
    write_env_var "GROQ_API_KEY" "$GROQ_API_KEY"
    echo "✅ GROQ_API_KEY saved to $ENV_FILE (mode 600)"
fi

# =============================================================================
# Start Docker Compose
# =============================================================================
echo ""
echo "═══════════════════ Starting Services ═══════════════════"
echo ""

# No `docker compose down` here: `up -d` recreates exactly the containers whose
# image or environment changed, and tearing down a stack the operator may be
# using is not this script's business.
export AUTO_DOWNLOAD_MODELS=true
if [ "$AI_PROVIDER" = "groq" ]; then
    export ENABLE_LOCAL_GPT_OSS=false
    echo "🚀 Starting with Groq Cloud (downloading bge-m3 for embeddings)..."
else
    export ENABLE_LOCAL_GPT_OSS=true
    echo "🚀 Starting with local Ollama (downloading bge-m3 + gpt-oss:20b, ~15GB)..."
fi

docker compose up -d

if [ "$AI_PROVIDER" = "groq" ]; then
    echo ""
    echo "═══════════════════ Configuring Groq Defaults ═══════════════════"
    echo ""

    # The GROQ_API_KEY from backend/.env is imported into the encrypted
    # provider-key store on first use. Here we only switch the default models to
    # Groq, via the catalog-key based console command (no raw SQL, no hardcoded
    # model IDs). Retrying the command IS the readiness check: it fails while the
    # backend is still booting or the catalogs are not seeded yet.
    APPLIED=0
    echo "⏳ Waiting for the backend to finish its first-run setup..."
    for _ in $(seq 1 45); do
        if docker compose exec -T backend php bin/console app:provider:apply-defaults groq >/dev/null 2>&1; then
            APPLIED=1
            break
        fi
        sleep 2
    done

    if [ "$APPLIED" -eq 1 ]; then
        echo "✅ Groq set as default AI provider"
    else
        echo "⚠️  Could not set the Groq defaults automatically. Once the backend is up, either run:"
        echo "      docker compose exec backend php bin/console app:provider:apply-defaults groq"
        echo "    or open Admin → AI Providers (http://localhost:5173/admin/setup)"
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
