#!/usr/bin/env bash
#
# Application-consistent backup of the data disk, published as
# `synaplan-snapshot`.
#
# Two paths, because a VM may or may not have been deployed with a Recovery
# Services vault:
#
#   With a vault, Azure Backup does the work. The image ships
#   /etc/azure/VMSnapshotScriptPluginConfig.json, so Azure runs the portable
#   backup gate around its own snapshot: pre-backup.sh writes a transactional
#   dump, Qdrant collection snapshots and an uploads archive onto the disk and
#   pauses every service, Azure snapshots the quiesced disk, post-backup.sh
#   resumes. This script only starts that job.
#
#   Without a vault, a plain managed-disk snapshot is crash-consistent: it
#   captures whatever MariaDB and Qdrant happened to have on disk mid-write. So
#   this script wraps the snapshot in the same portable gate itself.
#
# Either way the application is unavailable for the duration of the dump plus
# the few seconds the snapshot request takes.

set -Eeuo pipefail
# shellcheck source=../../scripts/lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/scripts/lib.sh"

# pre-backup.sh and post-backup.sh are child processes whose exports never come
# back here, and the disk lookup below must not be the first thing that fails.
ensure_deployment_secrets

IMDS_BASE=http://169.254.169.254/metadata

[[ $EUID -eq 0 ]] || {
    echo "synaplan-snapshot must run as root: sudo synaplan-snapshot" >&2
    exit 1
}

command -v az >/dev/null 2>&1 || {
    echo "The Azure CLI is unavailable, so no snapshot can be taken." >&2
    exit 1
}

instance="$(curl --fail --silent --max-time 5 \
    -H "Metadata:true" --noproxy '*' \
    "$IMDS_BASE/instance?api-version=2021-02-01")" || {
    echo "Could not read the instance metadata; this does not look like an Azure VM." >&2
    exit 1
}

metadata() { printf '%s' "$instance" | jq -r "$1 // empty"; }

vm_name="$(metadata '.compute.name')"
resource_group="$(metadata '.compute.resourceGroupName')"
subscription="$(metadata '.compute.subscriptionId')"
location="$(metadata '.compute.location')"
tag() { printf '%s' "$instance" | jq -r --arg name "synaplan:$1" '.compute.tagsList[]? | select(.name == $name) | .value' | head -n1; }

# The VM's own managed identity. No credential on this disk, and nothing the
# operator has to log in with.
az login --identity --output none 2>/dev/null || {
    echo "This VM has no usable managed identity, so the Azure CLI cannot authenticate." >&2
    echo "Snapshot the data disk from the Azure portal instead." >&2
    exit 1
}
az account set --subscription "$subscription" --output none

vault="$(tag recovery-vault)"
vault_resource_group="$(tag recovery-vault-resource-group)"
[[ -n "$vault_resource_group" ]] || vault_resource_group="$resource_group"

if [[ -n "$vault" ]]; then
    # 30 days, which matches the default retention of the policy the ARM
    # template creates. An on-demand backup needs an explicit expiry.
    retain_until="$(date --utc --date '+30 days' +%d-%m-%Y)"

    echo "Starting an application-consistent Azure Backup of $vm_name..."
    az backup protection backup-now \
        --resource-group "$vault_resource_group" \
        --vault-name "$vault" \
        --container-name "$vm_name" \
        --item-name "$vm_name" \
        --backup-management-type AzureIaasVM \
        --retain-until "$retain_until" \
        --output table

    cat <<EOF

Azure Backup quiesces the application through
/etc/azure/VMSnapshotScriptPluginConfig.json, snapshots the disks, and brings
the services back on its own. The job runs in the background.

Follow it:
  az backup job list --resource-group $vault_resource_group --vault-name $vault --output table
EOF
    exit 0
fi

# --------------------------------------------------------------------------
# No vault: quiesce here and take a managed-disk snapshot
# --------------------------------------------------------------------------

DATA_MOUNT=/var/lib/synaplan

findmnt --noheadings --target "$DATA_MOUNT" --mountpoint "$DATA_MOUNT" >/dev/null 2>&1 || {
    echo "The data is on the OS disk, not on a separate data disk." >&2
    echo "Snapshot the OS disk from the Azure portal instead, or redeploy with a data disk." >&2
    exit 1
}

# The disk at LUN 0 is the one firstboot.sh mounted, and IMDS names its resource
# id — so no guessing from a device path that Azure may renumber. IMDS documents
# every scalar as a string, `"lun": "0"`, but the comparison goes through
# `tostring` anyway: the cost is nothing, and the failure it would otherwise
# cause lands on the operator in the one moment they can least afford it, since
# this is the backup gate they run right before an update.
disk_id="$(printf '%s' "$instance" |
    jq -r '.compute.storageProfile.dataDisks[]? | select((.lun | tostring) == "0") | .managedDisk.id // empty' | head -n1)"

[[ -n "$disk_id" ]] || {
    echo "Could not identify the managed disk behind $DATA_MOUNT." >&2
    echo "Snapshot it from the Azure portal instead: Virtual machine > Disks > the disk at LUN 0 > Create snapshot." >&2
    exit 1
}

snapshot_name="synaplan-$vm_name-$(date --utc +%Y%m%d-%H%M%S)"

echo "Quiescing the application for a consistent snapshot of the data disk..."
"$DEPLOY_DIR/scripts/pre-backup.sh"
# Whatever happens next, the services come back. A failed snapshot must not
# leave the installation paused.
trap '"$DEPLOY_DIR/scripts/post-backup.sh"' EXIT INT TERM

az snapshot create \
    --resource-group "$resource_group" \
    --location "$location" \
    --name "$snapshot_name" \
    --source "$disk_id" \
    --tags "synaplan:consistent=application" \
    --output none

trap - EXIT INT TERM
"$DEPLOY_DIR/scripts/post-backup.sh"

cat <<EOF

Snapshot created: $snapshot_name
The application is back up.

Inspect it:
  az snapshot show --resource-group $resource_group --name $snapshot_name --output table
EOF
