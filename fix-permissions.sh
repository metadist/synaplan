#!/bin/bash
# Fix file permissions after Docker operations
# This script fixes ownership issues when Docker containers create files as root
# Especially needed for Playwright e2e tests which require node_modules on host

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Get current user and group
CURRENT_USER="${SUDO_USER:-$USER}"
CURRENT_GROUP=$(id -gn "$CURRENT_USER")

echo "🔧 Fixing file permissions..."
echo "   User: $CURRENT_USER"
echo "   Group: $CURRENT_GROUP"
echo ""

# Fix frontend node_modules (needed for Playwright e2e tests)
if [ -d "frontend/node_modules" ]; then
    echo "📦 Fixing frontend/node_modules..."
    sudo chown -R "$CURRENT_USER:$CURRENT_GROUP" frontend/node_modules 2>/dev/null || {
        echo "⚠️  Could not fix frontend/node_modules (may need sudo)"
        echo "   Run: sudo chown -R $CURRENT_USER:$CURRENT_GROUP frontend/node_modules"
        exit 1
    }
    echo "   ✅ Fixed frontend/node_modules"
fi

# Fix Playwright browser cache (if exists)
if [ -d "$HOME/.cache/ms-playwright" ]; then
    echo "🌐 Fixing Playwright browser cache..."
    sudo chown -R "$CURRENT_USER:$CURRENT_GROUP" "$HOME/.cache/ms-playwright" 2>/dev/null || true
fi

# Fix backend vendor (if exists on host)
if [ -d "backend/vendor" ]; then
    echo "📦 Fixing backend/vendor..."
    sudo chown -R "$CURRENT_USER:$CURRENT_GROUP" backend/vendor 2>/dev/null || {
        echo "⚠️  Could not fix backend/vendor (may need sudo)"
    }
fi

# Fix any other common Docker-created directories
for dir in frontend/dist frontend/dist-widget backend/var; do
    if [ -d "$dir" ]; then
        echo "📁 Fixing $dir..."
        sudo chown -R "$CURRENT_USER:$CURRENT_GROUP" "$dir" 2>/dev/null || true
    fi
done

echo ""
echo "✅ Permissions fixed!"
echo ""
echo "💡 Tips:"
echo "   - For normal development: Use 'make -C frontend deps' (runs in container)"
echo "   - For Playwright e2e tests: Use 'make -C frontend deps-host' then run this script"
echo "   - After Docker operations: Run this script to fix permissions"
