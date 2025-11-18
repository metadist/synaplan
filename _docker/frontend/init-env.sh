#!/bin/bash
set -e

echo "🔧 Checking frontend environment files..."

# Function to create .env from .env.example if it doesn't exist
create_env_from_example() {
    local ENV_FILE=$1
    local EXAMPLE_FILE=$2
    
    if [ ! -f "$ENV_FILE" ]; then
        if [ -f "$EXAMPLE_FILE" ]; then
            echo "📝 Creating $ENV_FILE from $EXAMPLE_FILE..."
            cp "$EXAMPLE_FILE" "$ENV_FILE"
            echo "✅ $ENV_FILE created from example"
        else
            echo "⚠️  Warning: $EXAMPLE_FILE not found, creating default"
            return 1
        fi
    else
        echo "✅ $ENV_FILE already exists"
    fi
    return 0
}

# Frontend .env
FRONTEND_DIR="/app"
FRONTEND_ENV="$FRONTEND_DIR/.env"
FRONTEND_EXAMPLE="$FRONTEND_DIR/.env.example"

# Try to create .env from .env.example if it doesn't exist
if ! create_env_from_example "$FRONTEND_ENV" "$FRONTEND_EXAMPLE"; then
    echo "📝 Creating default .env for frontend..."
    cat > "$FRONTEND_ENV" << 'EOF'
# Frontend Environment Variables

# API Configuration
VITE_API_URL=http://localhost:8000
VITE_WS_URL=ws://localhost:8000

# Environment
VITE_APP_ENV=development

# Feature Flags
VITE_ENABLE_ANALYTICS=false
VITE_ENABLE_DEBUG=true
EOF
    echo "✅ Default frontend/.env created"
fi

echo "✅ Frontend environment check completed!"
