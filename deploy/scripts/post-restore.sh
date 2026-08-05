#!/usr/bin/env bash

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

# The restored deploy/data carries the deployment's secrets.env, so this is also
# the point at which a restore that did NOT include that file fails — with a
# message naming the variable, instead of a database nobody can authenticate
# against.
ensure_deployment_secrets

# Runs before the recovery trap below is installed, on purpose: that trap brings
# the backend back up, so a failure after it exists would start a container with
# the rejected configuration — the crash loop this preflight prevents. The
# configuration is resolvable here because deploy/.env lives outside deploy/data
# and is therefore untouched by the platform restore.
#
# A restore is also the path most likely to run on a hand-written configuration
# file — recreated on a new host from an external copy of the secrets — so the
# roundtrip guard runs here too, and before the value rules for the same reason as
# everywhere else: an altered value must be reported as altered, not as invalid.
validate_secret_roundtrip
validate_bootstrap_admin_config

RESTORE_MARKER="$STATE_DIR/restore-ready"
restore_complete=false
uploads_staging=""
ollama_was_running=false
if service_was_paused ollama; then
    ollama_was_running=true
fi

recover_services_on_failure() {
    local exit_code=$?
    trap - EXIT INT TERM
    if [[ "$restore_complete" != true ]]; then
        ((exit_code != 0)) || exit_code=1
        echo "Restore finalization failed; attempting to return services to a runnable state." >&2
        compose up -d db redis qdrant centrifugo tika backend worker scheduler || true
        if [[ "$ollama_was_running" == true ]]; then
            compose up -d ollama || true
        fi
        [[ -n "$uploads_staging" ]] && rm -rf "$uploads_staging"
        echo "Restore markers were retained so finalization can be retried safely." >&2
    fi
    exit "$exit_code"
}
trap recover_services_on_failure EXIT INT TERM

prepare_data_directories
[[ -f "$RESTORE_MARKER" ]] || echo "Restore marker is absent; running idempotent recovery checks."

backup="$(latest_backup_path)"
backup_name="$(basename "$backup")"
restore_portable_backup="${RESTORE_PORTABLE_BACKUP:-false}"

chmod 0700 "$DATA_DIR" "$BACKUP_DIR" "$backup"
chmod 0600 "$backup/mariadb.sql"

compose up -d db redis qdrant centrifugo tika
wait_for_service_health db 300
wait_for_service_health redis 120
wait_for_service_health qdrant 180
wait_for_service_health centrifugo 120
wait_for_service_health tika 180

if [[ "$restore_portable_backup" == "true" ]]; then
    [[ -f "$backup/uploads.tar.gz" && -f "$backup/uploads.tar.gz.sha256" &&
        -f "$backup/uploads.manifest.sha256" ]] || {
        echo "Portable backup is missing uploaded-file artifacts" >&2
        exit 1
    }
    verify_sha256_file "$backup/uploads.tar.gz" "$backup/uploads.tar.gz.sha256"

    while IFS= read -r archive_path; do
        normalized="${archive_path#./}"
        [[ -z "$normalized" || "$normalized" == "." ]] && continue
        [[ "$normalized" != /* && "/$normalized/" != *"/../"* ]] || {
            echo "Unsafe path in uploads archive: $archive_path" >&2
            exit 1
        }
    done < <(tar -tzf "$backup/uploads.tar.gz")
    if tar -tvzf "$backup/uploads.tar.gz" | grep -Eq '^[lh]'; then
        echo "Refusing to restore links from the uploads archive" >&2
        exit 1
    fi

    uploads_staging="$(mktemp -d "$DATA_DIR/.uploads-restore.XXXXXX")"
    tar -xzf "$backup/uploads.tar.gz" \
        --directory "$uploads_staging" \
        --no-same-owner \
        --no-same-permissions
    if find "$uploads_staging" -type l -print -quit | grep -q .; then
        echo "Uploads archive extracted an unexpected symlink" >&2
        exit 1
    fi
    if [[ -s "$backup/uploads.manifest.sha256" ]]; then
        while IFS= read -r checksum_line; do
            checksum="${checksum_line:0:64}"
            separator="${checksum_line:64:2}"
            manifest_path="${checksum_line:66}"
            normalized="${manifest_path#./}"
            [[ "$checksum" =~ ^[a-fA-F0-9]{64}$ && "$separator" == "  " &&
                -n "$normalized" && "$normalized" != /* &&
                "/$normalized/" != *"/../"* ]] || {
                echo "Unsafe entry in uploaded-file checksum manifest" >&2
                exit 1
            }
        done < "$backup/uploads.manifest.sha256"
        (
            cd "$uploads_staging"
            if command -v sha256sum >/dev/null 2>&1; then
                sha256sum -c "$backup/uploads.manifest.sha256"
            else
                shasum -a 256 -c "$backup/uploads.manifest.sha256"
            fi
        )
    fi

    echo "Restoring MariaDB from the portable dump..."
    compose exec -T db sh -ec \
        'exec mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' \
        < "$backup/mariadb.sql"

    manifest="$backup/qdrant/manifest.tsv"
    if [[ -s "$manifest" ]]; then
        echo "Restoring Qdrant collection snapshots..."
        while IFS=$'\t' read -r collection snapshot; do
            [[ "$collection" =~ ^[A-Za-z0-9._-]+$ && "$snapshot" =~ ^[A-Za-z0-9._-]+$ ]] || {
                echo "Invalid Qdrant snapshot manifest entry" >&2
                exit 1
            }
            app_tool "curl -fsS -X POST \
                'http://qdrant:6333/collections/$collection/snapshots/upload?priority=snapshot' \
                -F 'snapshot=@/var/www/backup/$backup_name/qdrant/$snapshot' >/dev/null"
        done < "$manifest"
    fi

    echo "Restoring uploaded files from the portable archive..."
    rm -rf "$DATA_DIR/uploads"
    mv "$uploads_staging" "$DATA_DIR/uploads"
    uploads_staging=""
else
    echo "Using database and Qdrant state restored by the platform."
fi

compose run --rm --no-deps --pull never --entrypoint bash backend -ec \
    'chown -R www-data:www-data /var/www/backend/var/uploads && chmod -R u+rwX,g+rwX /var/www/backend/var/uploads'

compose up -d backend
wait_for_service_health backend 600
compose up -d worker scheduler
wait_for_service_health worker 240
wait_for_service_health scheduler 240
if [[ "$ollama_was_running" == true ]]; then
    compose up -d ollama
    wait_for_service_health ollama 240
fi

rm -f "$RESTORE_MARKER" "$PAUSED_SERVICES_FILE" "$STATE_DIR/active-backup"
"$DEPLOY_DIR/scripts/smoke-test.sh"
restore_complete=true
trap - EXIT INT TERM
echo "Restore completed and verified."
