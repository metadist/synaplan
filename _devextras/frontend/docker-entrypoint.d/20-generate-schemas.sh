#!/bin/bash
set -euo pipefail

echo "🔧 [dev] Generating Zod schemas from OpenAPI..."

# Wait for the backend's OpenAPI spec to be served.
#
# In docker-compose.yml the frontend uses `depends_on: backend: service_healthy`
# so the backend is normally up by the time we get here; docker-compose-minimal.yml
# uses a plain `depends_on: - backend` (no health gate), so this script may run
# while the backend is still booting. Either way, the FIRST request to
# /api/doc.json triggers Symfony's OpenAPI scan over every annotated class with
# a cold dev cache, which can take much longer than a plain health probe. We
# therefore poll generously (up to ~5 min) instead of failing after 30s — a
# slow first compile must not leave the frontend without schemas.
#
# `-f` makes curl fail (non-zero exit) on HTTP 4xx/5xx, so a backend that is up
# but still returning errors during the cold compile is treated as "not ready"
# instead of letting us generate schemas against a broken/error spec.
BACKEND_READY=false
MAX_ATTEMPTS=150
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
