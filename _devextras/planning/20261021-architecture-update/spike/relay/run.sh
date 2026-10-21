#!/usr/bin/env bash
# Relay spike: hold 100 … 2000 streams of 10 s each (200 tokens, 50 ms apart)
# through one Go relay and print memory and CPU per stream. Needs Docker only.
set -euo pipefail
cd "$(dirname "$0")"
IMAGE="${GO_IMAGE:-golang:1.25.8}"
docker run --rm -e CLIENTS -e RACE -v "$PWD":/src -w /src "$IMAGE" sh -c '
  set -e
  if [ "${RACE:-0}" = 1 ]; then go build -race -o /tmp/relay main.go; else go build -o /tmp/relay main.go; fi
  /tmp/relay --mode upstream --listen :9001 --tokens 200 --interval 50ms &
  /tmp/relay --mode relay --listen :9000 --upstream http://127.0.0.1:9001/stream &
  sleep 1
  for n in ${CLIENTS:-100 500 1000 2000}; do /tmp/relay --mode load --clients "$n"; done
'
