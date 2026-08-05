#!/usr/bin/env bash

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

# Before anything reaches Compose. compose.yaml declares the managed secrets as
# `${VAR:?}`, so on a deployment whose platform no longer supplies them — which is
# every new Elestio deployment, because one shared generated value for all of them
# was worse than none — `compose config --quiet` below would abort here first, and
# the deployment would never start.
ensure_deployment_secrets

prepare_data_directories

# On a managed platform this is the first place that reveals WHICH configuration
# file the deployment is running on, that it is running on the host environment
# alone, or that a second candidate file exists and is being ignored.
report_compose_env_file

compose config --quiet
validate_release_pin
# After `compose config`, which already rejects a value Compose cannot parse at
# all, and before any rule that inspects a resolved value: a value that Compose
# altered must be reported as altered, not as invalid.
validate_secret_roundtrip
echo "Deployment directories and Compose configuration are ready."
