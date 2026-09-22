# synaplan-compute

Secure compute sidecar. A Go service that runs untrusted Python or Node in an
ephemeral, T1-hardened container. Synaplan PHP never talks to Docker; it calls
this HTTP API (protocol 1).

**Go/no-go (A0):** own sidecar. See [docs/SPIKE.md](docs/SPIKE.md), [docs/API.md](docs/API.md), and [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md).

Developers start this from the Synaplan checkout with a plain
`docker compose up`. Details: [root README](../../README.md#file-work)
and [docs/COMPUTE.md](../../docs/COMPUTE.md).

## Layout

| Path | Role |
| ---- | ---- |
| `cmd/synaplan-compute` | Production binary |
| `cmd/compute-spike` | A0 prototype that dumps `runner.Hardened` |
| `pkg/contract` | Protocol 1 types, `DisallowUnknownFields`, SSE parser |
| `pkg/config` | `COMPUTE_*` environment (malformed values fail startup) |
| `internal/runner` | The only container factory (`Hardened` + `ValidateHardened`), `Runner` interface |
| `internal/api` | Health, runs (semaphore, cancel, janitor), workspaces |
| `internal/logs` | Per-run stdout/stderr cap (`COMPUTE_LOG_CAP_BYTES`) for the logs SSE |
| `internal/safepath` | Symlink-free path resolution shared by artefacts and workspaces |
| `internal/perm` | Scratch ownership for the sandbox uid (chown or permissive fallback) |
| `internal/auth` | Bearer token, SHA-256 + constant-time compare |
| `internal/egress` | Allow-list policy kept for a future proxy; `Validate` fails closed |
| `tests/hostile` | Corpus that every later PR must keep green |

## Run

```bash
export COMPUTE_AUTH_TOKEN="$(openssl rand -hex 32)"   # ≥ 32 random bytes
make build
./bin/synaplan-compute
# GET http://127.0.0.1:8080/v1/health  (unauthenticated)
```

A checkout started with `docker compose up` does not use that command. It
uses the fixed demo token `synaplan-dev-compute-token-change-me-32b` (see
the root README, File work). Leave that default alone. Set your own
`COMPUTE_TOKEN` before any shared or production host.

If dockerd is unreachable, **health still works**. Runs that pass validation fail with a clean status record (`docker_unavailable`) rather than taking down the process.

### Networking

Runs without `egress.allow` are created with `NetworkMode=none`. A run with
an allow-list (only when `COMPUTE_EGRESS_ENABLED=1`) gets a private internal
network plus a throwaway proxy container (same image, `proxy` subcommand)
that dials the pinned public IPs; anything else is refused with
`egress_not_allowed` (unknown host, wrong port, IP literal, private range,
over the host cap, or flag off). See `docs/THREAT_MODEL.md` for the full
posture.

### Permissions

The service image runs as distroless `nonroot` (uid 65532); the sandbox runs as `COMPUTE_SANDBOX_UID:COMPUTE_SANDBOX_GID` (default `65534:65534`, must be non-root). Inputs must be readable and `/out` / `/workspace` writable by that uid, so scratch directories are created `0770` and files `0660`, owned by the sandbox uid and the service process gid. That keeps listing readable after chown. When the service lacks `CAP_CHOWN` (the default for a non-root container), chown fails and the service falls back to `0777` / `0666` and logs one line at startup:

```
scratch permissions: chown to 65534:<service-gid> (sandbox uid, service gid) failed (...); falling back to world-writable scratch modes
```

Either grant the service `CAP_CHOWN` (or run it as a uid that owns the scratch volume) or keep `COMPUTE_SCRATCH_DIR` / `COMPUTE_WORKSPACES_DIR` on a volume no other local user can reach. Workspace metadata (`<root>/<id>.json`) is never mounted into the sandbox; only `<root>/<id>/data` is.

## HostConfig (T1)

`NetworkMode=none`, `ReadonlyRootfs=true`, `Tmpfs[/tmp]=rw,noexec,nosuid,nodev`, `CapDrop=ALL`, `SecurityOpt=no-new-privileges`, non-root `User`, `Init=true`, PidsLimit / Memory / NanoCPUs set, never privileged, only `/work`, `/out`, `/workspace` bind mounts from the configured roots. Asserted by `TestHostConfigHardening`; every weakening is rejected by `ValidateHardened` (`TestValidateHardenedRejectsWeakenedConfig`). Bind mounts cannot be `noexec` through the Docker API — see the limitations section of the threat model.

## Make

```bash
make build
make test    # go test -race ./...
make lint    # no-shell-guard, go vet, gofmt, node --check on the corpus (+ golangci-lint when installed)
```

## CI

The sidecar is verified with `make test lint` (`go build ./... && go vet ./... && go test -race ./... && gofmt -l cmd internal pkg tests && ./scripts/no-shell-guard.sh && node --check tests/hostile/node/*.js`). All tests are hermetic — no dockerd is required; the live hostile corpus is gated on `COMPUTE_HOSTILE_DOCKER=1`. The repository-root `CI` workflow runs that gate as **Compute Sidecar (Go)** on every push and pull request. A workflow file inside `sidecars/` is not read by GitHub and is therefore not kept here.

## Contract

`image` is a key (`python` / `node`), not a free image reference. `entry` is `{ program, args[] }` from the per-image allow-list (`python`+`sh`, `node`+`sh`). Fixtures live in `tests/fixtures/compute-contract/` and are locked to the server by `pkg/contract` and `internal/api` tests.
