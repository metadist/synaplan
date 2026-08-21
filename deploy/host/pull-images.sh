#!/usr/bin/env bash
#
# Bakes the container images for one release into a marketplace image, so a
# first boot comes up from the local image cache instead of waiting on ghcr.io.
# A launch into a locked-down network has no outbound registry access at all and
# must still work.
#
# The pull runs in a THROWAWAY copy of the deployment tree. deploy/scripts/
# resolves the eight managed secrets before it reaches Compose, and any value
# generated at bake time would be identical on every instance launched from the
# image — the exact shared-credential defect the Elestio adapter exists to avoid.
# The copy is deleted before this script returns, so nothing it generated
# survives into the image.

set -Eeuo pipefail

: "${SYNAPLAN_VERSION:?SYNAPLAN_VERSION must be set to the release to bake in}"

DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

mkdir -p "$stage/deploy"
cp "$DEPLOY_DIR/compose.yaml" "$stage/deploy/compose.yaml"
cp -R "$DEPLOY_DIR/scripts" "$stage/deploy/scripts"

# Placeholder configuration for the pull only. compose.yaml declares APP_URL and
# FRONTEND_URL as required, and `.invalid` is the reserved TLD that can never
# resolve — so a value leaking into a running stack would fail loudly rather
# than point somewhere real.
cat > "$stage/deploy/.env" <<EOF
COMPOSE_PROJECT_NAME=synaplan-image-build
SYNAPLAN_VERSION=$SYNAPLAN_VERSION
SYNAPLAN_PULL_POLICY=always
APP_URL=https://image-build.invalid
FRONTEND_URL=https://image-build.invalid
EOF

# The portable contract, unmodified: prepare, pull, verify the image is the
# pinned immutable release. A version that does not exist fails the build here
# rather than every customer's first boot.
"$stage/deploy/scripts/build.sh"

printf 'Baked the container images for Synaplan %s into the image cache.\n' "$SYNAPLAN_VERSION"
