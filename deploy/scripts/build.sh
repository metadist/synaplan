#!/usr/bin/env bash

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

# The platform's build command: prepare the checkout, fetch the pinned image,
# then verify it.
#
# It is a script rather than a `&&` chain in elestio.yml because the pull has to
# go through compose(). Called as `docker compose -f deploy/compose.yaml pull`,
# Compose takes deploy/ as the project directory and reads deploy/.env only — so
# a configuration that arrives as a checkout-root .env is invisible exactly here,
# every required `${VAR:?}` is unset, and the pull fails while every lifecycle
# hook, which does go through compose(), works. See resolve_compose_env_file().
#
# `set -e` keeps the chain's semantics: the first failing step aborts with its own
# exit status, so the platform marks the deployment as failed.
"$DEPLOY_DIR/scripts/prepare.sh"
compose pull
"$DEPLOY_DIR/scripts/validate-release.sh"
echo "Build completed; the pinned application image is present and verified."
