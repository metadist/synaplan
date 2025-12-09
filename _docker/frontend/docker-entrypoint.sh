#!/bin/bash
set -euo pipefail

echo ":rocket: Starting Synaplan Frontend..."

# Install npm dependencies if node_modules is empty or doesn't exist
if [ ! -d "/app/node_modules" ] || [ -z "$(ls -A /app/node_modules 2>/dev/null)" ]; then
    echo ":wrench: Installing npm dependencies..."
    npm ci
    echo ":white_check_mark: npm dependencies installed"
fi

# Run additional startup scripts from docker-entrypoint.d (if any)
# Useful for dev environments to mount custom initialization scripts
if [ -d "/docker-entrypoint.d" ]; then
    # Check if directory has any files (avoid empty glob expansion)
    if compgen -G "/docker-entrypoint.d/*" > /dev/null; then
        echo ":wrench: Running additional startup scripts from /docker-entrypoint.d/..."
        for f in /docker-entrypoint.d/*; do
            # Additional safety: verify the file is owned by root and not world-writable
            if [ ! -O "$f" ] || [ -k "$f" ]; then
                echo "   :warning:  Skipping unsafe file: $(basename "$f") (not owned by current user or has sticky bit)"
                continue
            fi
            if [ -x "$f" ]; then
                echo "   Executing: $(basename "$f")"
                "$f"
            elif [ -f "$f" ]; then
                echo "   :warning:  Skipping non-executable: $(basename "$f")"
            fi
        done
        echo ":white_check_mark: Additional startup scripts completed"
    fi
fi

# Initialize environment files
/usr/local/bin/init-env.sh

echo ":rocket: Starting development server..."
exec npm run dev -- --host 0.0.0.0