#!/bin/bash
set -euo pipefail

echo "🚀 Starting Synaplan Frontend..."

# ---------------------------------------------------------------------------
# Boot-status page: bind :5173 IMMEDIATELY with a small zero-dependency Node
# server (mounted at /boot-status) that shows a live onboarding page while
# npm ci, schema generation, and the backend boot are still running. Without
# it, http://localhost:5173 is a connection error for minutes on a cold boot.
# The server is stopped right before Vite takes over the port; the page
# detects the handover and reloads into the real app.
# ---------------------------------------------------------------------------
BOOT_PHASE_FILE="${BOOT_PHASE_FILE:-/tmp/synaplan-boot-phase.json}"
export BOOT_PHASE_FILE
BOOT_STATUS_PID=""

write_boot_phase() {
    # $1 phase  $2 detail (optional) — consumed by the boot-status page.
    printf '{"phase":"%s","detail":"%s","updatedAt":"%s"}\n' \
        "$1" "${2:-}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "${BOOT_PHASE_FILE}.tmp" \
        && mv "${BOOT_PHASE_FILE}.tmp" "$BOOT_PHASE_FILE"
}

write_boot_phase "init"

if [ -f /boot-status/server.mjs ]; then
    echo "🕐 Serving boot-status onboarding page on :5173 (until the dev server is ready)..."
    node /boot-status/server.mjs &
    BOOT_STATUS_PID=$!
fi

# Run additional startup scripts from docker-entrypoint.d (if any)
# Useful for dev environments to mount custom initialization scripts
if [ -d "/docker-entrypoint.d" ]; then
    # Check if directory has any files (avoid empty glob expansion)
    if compgen -G "/docker-entrypoint.d/*" > /dev/null; then
        echo "🔧 Running additional startup scripts from /docker-entrypoint.d/..."
        for f in /docker-entrypoint.d/*; do
            if [ -x "$f" ]; then
                echo "   Executing: $(basename "$f")"
                write_boot_phase "script" "$(basename "$f")"
                "$f"
            elif [ -f "$f" ]; then
                echo "   ⚠️  Skipping non-executable: $(basename "$f")"
            fi
        done
        echo "✅ Additional startup scripts completed"
    fi
fi

write_boot_phase "starting-app"

# Hand port 5173 over to Vite. `wait` ensures the port is actually released
# before Vite binds it (otherwise Vite dies with EADDRINUSE).
if [ -n "$BOOT_STATUS_PID" ]; then
    echo "🕐 Stopping boot-status page (handing :5173 over to Vite)..."
    kill "$BOOT_STATUS_PID" 2>/dev/null || true
    wait "$BOOT_STATUS_PID" 2>/dev/null || true
fi

echo "🚀 Starting development server..."
exec npm run dev -- --host 0.0.0.0
