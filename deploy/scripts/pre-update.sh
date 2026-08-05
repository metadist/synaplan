#!/usr/bin/env bash

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

"$DEPLOY_DIR/scripts/pre-backup.sh"
trap '"$DEPLOY_DIR/scripts/post-backup.sh"' EXIT INT TERM
"$DEPLOY_DIR/scripts/post-backup.sh"
trap - EXIT INT TERM

echo "Backup gate passed; pulling the pinned application image..."
compose pull backend worker scheduler
echo "Pre-update checks completed."
