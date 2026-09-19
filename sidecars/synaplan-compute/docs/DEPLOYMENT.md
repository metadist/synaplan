# Deployment — isolation tiers

Compute is reachable from the Synaplan PHP backend only. Never publish
`8080` on a public interface.

Authentication: `Authorization: Bearer <COMPUTE_AUTH_TOKEN>` on every path
except `GET /v1/health`. PHP holds the same value as `COMPUTE_TOKEN`.

## T1 — same host (developer / self-host)

Compose profile `compute` in `synaplan/docker-compose.yml`:

```bash
mkdir -p .compute-data/scratch .compute-data/workspaces
chmod -R 777 .compute-data
# write COMPUTE_URL, COMPUTE_TOKEN and COMPUTE_DOCKER_GID into .env
make -C sidecars/synaplan-compute images   # prints Id digests to pin in map.go
COMPUTE_TOKEN=$(openssl rand -hex 32) COMPUTE_URL=http://compute:8080 \
  COMPUTE_DOCKER_GID=$(stat -c %g /var/run/docker.sock) \
  docker compose --profile compute up -d --build
```

`COMPUTE_TOKEN` must be at least 32 random bytes (`openssl rand -hex 32`
produces 64 hex characters). Compose interpolates the variable even when
the profile is off, so it defaults to empty rather than failing
`docker compose ps`. The sidecar refuses to start if the token is short.

The sidecar image runs as distroless `nonroot`. A typical Linux Docker
socket is `root:docker` mode `0660`, so pass `COMPUTE_DOCKER_GID` (the
numeric GID of `/var/run/docker.sock`) via `group_add`. The compose default
`998` is often wrong — without the real GID every accepted run becomes
`docker_unavailable`.

The runner never pulls images. `make images` builds local tags and prints
their Id digests. Paste those into `internal/images/map.go` (the shipped
GHCR 1.0.0 pins are not public). Then rebuild the sidecar.

Honest limits: this is hardened Docker on the same machine as PHP
(`--network none`, dropped caps, read-only rootfs). It is **not** the
Cloud posture. Default concurrency is 2 on a 4 GB laptop.

PHP never mounts `docker.sock`. Only the `compute` service does.

## T2 — gVisor on a separate compute node (required for Synaplan Cloud)

Install `runsc`, register it in Docker `daemon.json` `runtimes`, set
`COMPUTE_TIER=gvisor`, and run the sidecar on a dedicated host or a
dedicated Docker daemon. Web hosts set `COMPUTE_URL` to that node over
the private network. The `synaplan-platform` service block lives in the
private repo.

## T3 — Kata / Firecracker

Documented only. Same API; the tier is reported in `GET /v1/health`.

## Images

Runtime images are `python` and `node` keys, pinned by digest in the
sidecar image map. A non-digest reference is refused at sidecar start.
Release tags are `v1.x.y`. Do not reference `latest` in production compose.

```bash
cosign verify ghcr.io/metadist/synaplan-compute:<tag> \
  --certificate-identity-regexp='https://github.com/metadist/synaplan/.+' \
  --certificate-oidc-issuer=https://token.actions.githubusercontent.com
```
