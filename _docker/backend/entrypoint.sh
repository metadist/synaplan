#!/bin/sh
set -e

# Copy .env.example to .env if missing or empty
# Docker Compose env_file with required:false may create an empty file before the entrypoint runs
if [ ! -f "/var/www/backend/.env" ] || [ ! -s "/var/www/backend/.env" ]; then
    echo "🔧 No .env file found (or empty), copying from .env.example..."
    if [ -f "/var/www/backend/.env.example" ]; then
        cp /var/www/backend/.env.example /var/www/backend/.env
        echo "✅ .env created from .env.example"
    else
        echo "⚠️  Warning: .env.example not found, creating minimal .env..."
        cat > /var/www/backend/.env << 'EOF'
APP_ENV=dev
APP_SECRET=change_me_in_production_12345678901234567890
DATABASE_URL=mysql://synaplan_user:synaplan_password@db:3306/synaplan?serverVersion=11.8&charset=utf8mb4
EOF
        echo "✅ Minimal .env created"
    fi
    # Match .env ownership to the parent directory (host user's UID/GID on bind-mounts)
    chown "$(stat -c %u:%g /var/www/backend)" /var/www/backend/.env
else
    echo "✅ .env file already exists"
fi

# Execute the original command (FrankenPHP)
exec "$@"

