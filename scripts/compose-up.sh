#!/usr/bin/env bash
# Start the boot-status page on :5173 before the rest of the stack is pulled.
#
# `docker compose up` fetches every service image first, then starts containers.
# After a down/restart that is minutes of silence on :5173 while Tika, Qdrant,
# MailHog, … download. Phase 1 only needs the frontend's node image, so the
# status page can say the other containers are still coming.
#
# Usage:
#   ./scripts/compose-up.sh
#   ./scripts/compose-up.sh -f docker-compose-minimal.yml
#   COMPOSE_PROFILES=local-ai ./scripts/compose-up.sh
#   make up
#
# Arguments are global docker-compose flags only (e.g. -f, --env-file).
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

echo "Starting the status page (http://localhost:5173)…"
docker compose "$@" up -d frontend

echo "Open http://localhost:5173 — other images may still be downloading."
echo "Starting the rest of the stack…"
docker compose "$@" up -d
