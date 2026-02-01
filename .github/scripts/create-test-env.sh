#!/bin/bash
# Generate .env file for CI
# This script is called from .github/workflows/ci.yml

cat > .env <<ENVEOF
APP_ENV=test
APP_SECRET=test_secret_not_for_production
DATABASE_WRITE_URL=${DATABASE_WRITE_URL}
DATABASE_READ_URL=${DATABASE_READ_URL}
MAILER_DSN=null://null
TOKEN_SECRET=test_token_secret_for_ci
AI_DEFAULT_PROVIDER=test
OLLAMA_BASE_URL=http://localhost:11434
TIKA_BASE_URL=http://localhost:9998
OIDC_CLIENT_ID=test_client_id
OIDC_CLIENT_SECRET=test_client_secret
OIDC_DISCOVERY_URL=https://test.example.com
OPENAI_API_KEY=test-key
ANTHROPIC_API_KEY=test-key
THEHIVE_API_KEY=test-key
LOCK_DSN=flock
ENVEOF

echo "✅ Created .env file for CI"
