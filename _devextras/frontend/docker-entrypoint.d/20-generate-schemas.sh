#!/bin/bash
set -euo pipefail

echo "🔧 [dev] Generating Zod schemas from OpenAPI..."

# Wait for the backend's OpenAPI spec to be served.
#
# The frontend container deliberately has NO depends_on on the backend: it must
# start immediately so the boot-status onboarding page can bind :5173 while the
# backend image is still building/booting. That means this script can run very
# early — on a cold first boot the backend may still be minutes away (image
# build + composer install + migrations). Additionally, the FIRST request to
# /api/doc.json triggers Symfony's OpenAPI scan over every annotated class with
# a cold dev cache, which takes much longer than a plain health probe. We
# therefore poll generously (up to ~15 min) instead of failing early — a slow
# first boot must not leave the frontend without schemas. If the ceiling is
# ever hit, the container exits and `restart: unless-stopped` retries the whole
# entrypoint, so the stack still self-heals.
#
# `-f` makes curl fail (non-zero exit) on HTTP 4xx/5xx, so a backend that is up
# but still returning errors during the cold compile is treated as "not ready"
# instead of letting us generate schemas against a broken/error spec.
BACKEND_READY=false
MAX_ATTEMPTS=450
for i in $(seq 1 "$MAX_ATTEMPTS"); do
  if curl -fs --max-time 10 http://backend/api/doc.json > /dev/null 2>&1; then
    echo "✅ Backend is ready"
    BACKEND_READY=true
    break
  fi
  echo "⏳ Waiting for backend OpenAPI spec... ($i/$MAX_ATTEMPTS)"
  sleep 2
done

if [ "$BACKEND_READY" = false ]; then
  echo "❌ [dev] Backend OpenAPI spec not reachable after ~5 minutes"
  echo "    Schema generation failed - frontend will not work without schemas"
  echo "    Please ensure backend is running and try: make generate-schemas"
  exit 1
fi

# Ensure generated directory exists
mkdir -p src/generated

# Generate schemas
if npm run generate:schemas; then
  echo "✅ [dev] Zod schemas generated successfully"
else
  echo "❌ [dev] Failed to generate schemas"
  echo "    Frontend will not work without schemas"
  echo "    Please fix the issue and try: make generate-schemas"
  exit 1
fi
