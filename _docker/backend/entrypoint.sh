#!/bin/sh
set -e

# Copy .env.example to .env if .env doesn't exist
if [ ! -f "/var/www/backend/.env" ]; then
    echo "🔧 No .env file found, copying from .env.example..."
    if [ -f "/var/www/backend/.env.example" ]; then
        cp /var/www/backend/.env.example /var/www/backend/.env
        echo "✅ .env created from .env.example"
    else
        echo "⚠️  Warning: .env.example not found, creating minimal .env..."
        cat > /var/www/backend/.env << 'EOF'
APP_ENV=dev
APP_SECRET=change_me_in_production_12345678901234567890
DATABASE_URL=mysql://synaplan_user:synaplan_password@db:3306/synaplan?serverVersion=11.8&charset=utf8mb4
MESSENGER_TRANSPORT_DSN=doctrine://default
EOF
        echo "✅ Minimal .env created"
    fi
else
    echo "✅ .env file already exists"
fi

# Execute the original command (FrankenPHP)
exec "$@"

