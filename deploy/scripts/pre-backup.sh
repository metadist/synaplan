#!/usr/bin/env bash

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

ACTIVE_BACKUP_FILE="$STATE_DIR/active-backup"

prepare_data_directories
if [[ -f "$ACTIVE_BACKUP_FILE" && -f "$PAUSED_SERVICES_FILE" ]]; then
    echo "Backup preparation is already complete: $(<"$ACTIVE_BACKUP_FILE")"
    exit 0
fi

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
target="$BACKUP_DIR/$timestamp"
mkdir -p "$target/qdrant"
chmod 0700 "$target"
backup_complete=false

cleanup_failed_backup() {
    local exit_code=$?
    ((exit_code != 0)) || exit_code=1
    trap - EXIT INT TERM
    if [[ "$backup_complete" != true ]]; then
        rm -rf "$target"
        rm -f "$ACTIVE_BACKUP_FILE"
        resume_paused_services || true
    fi
    exit "$exit_code"
}
trap cleanup_failed_backup EXIT INT TERM

begin_service_pause
pause_services scheduler worker backend

echo "Creating a consistent MariaDB dump..."
compose exec -T db sh -ec \
    'exec mariadb-dump --user=root --password="$MARIADB_ROOT_PASSWORD" \
        --single-transaction --routines --events --triggers --add-drop-table \
        "$MARIADB_DATABASE"' > "$target/mariadb.sql.tmp"
chmod 0600 "$target/mariadb.sql.tmp"
mv "$target/mariadb.sql.tmp" "$target/mariadb.sql"

echo "Creating Qdrant collection snapshots..."
: > "$target/qdrant/manifest.tsv"
collections="$(app_tool "curl -fsS http://qdrant:6333/collections | jq -r '.result.collections[].name'")"
while IFS= read -r collection; do
    [[ -n "$collection" ]] || continue
    [[ "$collection" =~ ^[A-Za-z0-9._-]+$ ]] || {
        echo "Unsafe Qdrant collection name: $collection" >&2
        exit 1
    }

    snapshot="$(app_tool "curl -fsS -X POST http://qdrant:6333/collections/$collection/snapshots | jq -er '.result.name'")"
    [[ "$snapshot" =~ ^[A-Za-z0-9._-]+$ ]] || {
        echo "Unsafe Qdrant snapshot name returned for $collection" >&2
        exit 1
    }

    app_tool "curl -fsS http://qdrant:6333/collections/$collection/snapshots/$snapshot" \
        > "$target/qdrant/$snapshot.tmp"
    chmod 0600 "$target/qdrant/$snapshot.tmp"
    mv "$target/qdrant/$snapshot.tmp" "$target/qdrant/$snapshot"
    printf '%s\t%s\n' "$collection" "$snapshot" >> "$target/qdrant/manifest.tsv"
    app_tool "curl -fsS -X DELETE http://qdrant:6333/collections/$collection/snapshots/$snapshot >/dev/null"
done <<< "$collections"
chmod 0600 "$target/qdrant/manifest.tsv"

echo "Archiving uploaded files..."
while IFS= read -r -d '' path; do
    [[ "$path" != *$'\n'* && "$path" != *\\* ]] || {
        echo "Upload paths containing newlines or backslashes cannot be backed up safely: $path" >&2
        exit 1
    }
done < <(find "$DATA_DIR/uploads" -mindepth 1 -print0)

if find "$DATA_DIR/uploads" -type l -print -quit | grep -q .; then
    echo "Refusing to archive symlinks from the uploads directory" >&2
    exit 1
fi

(
    cd "$DATA_DIR/uploads"
    tar -czf "$target/uploads.tar.gz.tmp" .
    while IFS= read -r -d '' file; do
        if command -v sha256sum >/dev/null 2>&1; then
            sha256sum "$file"
        else
            shasum -a 256 "$file"
        fi
    done < <(find . -type f -print0)
) > "$target/uploads.manifest.sha256"
chmod 0600 "$target/uploads.tar.gz.tmp" "$target/uploads.manifest.sha256"
mv "$target/uploads.tar.gz.tmp" "$target/uploads.tar.gz"
printf '%s  uploads.tar.gz\n' "$(sha256_file "$target/uploads.tar.gz")" > "$target/uploads.tar.gz.sha256"
chmod 0600 "$target/uploads.tar.gz.sha256"

# Portable artifacts must exist before stateful bind mounts are quiesced for the
# platform's raw filesystem snapshot.
pause_services ollama qdrant centrifugo redis db

printf '%s\n' "$target" > "$ACTIVE_BACKUP_FILE"
touch "$target/.complete"
chmod 0600 "$ACTIVE_BACKUP_FILE" "$target/.complete"
ln -sfn "$(basename "$target")" "$BACKUP_DIR/latest"
backup_complete=true
trap - EXIT INT TERM

echo "Backup artifacts are ready in $target; all previously running stateful services remain paused."
