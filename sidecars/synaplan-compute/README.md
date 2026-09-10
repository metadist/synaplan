# synaplan-compute

Wave 4 Secure Compute sidecar (phases A0–A2). A Go service that runs untrusted Python or Node in an ephemeral, T1-hardened container. Synaplan PHP never talks to Docker; it calls this HTTP API.

**Go/no-go (A0):** own sidecar. See [docs/SPIKE.md](docs/SPIKE.md) and [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md).

## Layout

| Path | Role |
| ---- | ---- |
| `cmd/synaplan-compute` | Production binary |
| `cmd/compute-spike` | A0 prototype that dumps `runner.Hardened` |
| `pkg/contract` | Protocol 1 types, `DisallowUnknownFields` |
| `internal/runner` | The only container factory (`Hardened`) |
| `internal/api` | Health, runs, workspaces |
| `internal/auth` | Bearer token, constant-time compare |
| `tests/hostile` | Corpus that every later PR must keep green |

## Run

```bash
export COMPUTE_AUTH_TOKEN="$(openssl rand -hex 16)"   # ≥ 32 bytes
make build
./bin/synaplan-compute
# GET http://127.0.0.1:8080/v1/health  (unauthenticated)
```

If dockerd is unreachable, **health still works**. Runs that pass validation fail with a clean status record (`docker_unavailable`) rather than taking down the process.

## HostConfig (T1)

`NetworkMode=none`, `ReadonlyRootfs=true`, `Tmpfs[/tmp]=rw,noexec,nosuid`, `CapDrop=ALL`, `SecurityOpt=no-new-privileges`, `User=65534:65534`, `Init=true`, never privileged. Asserted by `TestHostConfigHardening`.

## Make

```bash
make build
make test
make lint    # scripts/no-shell-guard.sh (+ golangci-lint when installed)
```

## Contract

`image` is a key (`python` / `node`), not a free image reference. `entry` is `{ program, args[] }` from the per-image allow-list (`python`+`sh`, `node`+`sh`). Fixtures live in `tests/fixtures/compute-contract/`.
