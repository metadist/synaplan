# Sizing

Memory = `maxConcurrent × memoryMb + 256 MB`  
CPU = `maxConcurrent × cpu`  
Scratch disk = `maxConcurrent × outputMb × 2` plus persistent workspaces.

| Node | Suggested defaults |
| ---- | ------------------ |
| 4 GB laptop / T1 compose | `COMPUTE_MAX_CONCURRENT=2`, cap `memoryMb=512`, `cpu=1.0`, `outputMb=50` |
| 16 GB compute node / T2 | `COMPUTE_MAX_CONCURRENT=8`, cap `memoryMb=2048`, `cpu=2.0`, `outputMb=200` |

PHP quotas (`COMPUTE_RUNS_*`, `COMPUTE_CONCURRENT`) sit **below** these
hard caps. The sidecar is the ceiling.

## Load test (CS26)

`cmd/compute-load` hammers a running sidecar with N concurrent runs from
`scenarios/mixed.json` (60 % CSV → chart, 20 % XLSX recalculation, 10 %
timeout kill, 10 % huge stdout) and checks the pass bars below. It exits
non-zero when any bar fails, so the nightly and release checklists can
just run it.

```bash
cd sidecars/synaplan-compute
COMPUTE_TOKEN=... go run ./cmd/compute-load \
  -url http://localhost:8080 -n 32 -concurrency 4
```

It needs the sidecar URL + token and, for the leftover-container bar,
docker access (same socket the sidecar uses). Without docker it reports
`leftover-containers=unknown` and skips that bar with a warning.

### Pass bars

| # | Bar | Rationale |
| - | --- | --------- |
| 1 | No run exceeds its `timeoutSec` by more than 2 s (server timestamps) | Timeout kills must be prompt, not eventual |
| 2 | Host 1-minute load average stays below `cores × 1.5` | Noisy-neighbour ceiling on shared hosts |
| 3 | Zero containers left afterwards (run label) | Every run path cleans up, including kills and timeouts |
| 4 | `capacity_exceeded` is the only refusal seen for the overflow | Overload degrades to one honest code, never 500s or silent drops |

Tune with `-overtime`, `-load-factor`, `-n`, `-concurrency`, `-timeout`.
Paste the summary into the release notes of the compute tag used for GA.

### Nightly

`.github/workflows/compute-nightly.yml` runs the T1 mix (N=32,
concurrency 4) against a fresh sidecar on ubuntu-latest. `make images`
tags `:local`. `scripts/nightly-up.sh` starts the sidecar on those tags
with the fixed local demo token and host-path scratch — the same contract
as `docker compose up`. It does not rewrite the published digest pins.
The T2 leg runs only when the repo variable
`COMPUTE_T2_NIGHTLY_ENABLED` is `true` (needs a self-hosted gVisor runner)
— until the T2 node exists, T2 evidence comes from the manual runbook
below, not from CI.
