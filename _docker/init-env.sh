#!/bin/bash
set -e

echo "🔧 Checking environment files..."

# Backend .env.docker
BACKEND_ENV="/var/www/backend/.env.docker"
if [ ! -f "$BACKEND_ENV" ]; then
    echo "📝 Creating backend/.env.docker..."
    cat > "$BACKEND_ENV" << 'EOF'
###> symfony/framework-bundle ###
APP_ENV=dev
APP_SECRET=change_me_in_production_12345678901234567890
###< symfony/framework-bundle ###

###> doctrine/doctrine-bundle ###
DATABASE_WRITE_URL=mysql://synaplan_user:synaplan_password@db:3306/synaplan?serverVersion=11.8&charset=utf8mb4
DATABASE_READ_URL=mysql://synaplan_user:synaplan_password@db:3306/synaplan?serverVersion=11.8&charset=utf8mb4
###< doctrine/doctrine-bundle ###

###> symfony/messenger ###
MESSENGER_TRANSPORT_DSN=redis://redis:6379/messages
###< symfony/messenger ###

###> redis ###
REDIS_URL=redis://redis:6379
###< redis ###

###> AI Providers ###
OLLAMA_BASE_URL=http://ollama:11434
TIKA_BASE_URL=http://tika:9998
AI_DEFAULT_PROVIDER=ollama
###< AI Providers ###

###> External AI API Keys (optional) ###
ANTHROPIC_API_KEY=
OPENAI_API_KEY=
GROQ_API_KEY=
GOOGLE_GEMINI_API_KEY=
###< External AI API Keys ###

###> lexik/jwt-authentication-bundle ###
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=change_me_in_production
###< lexik/jwt-authentication-bundle ###
EOF
    echo "✅ backend/.env.docker created"
else
    echo "✅ backend/.env.docker already exists"
fi

# Frontend .env.docker (will be created in mounted frontend directory)
FRONTEND_ENV="/app/.env.docker"
echo "📝 Frontend .env.docker will be created by frontend container..."

echo "✅ Environment check completed!"
EOF

