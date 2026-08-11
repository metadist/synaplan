#!/usr/bin/env bash
#
# The instance side of an application-consistent EBS snapshot, called through
# SSM rather than by a person: `deploy/aws/ssm/synaplan-backup-document.yaml`
# and the Data Lifecycle Manager pre/post scripts both do nothing but invoke
# this with a stage name.
#
# The work itself is the portable backup gate. pre-backup.sh writes a
# transactional MariaDB dump, Qdrant collection snapshots and an uploads archive
# onto the data volume and then pauses every service, so the snapshot taken
# between the two stages captures a quiesced filesystem that already carries its
# own restorable artifacts. post-backup.sh brings the services back.
#
# Usage: snapshot-hook.sh pre-script|post-script|dry-run [execution-id]

set -Eeuo pipefail

DEPLOY_DIR=/opt/synaplan/deploy

stage="${1-}"
execution_id="${2-manual}"

log() { printf '[synaplan-snapshot-hook] %s\n' "$*"; }

[[ -d "$DEPLOY_DIR" ]] || {
    echo "No Synaplan installation at $DEPLOY_DIR." >&2
    exit 1
}

case "$stage" in
    pre-script)
        log "Quiescing Synaplan for snapshot run $execution_id"
        "$DEPLOY_DIR/scripts/pre-backup.sh"
        log "Synaplan is quiesced; the snapshot can be taken"
        ;;
    post-script)
        log "Resuming Synaplan after snapshot run $execution_id"
        "$DEPLOY_DIR/scripts/post-backup.sh"
        log "Synaplan is back up"
        ;;
    dry-run)
        # What Data Lifecycle Manager runs when a policy is created, to prove
        # the document reaches the instance. It must not pause anything.
        for hook in pre-backup.sh post-backup.sh; do
            [[ -x "$DEPLOY_DIR/scripts/$hook" ]] || {
                echo "Missing or not executable: $DEPLOY_DIR/scripts/$hook" >&2
                exit 1
            }
        done
        log "Both backup hooks are in place"
        ;;
    *)
        echo "Usage: $(basename "$0") pre-script|post-script|dry-run [execution-id]" >&2
        exit 1
        ;;
esac
