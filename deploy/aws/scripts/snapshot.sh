#!/usr/bin/env bash
#
# Application-consistent EBS snapshot of the data volume, published as
# `synaplan-snapshot`.
#
# A plain EBS snapshot is crash-consistent: it captures whatever MariaDB and
# Qdrant happened to have on disk mid-write. This wraps the snapshot in the
# portable backup gate — pre-backup.sh writes a transactional dump, Qdrant
# collection snapshots and an uploads archive onto the volume and then pauses
# every service, and post-backup.sh resumes them — so what the snapshot captures
# is a quiesced volume that already contains its own restorable artifacts.
#
# The application is unavailable for the duration, which is the dump plus the
# few seconds the snapshot request takes (the snapshot itself completes in the
# background, after the services are back).

set -Eeuo pipefail
# shellcheck source=../../scripts/lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/scripts/lib.sh"

# pre-backup.sh and post-backup.sh are child processes whose exports never come
# back here, and the volume lookup below must not be the first thing that fails.
ensure_deployment_secrets

DATA_MOUNT=/var/lib/synaplan

[[ $EUID -eq 0 ]] || {
    echo "synaplan-snapshot must run as root: sudo synaplan-snapshot" >&2
    exit 1
}

command -v aws >/dev/null 2>&1 || {
    echo "The AWS CLI is unavailable, so no snapshot can be taken." >&2
    exit 1
}

imds() {
    local token
    token="$(curl --fail --silent --max-time 5 -X PUT \
        "http://169.254.169.254/latest/api/token" \
        -H "X-aws-ec2-metadata-token-ttl-seconds: 300")" || return 1
    curl --fail --silent --max-time 5 \
        -H "X-aws-ec2-metadata-token: $token" \
        "http://169.254.169.254/latest/meta-data/$1"
}

instance_id="$(imds instance-id)" || {
    echo "Could not read the instance id; this does not look like an EC2 instance." >&2
    exit 1
}
region="$(imds placement/region)" && export AWS_DEFAULT_REGION="$region"

# The EBS volume behind the mount point. On Nitro instances the block device is
# NVMe and its name has nothing to do with the device name the attachment was
# made under (/dev/sdf becomes /dev/nvme1n1), so the device name cannot be used
# to identify the volume. The NVMe serial can: EBS writes the volume id into it,
# without the dash.
source_device="$(findmnt --noheadings --output SOURCE --target "$DATA_MOUNT")"
serial="$(lsblk --noheadings --nodeps --output SERIAL "$source_device" 2>/dev/null | tr -d ' ')"

volume_id=""
if [[ "$serial" == vol-* ]]; then
    volume_id="$serial"
elif [[ "$serial" == vol* ]]; then
    volume_id="vol-${serial#vol}"
else
    # Xen instances, where the attachment device name IS the device name.
    volume_id="$(aws ec2 describe-volumes \
        --filters "Name=attachment.instance-id,Values=$instance_id" \
            "Name=attachment.device,Values=$source_device" \
        --query 'Volumes[0].VolumeId' --output text 2>/dev/null | grep -v '^None$' || true)"
fi

if [[ -z "$volume_id" ]]; then
    echo "Could not identify the EBS volume behind $DATA_MOUNT." >&2
    echo "The data is on the root volume — snapshot that volume from the console instead." >&2
    exit 1
fi

echo "Quiescing the application for a consistent snapshot of $volume_id..."
"$DEPLOY_DIR/scripts/pre-backup.sh"
# Whatever happens next, the services come back. A failed snapshot must not
# leave the installation paused.
trap '"$DEPLOY_DIR/scripts/post-backup.sh"' EXIT INT TERM

snapshot_id="$(aws ec2 create-snapshot \
    --volume-id "$volume_id" \
    --description "Synaplan application-consistent snapshot from $instance_id" \
    --tag-specifications "ResourceType=snapshot,Tags=[{Key=Name,Value=synaplan-$instance_id},{Key=synaplan:consistent,Value=application}]" \
    --query 'SnapshotId' --output text)"

trap - EXIT INT TERM
"$DEPLOY_DIR/scripts/post-backup.sh"

cat <<EOF

Snapshot started: $snapshot_id
The application is back up; the snapshot completes in the background.

Wait for it:
  aws ec2 wait snapshot-completed --snapshot-ids $snapshot_id
EOF
