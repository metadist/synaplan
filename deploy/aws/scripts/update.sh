#!/usr/bin/env bash
#
# In-place update of a running AMI instance, published as `synaplan-update`.
#
#   sudo synaplan-update 1.4.0
#
# It is a thin sequencer over the portable contract — pre-update backup gate,
# version bump, post-update start and verification. The AWS adapter deliberately
# owns no update logic of its own, so an instance updates exactly the way a
# self-hosted install does (docs/UPDATE_SELFHOST.md) and cannot drift from it.

set -Eeuo pipefail
# shellcheck source=../../scripts/lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/scripts/lib.sh"

usage() {
    cat >&2 <<'USAGE'
Usage: sudo synaplan-update <version>

  <version>  A released SemVer version, without a leading v — for example 1.4.0.
             Released versions: https://github.com/metadist/synaplan/releases

Take a snapshot first:  sudo synaplan-snapshot
Full guide:             https://github.com/metadist/synaplan/blob/main/docs/UPDATE_AWS.md
USAGE
    exit 64
}

target_version="${1-}"
[[ -n "$target_version" ]] || usage
[[ "$target_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?$ ]] || {
    printf 'Refusing "%s": only an immutable released version can be installed, never a mutable tag such as latest.\n\n' \
        "$target_version" >&2
    usage
}

[[ $EUID -eq 0 ]] || {
    echo "synaplan-update must run as root: sudo synaplan-update $target_version" >&2
    exit 1
}

# Only now, once the request is known to be a valid one from root: the sibling
# scripts below run as child processes and their exports never come back here,
# and generating deployment secrets is not something a mistyped command line or
# a run without sudo should reach.
ensure_deployment_secrets

env_file="$(resolve_compose_env_file)"
[[ -n "$env_file" ]] || {
    echo "No deployment configuration found; this instance has not completed its first boot." >&2
    exit 1
}

current_version="$(awk -F= '/^SYNAPLAN_VERSION=/ { print $2; exit }' "$env_file")"
if [[ "$current_version" == "$target_version" ]]; then
    printf 'Already running %s; nothing to do.\n' "$target_version"
    exit 0
fi

printf 'Updating Synaplan %s -> %s\n\n' "${current_version:-unknown}" "$target_version"

# 1. Backup gate. Fails loudly and changes nothing if the backup does not work,
#    which is the whole point of running it before the version changes.
"$DEPLOY_DIR/scripts/pre-update.sh"

# 2. Pin the new version. Written after the backup so a failed backup leaves the
#    instance on the release it was running.
if grep -q '^SYNAPLAN_VERSION=' "$env_file"; then
    sed -i "s|^SYNAPLAN_VERSION=.*|SYNAPLAN_VERSION=$target_version|" "$env_file"
else
    printf 'SYNAPLAN_VERSION=%s\n' "$target_version" >> "$env_file"
fi

# The images baked into the AMI only cover the release it shipped with, so an
# update has to be allowed to fetch. Restored to `missing` is deliberate: it
# stays a normal start that never reaches out afterwards.
restore_pull_policy() {
    sed -i 's|^SYNAPLAN_PULL_POLICY=.*|SYNAPLAN_PULL_POLICY=missing|' "$env_file"
}
if grep -q '^SYNAPLAN_PULL_POLICY=' "$env_file"; then
    sed -i 's|^SYNAPLAN_PULL_POLICY=.*|SYNAPLAN_PULL_POLICY=always|' "$env_file"
    trap restore_pull_policy EXIT
fi

# 3. Start and verify: pulls the image, runs the migrations, waits for every
#    service to become healthy, then runs the smoke test.
"$DEPLOY_DIR/scripts/post-update.sh"

printf '\nSynaplan is now running %s.\n' "$target_version"
printf 'Roll back with: sudo synaplan-update %s\n' "${current_version:-<previous version>}"
