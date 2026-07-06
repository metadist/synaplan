#!/bin/bash
set -euo pipefail

# Auto-install Composer dependencies when composer.lock changes.
#
# The backend source tree is bind-mounted from the host, so vendor/ persists
# across container restarts. A simple "does vendor/ exist?" check misses the
# case where someone pulls new code with updated composer.lock — the old
# vendor/ is still there but stale, and missing packages (e.g. predis/predis)
# cause silent runtime errors.
#
# We store an md5 fingerprint of composer.lock inside vendor/ after every
# successful install. On the next boot we compare: if the fingerprint differs
# (or is missing), we re-run `composer install`. When nothing changed, the
# check is a single md5sum + file read — effectively free.

echo "🔧 [dev] Checking Composer dependencies..."

if [ -f composer.lock ]; then
    LOCK_HASH=$(md5sum composer.lock | cut -d' ' -f1)
else
    LOCK_HASH="missing"
fi

STORED_HASH=""
if [ -f vendor/.composer_lock_hash ]; then
    STORED_HASH=$(cat vendor/.composer_lock_hash)
fi

if [ ! -d vendor ] || [ ! -f vendor/autoload.php ] || [ "$LOCK_HASH" != "$STORED_HASH" ]; then
    if [ -f vendor/autoload.php ] && [ "$LOCK_HASH" != "$STORED_HASH" ]; then
        echo "📦 [dev] composer.lock changed — updating dependencies..."
    else
        echo "📦 [dev] Installing Composer dependencies..."
    fi
    # Disable Composer's per-process timeout (default 300s). On Apple Silicon the
    # amd64 base image runs under qemu emulation, where unzipping large packages
    # (e.g. google/apiclient-services) easily exceeds 300s and aborts the install,
    # leaving vendor/ incomplete and crash-looping the container. 0 = no timeout.
    COMPOSER_PROCESS_TIMEOUT=0 composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts
    echo "$LOCK_HASH" > vendor/.composer_lock_hash
    chown -R www-data:www-data vendor/ 2>/dev/null || true
    echo "✅ [dev] Composer dependencies installed"
else
    echo "✅ [dev] Composer dependencies up to date"
fi
