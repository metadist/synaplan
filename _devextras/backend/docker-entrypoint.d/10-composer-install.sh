#!/bin/bash
set -euo pipefail

echo "🔧 [dev] Checking Composer dependencies..."

if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "📦 [dev] Installing Composer dependencies..."
    # Disable Composer's per-process timeout (default 300s). On Apple Silicon the
    # amd64 base image runs under qemu emulation, where unzipping large packages
    # (e.g. google/apiclient-services) easily exceeds 300s and aborts the install,
    # leaving vendor/ incomplete and crash-looping the container. 0 = no timeout.
    COMPOSER_PROCESS_TIMEOUT=0 composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts
    chown -R www-data:www-data vendor/ 2>/dev/null || true
    echo "✅ [dev] Composer dependencies installed"
else
    echo "✅ [dev] Composer dependencies already present"
fi
