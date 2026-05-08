#!/bin/bash
set -euo pipefail

echo "🔧 [dev] Checking Composer dependencies..."

if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "📦 [dev] Installing Composer dependencies..."
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts
    chown -R www-data:www-data vendor/ 2>/dev/null || true
    echo "✅ [dev] Composer dependencies installed"
else
    echo "✅ [dev] Composer dependencies already present"
fi
