#!/bin/bash
set -euo pipefail

echo "🚀 Starting Synaplan Frontend..."

# Run additional startup scripts from docker-entrypoint.d (if any)
# Useful for dev environments to mount custom initialization scripts
if [ -d "/docker-entrypoint.d" ]; then
    # Check if directory has any files (avoid empty glob expansion)
    if compgen -G "/docker-entrypoint.d/*" > /dev/null; then
        echo "🔧 Running additional startup scripts from /docker-entrypoint.d/..."
        for f in /docker-entrypoint.d/*; do
            if [ -x "$f" ]; then
                echo "   Executing: $(basename "$f")"
                "$f"
            elif [ -f "$f" ]; then
                echo "   ⚠️  Skipping non-executable: $(basename "$f")"
            fi
        done
        echo "✅ Additional startup scripts completed"
    fi
fi

echo "🚀 Starting development server..."
exec npm run dev -- --host 0.0.0.0
