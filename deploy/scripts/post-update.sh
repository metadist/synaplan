#!/usr/bin/env bash

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

# This script is also the install and deploy hook, so it is the only host-side
# gate on those paths: validate-release.sh does not run before them. Tier 1 only
# — the image contract, and with it the authoritative email probe, was already
# verified by validate-release.sh when the release was pinned.
#
# The roundtrip guard comes first, as in prepare.sh and validate-release.sh: it is
# the only check that compares the configuration FILE with what Compose read back
# from it, and validate_bootstrap_admin_config resolves its values through
# Compose, so it sees an altered value as if it were the configured one and
# approves it.
validate_secret_roundtrip
validate_bootstrap_admin_config

compose up -d --remove-orphans
wait_for_service_health db 300
wait_for_service_health redis 120
wait_for_service_health qdrant 180
wait_for_service_health centrifugo 120
wait_for_service_health tika 180
wait_for_service_health backend 600
wait_for_service_health worker 240
wait_for_service_health scheduler 240

"$DEPLOY_DIR/scripts/smoke-test.sh"

container_id="$(compose ps -q backend)"
image_reference="$(docker inspect --format '{{.Config.Image}}' "$container_id")"
image_id="$(docker inspect --format '{{.Image}}' "$container_id")"
printf 'Update verified: %s (%s)\n' "$image_reference" "$image_id"
