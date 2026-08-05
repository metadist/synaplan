#!/usr/bin/env bash

set -Eeuo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

# Every start needs the stack's own credentials, and they are exported here rather
# than read from a configuration file: the platform rewrites that file on every
# deploy, and Compose prefers an exported variable over any --env-file entry.
ensure_deployment_secrets

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
