#!/bin/bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_ROOT"

MIN_DOCKER_MAJOR=24

function require_cmd() {
  local cmd="$1"
  local msg="$2"
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "❌ $msg" >&2
    exit 1
  fi
}

require_cmd docker "Docker is required. Install it from https://docs.docker.com/get-docker/ and rerun this script."

DOCKER_VERSION=$(docker version --format '{{.Server.Version}}' 2>/dev/null || true)
if [ -z "$DOCKER_VERSION" ]; then
  DOCKER_VERSION=$(docker --version 2>/dev/null | awk '{print $3}' | tr -d ',')
fi
DOCKER_MAJOR=${DOCKER_VERSION%%.*}
if [ -z "$DOCKER_MAJOR" ]; then
  echo "❌ Unable to detect Docker version. Update Docker and retry." >&2
  exit 1
fi
if [ "$DOCKER_MAJOR" -lt "$MIN_DOCKER_MAJOR" ]; then
  echo "❌ Docker $MIN_DOCKER_MAJOR.x or newer is required (found $DOCKER_VERSION)." >&2
  exit 1
fi

docker compose version >/dev/null 2>&1 || {
  echo "❌ Docker Compose plugin is missing. Update Docker Desktop/Engine to get 'docker compose'." >&2
  exit 1
}

echo "✅ Docker $(docker --version | cut -d' ' -f3 | tr -d ',') detected"
echo "✅ Docker Compose $(docker compose version | head -n1)"

echo ""
echo "================ AI Provider Setup ================"
echo "1) Local Ollama (gpt-oss:20b + bge-m3) - requires ~24GB GPU RAM"
echo "2) Groq Cloud API (recommended, super fast + free tier)"
read -rp "Select provider [1/2, default=2]: " AI_CHOICE
AI_CHOICE=${AI_CHOICE:-2}

USE_GROQ=0
if [ "$AI_CHOICE" != "1" ]; then
  USE_GROQ=1
  echo ""
  echo "Great choice! Grab a free API key at https://console.groq.com/keys"
  while :; do
    read -rp "Enter your GROQ_API_KEY: " GROQ_API_KEY
    if [ -n "$GROQ_API_KEY" ]; then
      break
    fi
    echo "Key cannot be empty. Please try again."
  done
  ENV_FILE="backend/.env"
  if [ ! -f "$ENV_FILE" ]; then
    cp backend/.env.example "$ENV_FILE"
  fi
  if grep -q "^GROQ_API_KEY=" "$ENV_FILE"; then
    sed -i "s/^GROQ_API_KEY=.*/GROQ_API_KEY=$GROQ_API_KEY/" "$ENV_FILE"
  else
    printf "\nGROQ_API_KEY=%s\n" "$GROQ_API_KEY" >> "$ENV_FILE"
  fi
  echo "✅ GROQ_API_KEY stored in $ENV_FILE"
fi

echo ""
echo "📦 Pulling containers (this may take a minute)..."
docker compose pull

if [ "$USE_GROQ" -eq 1 ]; then
  echo "🚀 Starting stack (Groq cloud mode - still downloading bge-m3 locally)..."
  AUTO_DOWNLOAD_MODELS=true ENABLE_LOCAL_GPT_OSS=false docker compose up -d
else
  echo "🚀 Starting stack (Auto-download of gpt-oss:20b and bge-m3 enabled)..."
  AUTO_DOWNLOAD_MODELS=true ENABLE_LOCAL_GPT_OSS=true docker compose up -d
fi

echo ""
if [ "$USE_GROQ" -eq 1 ]; then
  echo "📡 Tracking Ollama model download (bge-m3 for RAG)..."
else
  echo "📡 Tracking Ollama model downloads (gpt-oss:20b + bge-m3)..."
fi
set +e
set +u
set +o pipefail
( docker compose logs -f backend | awk '/\[Background\]/ {printf "\r%s", $0; fflush(); if ($0 ~ /\[Background\] 🎉 Model downloads completed!/) { printf "\n"; exit }}' )
RESULT=$?
set -e
set -u
set -o pipefail
if [ "$RESULT" -ne 0 ]; then
  echo ""
  echo "⚠️ Could not confirm model download completion. Check 'docker compose logs backend'."
else
  echo "✅ Required Ollama models downloaded."
fi

echo ""
echo "⏳ Waiting for backend console availability..."
READY=0
for i in $(seq 1 30); do
  if docker compose exec backend php bin/console about >/dev/null 2>&1; then
    READY=1
    break
  fi
  sleep 2
done

if [ "$READY" -eq 1 ]; then
  echo "🧱 Updating database schema..."
  docker compose exec backend php bin/console doctrine:schema:update --force --complete
  echo "🌱 Loading fixtures..."
  docker compose exec backend php bin/console doctrine:fixtures:load --no-interaction
  if [ "$USE_GROQ" -eq 1 ]; then
    echo "⚙️ Switching defaults to Groq llama-3.3-70b-versatile..."
    docker compose exec backend php bin/console dbal:run-sql "UPDATE BCONFIG SET BVALUE='9' WHERE BGROUP='DEFAULTMODEL' AND BSETTING IN ('CHAT','SORT')"
    docker compose exec backend php bin/console dbal:run-sql "UPDATE BCONFIG SET BVALUE='groq' WHERE BOWNERID=0 AND BGROUP='ai' AND BSETTING='default_chat_provider'"
  fi
else
  echo "⚠️ Backend console did not become ready; please run doctrine:schema:update and fixtures manually."
fi

echo ""
echo "🎉 Setup complete! Logins: admin@synaplan.com / admin123"
echo "👉 Next time, you can simply run 'docker compose up -d'"
echo "🌐 Frontend URL: http://localhost:5173"
