#!/usr/bin/env bash
set -Eeuo pipefail
exec "$(dirname "$0")/../scripts/pre-update.sh"
