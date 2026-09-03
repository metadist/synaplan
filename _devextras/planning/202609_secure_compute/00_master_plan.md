# Secure Compute — a sandboxed workspace for the AI — master plan

**Status:** Decisions ticked 2026-09-03 (log in [`STATUS.md`](./STATUS.md)).
Track 5 of [`../20260903_roadmap.md`](../20260903_roadmap.md).
The sidecar (Phase A) can start as a spike at any time; the Synaplan
integration (Phase B) depends on track 4's approval policy and track 2's tool
allow-list.
Sprint files: [`01_phase_a0_spike_and_threat_model.md`](./01_phase_a0_spike_and_threat_model.md) …
[`08_phase_b4_hardening_and_ga.md`](./08_phase_b4_hardening_and_ga.md).
**Owner surface:** none new for users — results appear as ordinary files and
a run card in the chat. Operate → Feature status shows compute health;
Operate → System config holds limits.
**Flag:** `COMPUTE.ENABLED` — default off in code and seeder. Env
`COMPUTE_URL` (unset = feature absent).
**Related:**

- [`../20260829-desktop-agent-client/00_master_plan.md`](../20260829-desktop-agent-client/00_master_plan.md)
  — the *client-side* answer to "let the AI run code"; §12 explicitly leaves
  server-side execution out. This track is the server-side counterpart with
  a container boundary instead of a user's trust step
- [`../20260902-office-docs/00_master_plan.md`](../20260902-office-docs/00_master_plan.md)
  Decision 1 — "never exec `soffice` in PHP; sidecar over HTTP" (the rule we
  generalize)
- [`../202609_tools_approval_workflows/00_master_plan.md`](../202609_tools_approval_workflows/00_master_plan.md)
  — `code_run` is a `write`-class tool
- `/wwwroot/synaplan-opencloud/backend` — Go sidecar precedent in the family

---

## 0. Decision checklist (tick before any code)

| # | Decision | Proposed default | Agree? |
| - | -------- | ---------------- | ------ |
| 1 | **New repo `synaplan-compute`, Go, single static binary, own image.** Not PHP: it needs the Docker/containerd API, process trees, log streaming and cgroup limits. Rust is an acceptable alternative; Go is proposed for Docker SDK maturity and the existing Go precedent. | Go sidecar | ✅ 2026-09-03 |
| 2 | **The PHP backend never touches Docker.** Only the compute container has the socket (or a containerd/`runsc` handle). PHP holds `ComputeClient` (HTTP) and nothing else. | Locked | ✅ 2026-09-03 |
| 3 | **Every run is an ephemeral, hardened container:** `--network none` by default, read-only rootfs, `tmpfs /tmp`, `cap-drop ALL`, `no-new-privileges`, seccomp default profile, non-root uid, `pids-limit`, memory and CPU limits, wall-clock timeout that kills the whole tree. | Tier 1 hardening baseline | ✅ 2026-09-03 |
| 4 | **Isolation tiers:** T1 = hardened Docker (baseline, everywhere); T2 = gVisor `runsc` runtime when installed (recommended for Synaplan Cloud and hosters); T3 = microVM (Firecracker / Kata) documented for hosters who need it. The API is identical across tiers; the tier is reported in health. | T1 always, T2 recommended | ✅ 2026-09-03 |
| 5 | **No secrets inside the sandbox.** The compute service holds no Synaplan credentials; PHP **pushes** input files into a run and **pulls** artefacts out. Egress is off unless a run's policy allows an allow-list of hosts (track 4 policy, S4). | Push in, pull out, no egress | ✅ 2026-09-03 |
| 6 | **Runtime images are ours, pinned and minimal.** v1 ships **two** images: `synaplan-compute-python` (Python 3.12 + pandas, numpy, matplotlib, openpyxl, python-docx, python-pptx, pypdf, pillow) and `synaplan-compute-node` (Node 22 LTS + a small curated set: `xlsx`, `docx`, `pdf-lib`, `sharp`). LibreOffice image is v2. No package installation at run time in v1 (offline by construction). | Python + Node | ✅ 2026-09-03 (Node promoted to v1) |
| 7 | **Workspaces:** `run` (ephemeral, deleted after artefact pull) and `user` (persistent per Synaplan user, quota in MB, mounted read-write into that user's runs only). Workspace ids are opaque; PHP maps user → workspace id and never passes a path. | Two kinds | ✅ 2026-09-03 |
| 8 | **Contract `protocol: 1`, frozen after Phase A** with committed fixtures, the same discipline as the desktop job contract. A run request is `{ workspace, image, entry: {program, args[]}, files[], limits, egress }`. No free-form shell string crosses the API; if the model wants a shell it writes a script file and runs `python`/`node`/`sh` on that file **inside** the sandbox — the sandbox is the boundary, the API is not a shell. | Frozen contract | ✅ 2026-09-03 |
| 9 | **Results are untrusted.** Artefacts: size cap, MIME allow-list, filename sanitizing, provenance `source: compute` on `BFILES`; stdout/stderr truncated and escaped; nothing from a run is executed by PHP. | Locked | ✅ 2026-09-03 |
| 10 | **Policy: `code_run` is `write`-class.** Interactive default `auto` for the user's own chat (the sandbox is the safety); unattended default `approve`; egress or persistent-workspace writes never loosen below the tool policy. Configurable per assistant / group via track 4. | Interactive auto, unattended approve | ✅ 2026-09-03 |
| 11 | **Quotas per user:** concurrent runs, runs/hour, CPU-seconds/day, workspace MB — enforced by PHP (rate-limit lanes per `BUSERLEVEL`, IAM group overrides) *and* by the compute service (hard caps). | Two layers | ✅ 2026-09-03 |
| 12 | **Audit every run** (`BCOMPUTERUNS`: who, assistant, image, limits, exit code, duration, bytes in/out, artefact ids, egress hosts). Metadata only, no stdout content in the audit table. | Locked | ✅ 2026-09-03 |
| 13 | **Evaluate before building:** a one-week spike (S0) compares own Go sidecar vs. existing open-source runners (E2B infra, Piston, a Jupyter-kernel gateway, WASM/pyodide) on isolation, footprint, ops complexity and license. Default expectation: own sidecar wins on footprint and ops; the spike records the go/no-go in `STATUS.md`. | Spike first | ✅ 2026-09-03 |
| 14 | **Deployment:** compose profile `compute` for dev/self-host (same host, T1); **Synaplan Cloud (`web.synaplan.com`) requires T2 on a separate compute node** (dedicated host or separate Docker daemon with gVisor) before the flag is enabled there — T1 on the shared web hosts is not an accepted Cloud posture. `synaplan-platform` documents the node (private repo). | T2 + separate node required for Cloud | ✅ 2026-09-03 (tightened) |
| 15 | **Schema (ask recorded):** `BCOMPUTERUNS`, `BCOMPUTEWORKSPACES` (Phase B S2/S3). Galera-safe `addSql`. | Ask recorded | ✅ 2026-09-03 |
| 16 | **Mobile:** all `backend-only` except the run card (`ota-candidate`). | Locked | ✅ 2026-09-03 |

---

## 1. The concept in three sentences

> Sometimes the AI needs to *do* something with your files — recalculate a
> spreadsheet, convert a document, draw a chart — not just talk about them.
> Secure compute gives the AI a locked room for that: it gets copies of the
> files you chose, works inside the room, and hands back the results as new
> files you can preview and download. The room has no internet, no access to
> anything else, and is torn down when the job is done.

---

## 2. Why this exists

`PlatformCapabilityInventory` lists `code_execution` as unavailable, and the
honest answer to "make me a chart from this CSV" today is a text description
or a desktop skill on the user's own machine. The partner review asked for a
"secure workspace to process files, run code, test outputs, and create new
artifacts", sandboxed per user or per task, with previewable, auditable
outputs. Document generation (`DocumentGeneratorService`) already produces
DOCX/XLSX/PPTX, but only from templates PHP knows; compute makes the long tail
possible without new PHP for every case.

---

## 3. What already exists (do not rebuild)

| Piece | State | Role here |
| ----- | ----- | --------- |
| HTTP sidecar pattern (Tika, `synaplan-tts`, Collabora `OFFICE_CONVERT_URL`) | Shipped | Same shape: URL env, health, opt-in profile |
| Go service precedent (`synaplan-opencloud/backend`) | Shipped | Repo layout, CI, image build |
| Desktop job contract (`protocol: 1`, closed enum, fixtures) | Shipped | Contract discipline copied; **not** the same API |
| `MediaJob` (Redis-backed async media) + Messenger workers | Shipped | Async run tracking pattern; long runs are worker jobs, not request-bound |
| `TaskRunner` / `Capability` / `SkillCatalog` | Shipped | `code_run` capability + `CodeRunRunner` |
| `GatewayToolLoop` / `OpenAiGatewayToolLoop` | Shipped | `code_execution` tool for the gateways (opt-in per key scope) |
| `GeneratedDocumentStore` → `BFILES` (`source=generated`) | Shipped | Artefacts land the same way with `source=compute` |
| Files UI preview / download / office editor | Shipped | Artefact preview for free |
| `SsrfGuard` | Shipped | Applies to egress allow-lists resolution on the PHP side |
| Rate-limit lanes (`RATELIMITS_*` in `BCONFIG`) | Shipped | Compute quotas are new keys in the same lanes |
| `PlatformCapabilityInventory` | Shipped | Flips `code_execution` to available when `COMPUTE_URL` + flag are set |

---

## 4. Target architecture

```text
  Synaplan (PHP)                                 synaplan-compute (Go)                 Docker / runsc
 ┌──────────────────────────┐   HTTP protocol:1  ┌──────────────────────────┐        ┌────────────────┐
 │ CodeRunRunner            │ ────────────────►  │ POST /v1/runs            │ ─────► │ ephemeral      │
 │ code_execution tool      │  files (multipart) │  - pull image (pinned)   │        │ container      │
 │ ComputeClient            │ ◄────────────────  │  - mount run/user ws     │        │  --network none│
 │ BCOMPUTERUNS (audit)     │  stream logs (SSE) │  - apply limits, timeout │        │  ro rootfs …   │
 │ artefacts → BFILES       │ ◄────────────────  │ GET /v1/runs/{id}/...    │ ◄───── │ stdout/stderr  │
 └──────────────────────────┘  artefacts (pull)  │ workspaces + quotas      │        └────────────────┘
                                                 │ GET /v1/health (tier)    │
                                                 └──────────────────────────┘
```

### 4.1 Contract (`protocol: 1`, Phase A)

| Method | Path | Purpose |
| ------ | ---- | ------- |
| `GET` | `/v1/health` | `{ protocol: 1, tier: "docker" \| "gvisor" \| "microvm", images: [...], capacity }` |
| `POST` | `/v1/workspaces` · `DELETE /v1/workspaces/{id}` · `GET …/usage` | Persistent user workspaces (quota MB) |
| `POST` | `/v1/runs` (multipart: `request.json` + files) | Start a run; returns `runId` |
| `GET` | `/v1/runs/{id}` | Status, exit code, timing, resource usage |
| `GET` | `/v1/runs/{id}/logs` (SSE) | stdout/stderr stream (truncated server-side) |
| `GET` | `/v1/runs/{id}/artefacts` · `GET …/artefacts/{name}` | List / download outputs from `/out` |
| `DELETE` | `/v1/runs/{id}` | Cancel / clean up |

`request.json`:

```json
{
  "protocol": 1,
  "workspace": { "kind": "run" } ,
  "image": "python",
  "entry": { "program": "python", "args": ["main.py"] },
  "files": [ { "name": "data.csv", "role": "input" }, { "name": "main.py", "role": "input" } ],
  "limits": { "timeoutSec": 60, "memoryMb": 512, "cpu": 1.0, "pids": 128, "outputMb": 50 },
  "egress": { "allow": [] }
}
```

`deny_unknown_fields`; `image` is a key into the service's pinned image
map, never a free image reference; `program` must be in the image's
allow-list (`python`, `node`, `sh`, `libreoffice` later). Authentication
between PHP and compute: shared bearer token from env + network policy
(compute is not published to the outside).

### 4.2 Synaplan integration (Phase B)

- `CodeRunRunner` (`Capability::CodeRun`): planner picks it for "compute
  with files" intents; inputs = selected conversation files; the model
  writes `main.py` (or the requested script) as a step output; the runner
  submits, streams progress to the multitask card, ingests artefacts.
- `code_execution` gateway tool (Anthropic and OpenAI gateways): same runner,
  opt-in per API-key scope `compute:run`.
- Artefacts → `BFILES` with `source=compute`, `originKind=artefact`, linked
  to the message; preview via the existing files UI; "Re-run with changes"
  re-submits with the edited script.
- Run card in chat: status, elapsed, exit code, truncated logs (collapsed),
  artefact chips, "Open workspace" (S3).
- Quotas: PHP checks lanes before submit; compute enforces hard caps; both
  refusals are readable in the card.

### 4.3 Threat model (Phase A S0 deliverable; headline rows)

| Threat | Control |
| ------ | ------- |
| Container escape | T1 hardening; T2 gVisor recommended; separate compute node for T3 |
| Data exfiltration | No egress by default; allow-list resolved and pinned by PHP through `SsrfGuard`; compute refuses hosts not in the request |
| Resource exhaustion (fork bomb, disk fill, infinite loop) | `pids-limit`, `memory`, `cpu`, `outputMb`, `tmpfs` size, wall-clock kill of the process tree |
| Cross-user access | Workspace ids opaque and owned; PHP never passes paths; user workspace mounted only into that user's runs |
| Poisoned artefacts | Untrusted: MIME allow-list, size cap, no execution by PHP, provenance shown |
| Prompt-injected code | The policy (track 4) decides; the sandbox limits blast radius; audit shows what ran |
| Compute service compromise | Holds no Synaplan credentials; can only answer PHP; not reachable from the internet |

A hostile-script corpus (`tests/hostile/*.py`: fork bomb, `/proc` walk,
disk fill, DNS attempt, `os.setuid`, symlink out of `/work`) runs in CI on
the compute repo and must be green on T1.

---

## 5. UI

No new page. In the chat: run card; in Files: artefacts with a *compute*
badge. Operate → Feature status: compute health (tier, capacity, image
versions). Operate → System config: default limits, quotas per tier, egress
allowed globally (yes/no).

Words (en / de / es / fr / tr): Run / Ausführen / Ejecutar / Exécuter /
Çalıştır; Result files / Ergebnisdateien / Archivos de resultado / Fichiers
de résultat / Sonuç dosyaları; Workspace / Arbeitsbereich / Espacio de
trabajo / Espace de travail / Çalışma alanı. Never "sandbox", "container",
"gVisor", "stdout" in primary copy — helper text may.

---

## 6. Compatibility invariants

| # | Invariant | Proof |
| - | --------- | ----- |
| C1 | `COMPUTE_URL` unset or flag off ⇒ Synaplan behaves as today; `code_execution` stays "unavailable"; no planner capability listed; snapshots untouched | Feature suite + characterization |
| C2 | PHP containers never mount `docker.sock`; grep-able check in compose files and CI | Static check |
| C3 | No run without an owner, an image key from the map and limits within the instance caps | Contract tests |
| C4 | No egress unless the request allows a host list resolved by PHP; compute rejects unknown hosts | Compute integration tests |
| C5 | Artefacts are untrusted: never executed, never auto-vectorized without the existing upload path and quotas | Ingest tests |
| C6 | Hostile corpus green on T1 in every compute PR | Compute CI |
| C7 | Contract frozen after Phase A; PHP conforms; a change is `protocol: 2` | Fixture checksum test in both repos |
| C8 | Widget, mobile, `/v1` gateways unchanged unless `compute:run` scope is granted | Suites + mobile-impact |

---

## 7. Phases and sprints

**Phase A — the sidecar (repo `synaplan-compute`)**

| Sprint | Content | Exit |
| ------ | ------- | ---- |
| **A0 — Spike & threat model** | One week: own Go sidecar prototype vs. OSS runners on isolation / footprint / ops / license; threat model document; decision rows 1, 4, 13 confirmed | Go/no-go recorded in `STATUS.md` |
| **A1 — Runner MVP** | Repo, CI (lint, tests, image build), `POST /v1/runs` on T1 with the Python image, logs SSE, artefact pull, hostile corpus, `protocol: 1` fixtures | Corpus green; a CSV→chart script returns a PNG |
| **A2 — Workspaces & tiers** | Persistent user workspaces with quotas, cleanup, T2 gVisor detection, health/capacity, bearer auth, structured audit log | Same request runs on T1 and T2; quota refusal is a clean 4xx |
| **A3 — Freeze** | Contract review, fixtures committed, `docs/` in the repo, image signing, compose profile block for `synaplan/` and service block for `synaplan-platform` | Contract frozen |

**Phase B — Synaplan integration (repo `synaplan/`)**

| Sprint | Content | Exit |
| ------ | ------- | ---- |
| **B1 — Client & capability** | `ComputeClient`, `BCOMPUTERUNS`, `CodeRunRunner` + `Capability::CodeRun`, flag, `PlatformCapabilityInventory` flip, run card, artefacts → `BFILES`, quotas in rate-limit lanes | "Make a chart from this CSV" yields a PNG in the chat on a dev instance |
| **B2 — Tools & policy** | `code_execution` gateway tool + `compute:run` scope; track 4 policy wiring (interactive `auto`, unattended `approve`); assistant allow-list (track 2) | A scheduled task using compute pauses for approval; an assistant without `code_run` cannot plan it |
| **B3 — Workspaces & egress** | `BCOMPUTEWORKSPACES`, "Open workspace" file browser (reuses Files UI), per-run egress allow-list through `SsrfGuard`, admin limits UI, docs (`docs/COMPUTE.md`, docs site, platform guide) | A user's workspace persists across runs and is visible in Files; egress works only for allowed hosts |
| **B4 — Hardening & GA** | Load test, capacity signals in Feature status, cleanup jobs, seeder flag on for new installs | Success criteria met |

Sprint files: [`A0`](./01_phase_a0_spike_and_threat_model.md) ·
[`A1`](./02_phase_a1_runner_mvp.md) · [`A2`](./03_phase_a2_workspaces_and_tiers.md) ·
[`A3`](./04_phase_a3_freeze.md) · [`B1`](./05_phase_b1_client_and_capability.md) ·
[`B2`](./06_phase_b2_tools_and_policy.md) · [`B3`](./07_phase_b3_workspaces_and_egress.md) ·
[`B4`](./08_phase_b4_hardening_and_ga.md).

Cut line: B3 egress first (offline-only is a fine v1), then persistent
workspaces (run-only). Never cut the hostile corpus or C2.

---

## 8. Rollout

1. Phase A ships a complete, tested sidecar with no consumer (same reasoning
   as the desktop plan: the alternative is a client shaping the sandbox under
   deadline).
2. B1 merges behind `COMPUTE.ENABLED = off`; Synaplan Cloud enables it on a
   separate compute node (T2) for internal users first.
3. Self-host docs describe the `compute` profile (T1) and its limits
   honestly: "same host, hardened container — good for trusted teams; use a
   separate node with gVisor for untrusted tenants".
4. Seed flag on for new installs after B4 **only when `COMPUTE_URL` is set**
   (the flag alone does nothing).
5. Rollback: flag off; run history and artefacts remain as files.

---

## 9. Out of scope (v1)

- Interactive notebooks / long-lived kernels; GPU runs; distributed jobs.
- Package installation at run time (`pip install`) — images are curated.
- Running user-uploaded binaries or Docker images.
- Compute for widget visitors or anonymous channels.
- Replacing document generation (`DocumentGeneratorService` stays the
  template path; compute is the long tail).
- Desktop skills running on the server — those remain on the user's device.

---

## 10. Success criteria

1. Hostile corpus: every script is contained on T1 and T2 (no host effect,
   killed within the limit, readable failure).
2. "Recalculate this XLSX with a 5 % increase and give me the new file"
   produces a downloadable XLSX in the chat with `source: compute`.
3. A user without `compute:run` scope on an API key cannot invoke
   `code_execution` through `/v1/messages`; an assistant without `code_run`
   never plans it.
4. A scheduled task that uses compute pauses for approval by default and
   runs after approval; the audit row shows image, limits and artefacts.
5. Flag off / URL unset: gate green, snapshots untouched, `code_execution`
   unavailable, no `docker.sock` in any PHP container.
6. Contract fixtures identical in both repos (checksum test).

---

## 11. Decisions from the 2026-09-03 review (formerly open questions)

| # | Question | Decision |
| - | -------- | -------- |
| 1 | Go or Rust? | **Go** — Docker SDK maturity, `synaplan-opencloud/backend` precedent, team familiarity. A0 still prototypes in Go only; Rust is not re-evaluated unless A0 finds a blocking gap. |
| 2 | Adopt an existing OSS runner? | **Only if the A0 spike shows parity** on isolation (≥ T1), file push/pull, egress policy, multi-arch images, permissive license, and no mandatory extra infrastructure (Firecracker, Kubernetes). Otherwise own sidecar. |
| 3 | `sh` in the in-sandbox allow-list? | **Yes.** The container is the boundary; the API still carries no shell string (row 8). |
| 4 | Default interactive policy | **`auto`** for the user's own chat; hosters can set `approve` instance-wide; unattended stays `approve`. |
| 5 | Persistent user workspaces | **Yes, B3**, default off per instance (`COMPUTE.WORKSPACES_ENABLED`). |
| 6 | Egress | **Offline by default; per-run allow-list via track-4 policy in B3.** |
| 7 | Runtime images | **Python + Node in v1**; LibreOffice image v2 (row 6 updated). |
| 8 | Cloud posture | **T2 on a separate compute node is required** before enabling on `web.synaplan.com` (row 14 tightened). |
