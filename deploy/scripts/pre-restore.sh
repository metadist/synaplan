#!/usr/bin/env bash

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

# Stopping the stack is a Compose call as well, and it happens before the platform
# replaces deploy/data — so this reads the secrets file that is still the current
# deployment's.
ensure_deployment_secrets

RESTORE_MARKER="$STATE_DIR/restore-ready"

prepare_data_directories
if [[ -f "$RESTORE_MARKER" && -f "$PAUSED_SERVICES_FILE" ]]; then
    echo "Restore preparation is already complete."
    exit 0
fi

restore_prepare_complete=false
cleanup_failed_prepare() {
    local exit_code=$?
    ((exit_code != 0)) || exit_code=1
    trap - EXIT INT TERM
    if [[ "$restore_prepare_complete" != true ]]; then
        rm -f "$RESTORE_MARKER"
        resume_paused_services || true
    fi
    exit "$exit_code"
}
trap cleanup_failed_prepare EXIT INT TERM

begin_service_pause
pause_services scheduler worker backend ollama centrifugo redis qdrant db
touch "$RESTORE_MARKER"
chmod 0600 "$RESTORE_MARKER"
restore_prepare_complete=true
trap - EXIT INT TERM

echo "Application and stateful services are stopped; deploy/data is ready to be restored."
