#!/bin/bash
set -euo pipefail

echo "🔧 [dev] Generating Zod schemas from OpenAPI..."

# Wait for backend to be ready (max 30 seconds)
BACKEND_READY=false
for i in {1..30}; do
  if curl -s http://backend/api/doc.json > /dev/null 2>&1; then
    echo "✅ Backend is ready"
    BACKEND_READY=true
    break
  fi
  echo "⏳ Waiting for backend... ($i/30)"
  sleep 1
done

if [ "$BACKEND_READY" = false ]; then
  echo "❌ [dev] Backend is not ready after 30 seconds"
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
