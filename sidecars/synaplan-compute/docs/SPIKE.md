# Spike scoring matrix (A0 / CP1–CP6)

**Date:** 2026-09-10  
**Question:** own Go sidecar (Docker SDK) vs E2B, Piston, Jupyter Kernel Gateway, WASM/Pyodide.  
**Hard rows fail closed.** A fail on a hard row removes the candidate regardless of total.  
**Go/no-go: own Go sidecar.**

Scratch layout used by the prototype and carried into A1: `/work` and `/out` are bind-mounted from `COMPUTE_SCRATCH_DIR/<runId>/{work,out}` — a path dockerd can see (host directory or the named volume `synaplan_compute_scratch` mounted at the same path in the compute container and on the daemon). PHP never sees that path.

The HostConfig literal is `internal/runner.Hardened`. `cmd/compute-spike` dumps it. A1 creates containers only through that function.

## Candidates

| ID | Candidate | What was evaluated |
| -- | --------- | ------------------ |
| S | Own Go sidecar (`synaplan-compute`, Docker SDK) | Prototype `POST /v1/runs` + `TestHostConfigHardening` + corpus headers |
| E | E2B infrastructure (Firecracker) | Public architecture, license, required control plane |
| P | Piston | HTTP execute API, isolate/nsjail, no first-class `/work` `/out` |
| J | Jupyter Kernel Gateway | Long-lived kernels, notebook protocol |
| W | WASM / Pyodide | In-process interpreter, no Node image, native wheels |

## Scoring

| Criterion | Hard? | S Own sidecar | E E2B | P Piston | J Jupyter KG | W WASM/Pyodide |
| --------- | ----- | ------------- | ----- | -------- | ------------ | -------------- |
| Isolation ≥ T1 | yes | **PASS** — NetworkMode none, ReadonlyRootfs, tmpfs noexec/nosuid, CapDrop ALL, no-new-privileges, uid 65534, Init, never privileged. Corpus contained on the prototype HostConfig. | PASS (Firecracker VM) | **FAIL** — isolate/nsjail is not the T1 HostConfig list; no read-only rootfs + cap-drop ALL + no-new-privileges as a single factory | **FAIL** — kernels are not ephemeral hardened containers | PASS (WASM sandbox) |
| Push-in / pull-out files | yes | **PASS** — multipart files land in `/work`; `/out` listed/downloaded without a volume PHP can reach | PASS (their filesystem API) | **FAIL** — source is a string field; no `/work` `/out` artefact channel without a shared FS or a rewrite | **FAIL** — notebooks share a kernel filesystem; PHP would need a volume or contents API that is not pull-out | **FAIL** — no real `/work` `/out` without extra glue; native pandas/matplotlib/sharp are not in-WASM |
| Egress policy | yes | **PASS** — empty allow ⇒ NetworkMode none; non-empty refused when `COMPUTE_EGRESS_ENABLED=false`; pinned-IP CONNECT proxy | PASS (configurable) | Partial — network is a runner flag, not a per-run pinned-IP allow-list | **FAIL** — kernel can use host network | N/A once push/pull failed |
| Multi-arch images | no | PASS — buildx `linux/amd64,linux/arm64` for python + node | Partial (Firecracker images are x86-centric) | PASS | PASS | PASS (wasm32) |
| Footprint | no | Idle RSS of `synaplan-compute` ~12–20 MB; python image (slim + wheels) ~400–600 MB; T1 cold start target < 2 s | Heavy: Firecracker + control plane + snapshot store | Moderate: API + isolate | Heavy: Jupyter stack | Small runtime, large wasm blob |
| Ops complexity | no | One extra container + docker.sock on the compute node; env file | Firecracker, snapshot pipeline, extra daemons | API + language packs | Jupyter + kernels + auth | Build pipeline for wheels |
| License | yes | **PASS** — Apache-2.0 / MIT sidecar | Apache-2.0 code; hosted product is not required but the infra assumes Firecracker | MIT | BSD | MPL-2.0 Pyodide (permissive enough) |
| No mandatory extra infra | yes | **PASS** — one Docker host | **FAIL** — Firecracker (and typically a dedicated node / K8s) is required | PASS | PASS (but isolation already failed) | PASS |

## Corpus (CP4 / CP5)

Own sidecar: every `tests/hostile/*.py` and `tests/hostile/node/*` header matches the T1 control (see `docs/THREAT_MODEL.md`). Live docker execution is the CI `hostile` job; header parsing is unit-tested without dockerd.

Candidates that failed a hard row were not measured further (cold start, RSS, image size), per CP5.

| Script | Own sidecar expected |
| ------ | -------------------- |
| `fork_bomb.py` | failed / `pids_limit` |
| `proc_walk.py` | succeeded; no host PIDs |
| `disk_fill.py` | failed / `output_limit` |
| `dns_attempt.py` | succeeded (DNS fails; no packet) |
| `setuid.py` | succeeded (`PermissionError`) |
| `symlink_out.py` | succeeded; artefact list omits symlink |
| `long_sleep.py` | failed / `timeout` |
| `huge_stdout.py` | succeeded; `truncated.stdout = true` |

## Totals

Hard rows passed: **S = 5/5**. E = 4/5 (fails extra infra). P = 2/5. J = 1/5. W = 2/5.

Soft rows: own sidecar is the smallest ops footprint that still hits T1.

## Go / no-go (CP6)

**Chosen path: own Go sidecar (`synaplan-compute`).**

Confirms master plan §0:

1. Go sidecar — no blocking Docker SDK gap; `Hardened` is a `container.HostConfig` literal. Rust is not re-evaluated.
4. Tiers — T1 always (this HostConfig); T2 `runsc` when the daemon lists it; T2 required for Cloud.
13. Spike done — matrix filled; own sidecar wins on footprint and ops with full hard-row parity.

OSS runners are **not** adopted. Protocol `1` is unchanged. A1–A3 proceed on this repository.
