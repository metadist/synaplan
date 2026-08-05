#!/usr/bin/env bash

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

# Every check below resolves the Compose configuration, and the managed secrets are
# `${VAR:?}` keys in it. This script is also documented as directly runnable, so it
# cannot rely on a caller having exported them.
ensure_deployment_secrets

validate_release_pin
# elestio.yml runs this script again as its run command, on every start, so the
# roundtrip guard also covers a configuration file that changed after the
# install-time prepare.sh. It must precede the value rules below: a value Compose
# altered has to be reported as altered, not as invalid.
validate_secret_roundtrip
# Tier 1: needs no application image, so it decides everything it can before one
# is started.
validate_bootstrap_admin_config
resolved_image="$(resolved_app_image)"

# Tier 2: the image contract probe also asks the application's own validator for a
# verdict on the COMPLETE first-admin configuration, so no host-side rule can
# drift from it.
validate_app_image_contract

echo "Validated compatible Synaplan application image: $resolved_image"
