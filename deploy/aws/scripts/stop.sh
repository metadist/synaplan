#!/usr/bin/env bash
#
# Stops the stack for synaplan.service. A thin wrapper rather than an inline
# ExecStop, because Compose has to be reached through lib.sh — it resolves which
# configuration file the running containers were started from, and a direct
# `docker compose down` would resolve a different one and leave containers
# behind.

set -Eeuo pipefail
# shellcheck source=../../scripts/lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/scripts/lib.sh"

# compose.yaml declares the managed secrets as `${VAR:?}`, so even a stop has to
# resolve them before it reaches Compose.
ensure_deployment_secrets

compose down
echo "Services stopped."
