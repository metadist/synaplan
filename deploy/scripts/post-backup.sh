#!/usr/bin/env bash

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

ACTIVE_BACKUP_FILE="$STATE_DIR/active-backup"

resume_on_exit() {
    resume_paused_services
}
trap resume_on_exit EXIT

if [[ ! -f "$ACTIVE_BACKUP_FILE" ]]; then
    echo "No active backup marker found; ensuring paused services are released."
    exit 0
fi

target="$(<"$ACTIVE_BACKUP_FILE")"
[[ "$target" == "$BACKUP_DIR/"* && -f "$target/.complete" ]] || {
    echo "Refusing to finalize an invalid or incomplete backup path: $target" >&2
    exit 1
}

ln -sfn "$(basename "$target")" "$BACKUP_DIR/latest"
rm -f "$ACTIVE_BACKUP_FILE"

keep="${BACKUP_RETENTION_COUNT:-7}"
[[ "$keep" =~ ^[1-9][0-9]*$ ]] || {
    echo "BACKUP_RETENTION_COUNT must be a positive integer" >&2
    exit 1
}

backups=()
for candidate in "$BACKUP_DIR"/????????T??????Z; do
    [[ -d "$candidate" && -f "$candidate/.complete" ]] && backups+=("$candidate")
done

if ((${#backups[@]} > keep)); then
    remove_count=$((${#backups[@]} - keep))
    for ((index = 0; index < remove_count; index++)); do
        rm -rf "${backups[$index]}"
    done
fi

echo "Backup finalized at $target."
