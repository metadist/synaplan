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

echo "🔧 [dev] Checking PHP libraries..."

# The backend source tree (incl. vendor/) is bind-mounted from the host, so its
# files are owned by the host user's UID, not root. Composer runs here as root,
# and packages installed from source carry a .git dir; git 2.35+ then refuses
# any operation on a repo it does not own ("detected dubious ownership"), which
# aborts `composer install` when a package needs updating and crash-loops the
# container. Trust the bind-mounted vendor tree so composer's git operations work.
git config --global --add safe.directory '*' 2>/dev/null || true

if [ -f composer.lock ]; then
    LOCK_HASH=$(md5sum composer.lock | cut -d' ' -f1)
else
    LOCK_HASH="missing"
fi

STORED_HASH=""
if [ -f vendor/.composer_lock_hash ]; then
    STORED_HASH=$(cat vendor/.composer_lock_hash)
fi

# The image installs the libraries and may not have written the sidecar file
# into this volume yet. installed.php records the lock's content-hash, so a
# matching tree is already the install we want — running composer again would
# download every package a second time.
libraries_match_lock() {
    [ -f vendor/autoload.php ] || return 1
    [ -f vendor/composer/installed.php ] || return 1
    [ -f composer.lock ] || return 1
    php -r '
        $installed = include "vendor/composer/installed.php";
        $lock = json_decode((string) file_get_contents("composer.lock"), true);
        $installedHash = is_array($installed) ? (string) ($installed["content-hash"] ?? "") : "";
        $lockHash = is_array($lock) ? (string) ($lock["content-hash"] ?? "") : "";
        exit($installedHash !== "" && $installedHash === $lockHash ? 0 : 1);
    '
}

if [ -f vendor/autoload.php ] && { [ "$LOCK_HASH" = "$STORED_HASH" ] || libraries_match_lock; }; then
    if [ "$LOCK_HASH" != "$STORED_HASH" ]; then
        echo "$LOCK_HASH" > vendor/.composer_lock_hash
    fi
    echo "✅ [dev] PHP libraries are already installed"
else
    if [ -f vendor/autoload.php ]; then
        echo "📦 [dev] composer.lock changed — updating PHP libraries once..."
    else
        echo "📦 [dev] Installing PHP libraries. This runs once on a new checkout..."
    fi
    # Disable Composer's per-process timeout (default 300s). On Apple Silicon the
    # amd64 base image runs under qemu emulation, where unzipping large packages
    # (e.g. google/apiclient-services) easily exceeds 300s and aborts the install,
    # leaving vendor/ incomplete and crash-looping the container. 0 = no timeout.
    COMPOSER_PROCESS_TIMEOUT=0 composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts
    echo "$LOCK_HASH" > vendor/.composer_lock_hash
    chown -R www-data:www-data vendor/ 2>/dev/null || true
    echo "✅ [dev] PHP libraries installed"
fi

# lib/grpc/ is gitignored and the install above skips scripts, so a fresh checkout
# has no Triton stubs and PHPStan reports class.notFound for Inference\*.
if [ ! -d lib/grpc/Inference ]; then
    echo "🔧 [dev] Generating gRPC stubs..."
    mkdir -p lib/grpc
    composer proto:generate || echo "⚠️  [dev] gRPC stub generation failed; run: composer proto:generate"
fi
