#!/usr/bin/env bash

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

# The platform's run command: validate on every start, then start the stack.
#
# The validation is deliberately repeated here and not only in build.sh, because
# a start can follow a configuration change that never ran a build. `compose up`
# must reach Compose through compose() for the same reason the pull in build.sh
# does; the post-install, post-deploy and post-update hooks then wait for health.
#
# The configuration source is named first, and on this path too: a start can
# follow a configuration change that never ran build.sh, so this is the only
# place that reports which file the started containers are configured from.
report_compose_env_file
"$DEPLOY_DIR/scripts/validate-release.sh"
compose up -d
echo "Services started."
