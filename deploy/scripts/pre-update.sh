#!/usr/bin/env bash

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

# For the `compose pull` at the end of this script: the backup scripts it calls
# are child processes and export nothing back into this shell.
ensure_deployment_secrets

"$DEPLOY_DIR/scripts/pre-backup.sh"
trap '"$DEPLOY_DIR/scripts/post-backup.sh"' EXIT INT TERM
"$DEPLOY_DIR/scripts/post-backup.sh"
trap - EXIT INT TERM

echo "Backup gate passed; pulling the pinned application image..."
compose pull backend worker scheduler
echo "Pre-update checks completed."
