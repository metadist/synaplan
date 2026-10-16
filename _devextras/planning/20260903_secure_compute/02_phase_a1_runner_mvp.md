# Sprint A1 — Runner MVP

**Phase A (`synaplan-compute`), sprint 2 of 4.** Steps `CP7`–`CP16`.

**Goal:** A real repository with CI and image build, and a service that takes `POST /v1/runs` (multipart
`request.json` + files), runs the entry program in a T1-hardened ephemeral container from the pinned Python
or Node image, streams logs over SSE with server-side truncation, serves artefacts from `/out` through a
MIME allow-list, and cleans up on `DELETE`. The A0 hostile corpus is a required CI job and is green.
**Depends on:** A0 go/no-go = own Go sidecar (or the re-planned variant).
**Unlocks:** A2 (workspaces, tiers, auth, audit). **Repos:** `synaplan-compute` only.
**Flag:** none — no consumer exists yet (master plan §8 step 1).

---

## 0. Why this sprint exists

The MVP proves the boundary before any UI or model prompt shapes it: `image` is a key into a pinned
map, `entry.program` comes from a per-image allow-list, files are pushed in and artefacts pulled out —
fixed in code and fixtures so Phase B conforms instead of negotiating. The A0 `HostConfig` literal
becomes the only way a container is created.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| A0 scratch repo: `internal/runner/hostconfig.go`, `tests/hostile/` | Carried over verbatim; the corpus is the CI gate |
| `/wwwroot/synaplan-opencloud/backend/{Makefile,.golangci.yml,pkg/config/,pkg/command/}`, `.github/workflows/ci.yml`, `Dockerfile` | Layout to copy: `serve` / `version` commands, config from env, golangci v2; actions pinned by SHA, `go test` + lint + buildx job, multi-stage static build (drop the frontend job) |
| `synaplan/docker-compose.yml` (`tts`, `collabora` image lines); `/wwwroot/synaplan-desktop/scripts/no-shell-guard.sh` | Digest-pinned image references; the no-shell guard adapted over `cmd/`, `internal/`, `pkg/` |

---

## 2. Developer steps

### 2.1 Repository bootstrap (`CP7`)

Layout: `cmd/synaplan-compute/main.go`, `pkg/contract/` (protocol types), `pkg/config/` (env → struct),
`internal/api/`, `internal/runner/` (Docker SDK), `internal/images/`, `internal/artefact/`, `internal/logs/`,
`tests/hostile/`, `tests/fixtures/compute-contract/`, `images/{python,node}/Dockerfile`, `docs/`. `Makefile`:
`build test lint format hostile images`. CI on push to `main` and every PR: `go test ./...`, golangci-lint
(opencloud config + `gosec`), `scripts/no-shell-guard.sh`, hostile corpus, buildx for `linux/amd64,linux/arm64`,
SBOM (`syft`) attached to the image. Base images pinned by digest; Renovate config copied from opencloud.

### 2.2 Contract types and `POST /v1/runs` (`CP8`)

`pkg/contract/run.go`: `RunRequest{Protocol int; Owner string; Workspace; Image string;
Entry{Program string; Args []string}; Files []FileRef{Name, Role}; Limits{TimeoutSec,
MemoryMb, CPU float64, Pids, OutputMb}; Egress{Allow []EgressHost}}`, decoded with
`json.Decoder.DisallowUnknownFields()`. `Owner` is an opaque string set by PHP (`user:123`), required
because C3 says no run without an owner and A2 binds workspaces to it — a refinement of the §0 row 8
example, recorded in `STATUS.md` at freeze (A3). Multipart handler `internal/api/runs.go`: part
`request.json` first, then one part per `files[]` entry; names validated (`^[A-Za-z0-9._-]{1,128}$`,
no `..`, no leading `-`); unknown parts rejected. Response `202 { "runId": "<ulid>" }`.
`GET /v1/runs/{id}` → `{ runId, status, exitCode, reason, startedAt, finishedAt, durationMs,
usage{cpuSec, maxMemoryMb, bytesIn, bytesOut}, truncated{stdout, stderr} }`;
`status ∈ queued|running|succeeded|failed|cancelled`; `reason ∈ timeout|oom|pids_limit|output_limit|program_error|cancelled`.
Errors are `{ "error": { "code", "message", "details" } }`, codes `unknown_field`, `unknown_image`,
`program_not_allowed`, `limits_exceed_caps`, `bad_file_name`, `too_many_files`, `payload_too_large`.

### 2.3 T1 hardening as the only container factory (`CP9`)

`internal/runner/hostconfig.go` exposes `Hardened(limits) (container.Config, container.HostConfig)`;
`docker.go` has no other way to create a container.

```text
docker run equivalent                                Docker SDK field
--network none                                       HostConfig.NetworkMode = "none"
--read-only                                          HostConfig.ReadonlyRootfs = true
--tmpfs /tmp:rw,noexec,nosuid,size=64m               HostConfig.Tmpfs["/tmp"] = "rw,noexec,nosuid,size=64m"
--cap-drop ALL                                       HostConfig.CapDrop = ["ALL"]
--security-opt no-new-privileges                     HostConfig.SecurityOpt += "no-new-privileges"
--security-opt seccomp=seccomp/default.json          HostConfig.SecurityOpt += "seccomp=<embedded profile>"
--user 65534:65534                                   Config.User = "65534:65534"
--pids-limit <limits.pids>                           HostConfig.Resources.PidsLimit
--memory <mb> --memory-swap <same mb>                HostConfig.Resources.Memory / MemorySwap (no swap)
--cpus <limits.cpu>                                  HostConfig.Resources.NanoCPUs
--init                                               HostConfig.Init = true (kill PID 1 = whole tree)
--ulimit nofile=1024:1024 --ulimit fsize=<outputMb>  HostConfig.Resources.Ulimits
-v <scratch>/<runId>/work:/work:rw                   HostConfig.Mounts (bind from a path dockerd can see)
-v <scratch>/<runId>/out:/out:rw                     HostConfig.Mounts
--workdir /work                                      Config.WorkingDir = "/work"
never: --privileged, --device, --pid/--ipc/--uts host, docker.sock, extra groups
```

Wall-clock: `context.WithTimeout(limits.TimeoutSec)`, then `ContainerKill` (SIGKILL) and `ContainerRemove{Force: true,
RemoveVolumes: true}`. Scratch root `COMPUTE_SCRATCH_DIR` (host path shared with dockerd, or the named volume
`synaplan_compute_scratch` mounted at the same path in both). Limits above `COMPUTE_MAX_{TIMEOUT_SEC,MEMORY_MB,CPU,PIDS,OUTPUT_MB}` → `limits_exceed_caps`.

### 2.4 Pinned image map and program allow-list (`CP10`)

`internal/images/map.go`: `python → ghcr.io/metadist/synaplan-compute-python@sha256:…`,
`node → ghcr.io/metadist/synaplan-compute-node@sha256:…`; allow-list `python: [python, sh]`, `node: [node, sh]`.
Python 3.12 + pandas, numpy, matplotlib, openpyxl, python-docx, python-pptx, pypdf, pillow; Node 22 LTS +
`xlsx`, `docx`, `pdf-lib`, `sharp`. Both non-root (`65534`), no package-manager network at run time.
Pulled by digest at service start; a run never pulls.

### 2.5 Logs SSE (`CP11`), artefacts (`CP12`), delete and cleanup (`CP13`)

`GET /v1/runs/{id}/logs` → `text/event-stream`, events `stdout`, `stderr` (`{ "seq", "text" }`,
UTF-8-sanitized), `status`, `done`. Cap per stream `COMPUTE_LOG_CAP_BYTES` (default 256 KiB); after
the cap one `truncated` event, further output discarded while the container keeps running;
`Last-Event-ID` replays from a ring buffer. `GET /v1/runs/{id}/artefacts` lists `{ name, size, mime,
sha256 }` for regular files only (`Lstat` + `O_NOFOLLOW`); names sanitized like inputs; MIME sniffed
from content and matched against `COMPUTE_ARTEFACT_MIME_ALLOW` (default: images, PDF, OOXML, ODF,
CSV, JSON, plain text, ZIP); total size ≤ `limits.outputMb`; anything else is listed with
`rejected: "<reason>"` and cannot be downloaded. `GET /v1/runs/{id}/artefacts/{name}` streams as an
attachment. `DELETE /v1/runs/{id}` kills and removes a running container, deletes `<scratch>/<runId>`,
keeps the status record for `COMPUTE_RUN_RETENTION_MIN` (default 60) so PHP can read the final
status. A startup sweep removes containers labelled `synaplan.compute.run` left by a crash.

### 2.6 Hostile corpus in CI (`CP14`), fixtures (`CP15`), demo (`CP16`)

`tests/hostile/hostile_test.go` submits each corpus script through the real HTTP API against a
service started in the test (`httptest` + local dockerd), asserts the header expectation, and checks
host-side effects (process count, disk usage, `tcpdump` capture for `dns_attempt`). Job `hostile` is
required for merge. `tests/fixtures/compute-contract/`: `run_request_python.json`, `run_request_node.json`,
`run_status_succeeded.json`, `run_status_timeout.json`, `artefact_list.json`, `error_limits_exceed_caps.json`,
`logs.sse`; loaded by `pkg/contract/contract_test.go` (`TestFixturesDecode`, `TestUnknownFieldRejected`).
`examples/csv-chart/` holds `data.csv` + `main.py` + `run.sh` for the demo.

---

## 3. Tests and invariants

- **C3**: `TestRunRejectsMissingOwner`, `TestRunRejectsUnknownImage`, `TestRunRejectsProgramNotInAllowList`,
  `TestRunRejectsLimitsAboveCaps`.
- **C6**: `hostile` CI job green on T1 for every PR. **C7 (seed)**: `TestUnknownFieldRejected` per struct.
- Row 9 (untrusted results): `TestArtefactSymlinkOmitted`, `TestArtefactMimeRejected`, `TestArtefactSizeCap`,
  `TestLogTruncation`.
- No-shell: `scripts/no-shell-guard.sh` in CI; `entry.program` runs as `Cmd = [program, args...]` through
  the Docker API, never a shell. `TestHostConfigHardening` asserts every §2.3 row.

---

## 4. Exit criteria / demo

1. `make hostile` green locally and in CI on T1 (Python and Node mirrors); `huge_stdout.py` ends
   with `truncated.stdout = true` and the service RSS stays flat.
2. `examples/csv-chart/run.sh` returns `chart.png` via `/v1/runs/{id}/artefacts/chart.png`; logs SSE
   shows the pandas summary; status `succeeded`, `exitCode 0`.
3. Images `synaplan-compute`, `-python`, `-node` published for both architectures with SBOMs;
   fixtures committed and decoding in tests; `docs/API.md` draft matches them.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| CP7 | `chore(compute): repository bootstrap, ci, image build with sbom` | n.a. (new repo) | A0 |
| CP8 | `feat(compute): protocol 1 run request and POST /v1/runs` | n.a. (new repo) | CP7 |
| CP9 | `feat(compute): T1 hardened container factory with wall-clock kill` | n.a. (new repo) | CP8 |
| CP10 | `feat(compute): pinned python and node images with program allow-list` | n.a. (new repo) | CP9 |
| CP11 | `feat(compute): run logs over SSE with server-side truncation` | n.a. (new repo) | CP9 |
| CP12 | `feat(compute): artefact listing and download with mime allow-list` | n.a. (new repo) | CP9 |
| CP13 | `feat(compute): DELETE /v1/runs and crash-safe cleanup` | n.a. (new repo) | CP9 |
| CP14 | `test(compute): hostile corpus as a required ci job` | n.a. (new repo) | CP10–CP13 |
| CP15 | `test(compute): protocol 1 contract fixtures` | n.a. (new repo) | CP8, CP12 |
| CP16 | `docs(compute): csv to chart example and api draft` | n.a. (new repo) | CP14, CP15 |
