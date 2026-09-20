#!/usr/bin/env bash
# Push make images (:local) to an ephemeral registry and pin map.go to the
# resulting RepoDigests. Run from sidecars/synaplan-compute after `make images`.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

for img in python node; do
  if ! docker image inspect "synaplan-compute-${img}:local" >/dev/null 2>&1; then
    echo "missing synaplan-compute-${img}:local — run make images first" >&2
    exit 1
  fi
done

docker rm -f nightly-registry >/dev/null 2>&1 || true
docker run -d --name nightly-registry -p 127.0.0.1:5000:5000 registry:2.8 >/dev/null
ready=0
for _ in $(seq 1 20); do
  if curl -sf http://127.0.0.1:5000/v2/ >/dev/null; then
    ready=1
    break
  fi
  sleep 1
done
if [[ "${ready}" -ne 1 ]]; then
  echo "nightly-registry did not become ready on :5000" >&2
  docker logs nightly-registry >&2 || true
  exit 1
fi

for img in python node; do
  docker tag "synaplan-compute-${img}:local" "127.0.0.1:5000/compute-${img}:latest"
  docker push "127.0.0.1:5000/compute-${img}:latest"
done

pyid="$(docker inspect --format '{{index .RepoDigests 0}}' 127.0.0.1:5000/compute-python:latest | sed 's/.*@//')"
nodeid="$(docker inspect --format '{{index .RepoDigests 0}}' 127.0.0.1:5000/compute-node:latest | sed 's/.*@//')"
if [[ -z "${pyid}" || "${pyid}" == '<no value>' || -z "${nodeid}" || "${nodeid}" == '<no value>' ]]; then
  echo "RepoDigests missing after push (python=${pyid:-empty} node=${nodeid:-empty})" >&2
  exit 1
fi

python3 scripts/pin-nightly-map.py \
  --map internal/images/map.go \
  --python "${pyid}" \
  --node "${nodeid}"
grep -n "127.0.0.1:5000/compute-python@sha256:" internal/images/map.go | head -1
