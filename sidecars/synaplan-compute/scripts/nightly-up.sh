#!/usr/bin/env bash
# Start synaplan-compute for the nightly load the same way a developer
# install does.
#
# A plain `docker compose up` uses one fixed demo token and the :local
# images `make images` just built. This script does that too. It does not
# generate a token and it does not rewrite the published digest pins in
# internal/images/map.go — both of those changed out from under the job
# and took the nightly down.
#
# Production must not use the demo token. deploy/ prepare.sh writes a
# fresh COMPUTE_TOKEN when the operator leaves it empty. See the root
# README, section "File work".
set -euo pipefail

# Keep this identical to the COMPUTE_TOKEN default in docker-compose.yml.
# tests/compute-nightly-images.test.mjs fails if the two drift.
DEMO_TOKEN="synaplan-dev-compute-token-change-me-32b"

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

if ! docker image inspect synaplan-compute-python:local >/dev/null 2>&1 \
  || ! docker image inspect synaplan-compute-node:local >/dev/null 2>&1; then
  echo "missing :local run images — run 'make images' first" >&2
  exit 1
fi

docker build -t synaplan-compute:test .

scratch_host="/tmp/compute-nightly/scratch"
workspaces_host="/tmp/compute-nightly/workspaces"
mkdir -p "$scratch_host" "$workspaces_host"
chmod 777 "$scratch_host" "$workspaces_host"

docker rm -f synaplan-compute-nightly >/dev/null 2>&1 || true

# Root matches the compose service: the image is distroless nonroot, which
# cannot open the host docker socket or create the default scratch path.
args=(
  -d --name synaplan-compute-nightly
  --user 0:0
  -p 127.0.0.1:8080:8080
  -v /var/run/docker.sock:/var/run/docker.sock
  -v "${scratch_host}:/scratch"
  -v "${workspaces_host}:/workspaces"
  -e "COMPUTE_AUTH_TOKEN=${DEMO_TOKEN}"
  -e COMPUTE_LISTEN=:8080
  -e COMPUTE_SCRATCH_DIR=/scratch
  -e COMPUTE_WORKSPACES_DIR=/workspaces
  -e COMPUTE_ALLOW_LOCAL_IMAGES=1
  -e COMPUTE_IMAGE_PYTHON=synaplan-compute-python:local
  -e COMPUTE_IMAGE_NODE=synaplan-compute-node:local
  -e COMPUTE_MAX_CONCURRENT=4
)
if [[ -n "${COMPUTE_TIER:-}" ]]; then
  args+=(-e "COMPUTE_TIER=${COMPUTE_TIER}")
fi

docker run "${args[@]}" synaplan-compute:test

if [[ -n "${GITHUB_ENV:-}" ]]; then
  echo "COMPUTE_TOKEN=${DEMO_TOKEN}" >> "$GITHUB_ENV"
fi

for _ in $(seq 1 30); do
  if curl -sf http://127.0.0.1:8080/v1/health >/dev/null; then
    curl -sf http://127.0.0.1:8080/v1/health | head -c 300
    echo
    exit 0
  fi
  running="$(docker inspect -f '{{.State.Running}}' synaplan-compute-nightly 2>/dev/null || echo false)"
  if [[ "$running" != "true" ]]; then
    echo "sidecar exited before /v1/health" >&2
    docker logs synaplan-compute-nightly >&2 || true
    exit 1
  fi
  sleep 2
done

echo "sidecar did not become healthy" >&2
docker logs synaplan-compute-nightly >&2 || true
exit 1
