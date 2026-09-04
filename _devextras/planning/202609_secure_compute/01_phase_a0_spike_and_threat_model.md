# Sprint A0 — Spike and threat model

**Phase A (`synaplan-compute`), sprint 1 of 4.** Steps `CP1`–`CP6`.

**Goal:** One calendar week that ends in a written go/no-go. Deliverables: a
scoring matrix that compares an own Go sidecar with four named open-source
runners, a throwaway Go prototype that runs one Python script on hardened
Docker through `POST /v1/runs`, a threat model document, and the definition
of the hostile-script corpus that every later compute PR must keep green.
**Depends on:** master plan §0 rows 1, 3, 4, 13; §11 decisions 1–3. Nothing
in `synaplan/`.
**Unlocks:** A1. A "no-go" for the own sidecar re-plans A1–A3 around the
chosen runner; it never re-opens Phase B or the contract shape (§0 row 8).
**Repos:** new `synaplan-compute` (created as a scratch repository here;
`CP6` decides whether its history is kept or A1 starts clean).
**Flag:** none — nothing reaches a Synaplan install in this sprint.

---

## 0. Why this sprint exists

Row 13 says "evaluate before building" and §11 decision 2 says an OSS runner is adopted **only on
proven parity**. Parity has to be measured, not argued, so this sprint puts every candidate in front
of the same hostile scripts and the same push-in / pull-out file test. The prototype is not the MVP:
its purpose is to produce the exact `HostConfig` list that A1 copies verbatim, and to find out early
whether the Docker SDK path has a blocking gap (the only thing that would make §11 decision 1
re-evaluate Rust). The threat model is written now because A1's hardening flags and A2's workspace
ownership rules are derived from its rows, not the other way round.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `00_master_plan.md` §0 rows 3–9, §4.3, §11 | Binding decisions; §4.3 is the table `CP3` expands |
| `/wwwroot/synaplan-opencloud/backend/` (`cmd/`, `internal/`, `pkg/`, `Makefile`, `.golangci.yml`) and `.github/workflows/ci.yml` | Go layout, lint config and CI shape the prototype copies |
| `/wwwroot/synaplan-desktop/scripts/no-shell-guard.sh` | The no-shell-string discipline; the compute API carries `{program, args[]}` only, `sh` is a program *inside* the sandbox |
| `synaplan/backend/src/Service/SelfAware/PlatformCapabilityInventory.php` (`KNOWN_ABSENT`, id `code_execution`) | The sentence this track exists to change; read the wording so the threat model answers it |
| `synaplan/docker-compose.yml` (`collabora`, `tts` services, `profiles:`) | How sidecars are opted in today; T1 must fit the same shape |
| `synaplan/backend/src/Service/Security/SsrfGuard.php` | Egress resolution stays in PHP; the spike only defines what compute receives |
| `../20260829-desktop-agent-client/03_phase_a3_jobs_and_checkin.md` §2.5 | Contract-freeze discipline this track copies |

---

## 2. Developer steps

### 2.1 Scoring matrix and candidates (`CP1`)

`docs/SPIKE.md` in the scratch repo. Candidates: own Go sidecar (Docker SDK),
E2B infrastructure (Firecracker), Piston, Jupyter Kernel Gateway, WASM /
Pyodide. Every candidate is scored on the same rows; a fail on a hard row
removes the candidate regardless of its total.

| Criterion | Hard? | Pass bar |
| --------- | ----- | -------- |
| Isolation ≥ T1 | yes | All §0 row 3 controls present or configurable; corpus (`CP4`) contained |
| Push-in / pull-out files | yes | Input files land in `/work` before start; `/out` retrievable without a shared volume PHP can reach |
| Egress policy | yes | Offline by default; per-run host allow-list possible without rebuilding images |
| Multi-arch images | no | `linux/amd64` + `linux/arm64` for the Python and Node images |
| Footprint | no | Idle RSS of the service, image size, cold-start of a run (target < 2 s on T1) |
| Ops complexity | no | Number of extra daemons, config files, and moving parts a hoster must run |
| License | yes | Permissive (MIT / Apache-2.0 / BSD); no network-copyleft, no "source available" |
| No mandatory extra infra | yes | Runs on one Docker host; Kubernetes, Firecracker or a message broker must not be required |

### 2.2 Go-only prototype (`CP2`)

`cmd/compute-spike/main.go`, `internal/runner/docker.go`, `internal/runner/hostconfig.go`.
One endpoint, `POST /v1/runs` (multipart: `request.json` + files), that
creates a container from a locally built `synaplan-compute-python` image,
copies the files into `/work`, starts it with the row-3 hardening, waits with
a deadline, returns `{ exitCode, stdout, stderr, out: [names] }`. No auth, no
SSE, no workspaces. `hostconfig.go` is the only file carried into A1 as-is:
it holds the `container.HostConfig` literal and a `go test` that asserts
every field (`TestHostConfigHardening`). Also settle the scratch-dir question
(`/work` and `/out` bind-mounted from a path dockerd can see) and record it.

### 2.3 Threat model document (`CP3`)

`docs/THREAT_MODEL.md`. The §4.3 table expanded to: threat, attacker
position (script author / prompt injector / compromised compute host /
malicious artefact), asset, T1 control, T2 control, residual risk, test that
proves the control (a corpus script or a Go test name). Adds rows §4.3 does
not have: timing side channels between concurrent runs (accepted on T1,
mitigated by T2/T3), image supply chain (digest pinning, cosign in A3), log
injection through stdout (escaped and truncated by compute and again by
PHP), and denial of service against the compute API itself (bearer auth +
capacity cap in A2).

### 2.4 Hostile corpus definition (`CP4`)

`tests/hostile/README.md` plus one script per row. Each script declares its
expected outcome in a header comment that the harness parses.

| Script | Expected result |
| ------ | --------------- |
| `fork_bomb.py` | Killed by `pids-limit`; run `failed`, reason `pids_limit`; host load unchanged |
| `proc_walk.py` | `/proc` shows only the run's own processes; no host PIDs, no `/proc/1/environ` of the host |
| `disk_fill.py` | Stops at the `/work` / `/tmp` size caps; reason `output_limit` or `ENOSPC` inside; host disk unchanged |
| `dns_attempt.py` | Name resolution fails; no packet leaves (checked with a host-side `tcpdump` in CI) |
| `setuid.py` | `os.setuid(0)` raises `PermissionError`; `no-new-privileges` holds |
| `symlink_out.py` | Symlink from `/out` to `/etc/passwd` is not followed on artefact pull; artefact list omits it |
| `long_sleep.py` | Killed at `timeoutSec`; run `failed`, reason `timeout`; no zombie container |
| `huge_stdout.py` | Stream truncated server-side at the log cap; run finishes; `truncated.stdout = true` |
| `node/` mirror of the above in JavaScript | Same expectations on the Node image |

### 2.5 Run every candidate against the corpus (`CP5`)

Fill the matrix from `CP1` with measured values, not estimates. For each
candidate record the corpus result per script, cold-start time, idle RSS,
image size, and the exact list of extra services required. Candidates that
cannot take a file in and give a file back without a shared filesystem are
marked failed on the hard row and not measured further.

### 2.6 Go/no-go (`CP6`)

`STATUS.md` in `synaplan/_devextras/planning/202609_secure_compute/` gets one
decision entry: the chosen path, the matrix totals, and the confirmation of
§0 rows 1 (Go), 4 (tiers, T2 recommended, T2 required for Cloud) and 13
(spike done). If an OSS runner wins, the entry also lists which A1–A3 steps
change and confirms that the `protocol: 1` shape (§0 row 8) is unchanged.

---

## 3. Tests and invariants

- `TestHostConfigHardening` (Go, `internal/runner`): every §0 row 3 field
  asserted on the literal; this becomes the first test of A1.
- `tests/hostile/run.sh`: runs the corpus against the prototype; green on
  T1 is the exit gate. This is the seed of **C6**.
- No `docker.sock` string in anything destined for `synaplan/` (**C2**);
  the spike touches no compose file in `synaplan/`.
- No shell string in the API: a `grep -RnF 'sh -c' cmd internal` in the
  scratch repo returns nothing (the desktop guard, adapted).
- Threat model rows each name a proving test or corpus script — a row
  without proof is not done.

---

## 4. Exit criteria / demo

1. `docs/SPIKE.md` matrix filled for all five candidates with measured values.
2. Prototype: `curl -F request.json=@req.json -F data.csv=@data.csv -F main.py=@main.py :8080/v1/runs`
   returns a PNG name in `out` from a pandas + matplotlib script on T1.
3. Corpus green on the prototype (every row matches its expected result).
4. `docs/THREAT_MODEL.md` reviewed by a second engineer; every row has a proof.
5. `STATUS.md` decision entry written; rows 1, 4, 13 confirmed.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| CP1 | `docs(compute): spike scoring matrix and candidate list` | n.a. (new repo) | — |
| CP2 | `feat(compute): go-only run prototype on hardened docker` | n.a. (new repo) | CP1 |
| CP3 | `docs(compute): threat model with controls and proofs` | n.a. (new repo) | CP2 |
| CP4 | `test(compute): hostile-script corpus definition` | n.a. (new repo) | CP3 |
| CP5 | `docs(compute): measured spike results per candidate` | n.a. (new repo) | CP2, CP4 |
| CP6 | `docs(planning): secure compute A0 go/no-go in STATUS.md` | backend-only (`synaplan/` docs only) | CP5 |
