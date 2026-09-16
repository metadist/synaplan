# Sprint A2 — Workspaces, tiers, auth and audit

**Phase A (`synaplan-compute`), sprint 3 of 4.** Steps `CP17`–`CP24`.

**Goal:** The sidecar becomes multi-tenant-safe and operable: persistent user workspaces with a MB quota and
opaque ids that mount only into their owner's runs, gVisor (`runsc`) detection and selection for T2, a health
endpoint reporting protocol, tier, images and capacity, shared bearer authentication, structured audit lines
without stdout content, the egress proxy B3 will drive, and quota refusals as clean 4xx shapes. The same
request runs unchanged on T1 and T2 in CI.
**Depends on:** A1 (`CP8`–`CP16`). **Unlocks:** A3 (freeze). **Repos:** `synaplan-compute` only. **Flag:** none.

---

## 0. Why this sprint exists

Row 7 promises a persistent workspace per user and row 4 one API across tiers. Both are cross-user boundaries:
a workspace mounted into the wrong run is a data leak, and a tier that is silently T1 where T2 was expected is
a Cloud posture violation (row 14). This sprint makes ownership a server-side check and the tier a reported
fact before any PHP code can assume either. Egress lands here on the compute side only (proxy and refusal),
so A3 can freeze the request with the `egress` block filled in and B3 has nothing to add to the contract.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `internal/runner/hostconfig.go` (A1) | Mount list grows by one entry for `workspace.kind = user`; nothing else changes |
| `pkg/contract/run.go` (A1) | `Owner`, `Workspace{Kind, Id}`, `Egress.Allow` are already typed; this sprint gives them behaviour |
| `synaplan/backend/src/Service/Security/SsrfGuard.php` | What PHP will send: host, port, pinned IPs — compute trusts nothing else |
| `synaplan/backend/src/Service/Media/MediaJobStore.php`; `/wwwroot/synaplan-opencloud/backend/pkg/command/health.go` | Lease / cleanup thinking for the capacity counter; health command shape in the family |
| gVisor docs: `runsc` as a Docker runtime (`daemon.json` → `runtimes.runsc`) | Detection is "the daemon lists `runsc`", nothing cleverer |

---

## 2. Developer steps

### 2.1 Workspaces (`CP17`)

`internal/workspace/store.go`, `internal/api/workspaces.go`. A workspace is a directory `<COMPUTE_WORKSPACES_DIR>/<workspaceId>`
plus metadata `{ id, owner, quotaMb, createdAt, lastUsedAt }`. Ids are ULIDs; the path is never returned.

| Method | Path | Body / result |
| ------ | ---- | ------------- |
| `POST` | `/v1/workspaces` | `{ owner: "user:123", quotaMb: 512 }` → `201 { workspaceId, quotaMb }` |
| `GET` | `/v1/workspaces/{id}/usage` | `{ usedMb, quotaMb, fileCount, lastUsedAt }` |
| `GET` | `/v1/workspaces/{id}/files?path=` | `[ { path, size, mime, modifiedAt } ]` (regular files only) |
| `GET` | `/v1/workspaces/{id}/files/{path}` | Download, same sanitizing and MIME rules as artefacts |
| `DELETE` | `/v1/workspaces/{id}` | Removes directory and metadata; running runs on it are cancelled first |

A run with `workspace: { kind: "user", id }` must carry the same `owner` as the workspace; mismatch → `403
workspace_not_owned`, unknown id → `404 workspace_not_found`. The workspace is bind-mounted at `/workspace`
read-write; `/work` and `/out` stay per run. `usedMb` is recomputed after every run on it; a run that would
exceed `quotaMb` is refused before start (`409 workspace_quota_exceeded`), one that exceeds it while running
is killed with reason `output_limit`.

### 2.2 Tier detection and selection (`CP18`)

`internal/runtime/tier.go`: at start, `client.Info()` → if `Runtimes` has `runsc`, tier is `gvisor` and every
run sets `HostConfig.Runtime = "runsc"`; else `docker`. `COMPUTE_TIER=docker|gvisor|microvm` forces a value and
fails startup if the daemon cannot provide it (a hoster who configured T2 must never fall back to T1 silently).
`microvm` is accepted for Kata / Firecracker runtimes (`COMPUTE_RUNTIME_NAME` names the daemon runtime), documented in A3.

### 2.3 Health and capacity (`CP19`)

`GET /v1/health` (unauthenticated liveness for compose; everything else is authenticated):

```json
{
  "protocol": 1,
  "tier": "gvisor",
  "images": [ { "key": "python", "ref": "ghcr.io/metadist/synaplan-compute-python@sha256:…" },
              { "key": "node", "ref": "ghcr.io/metadist/synaplan-compute-node@sha256:…" } ],
  "capacity": { "maxConcurrent": 8, "running": 2, "queued": 0 },
  "caps": { "timeoutSec": 300, "memoryMb": 2048, "cpu": 2.0, "pids": 256, "outputMb": 200 },
  "features": { "workspaces": true, "egress": true }
}
```

`COMPUTE_MAX_CONCURRENT` bounds running containers; the (N+1)th request queues up to `COMPUTE_QUEUE_MAX`, beyond that `429 capacity_exceeded` with `Retry-After`.

### 2.4 Shared bearer auth (`CP20`) and structured audit log (`CP21`)

`internal/auth/bearer.go`: `COMPUTE_AUTH_TOKEN` (≥ 32 bytes, startup fails otherwise); constant-time compare on
`Authorization: Bearer`; missing or wrong → `401 unauthorized` without detail. The token is the only credential
the service knows and grants nothing on the Synaplan side. `internal/audit/log.go` writes one JSON line per
event to stdout: `run.accepted`, `run.started`, `run.finished`, `run.cancelled`, `run.refused`, `workspace.created`,
`workspace.deleted`, `egress.refused`. Fields: `ts, event, runId, owner, image, tier, limits, exitCode, reason,
durationMs, bytesIn, bytesOut, artefactCount, egressHosts`. Never `stdout`, `stderr`, input file names or
artefact contents — `TestAuditNeverLogsOutput` runs `huge_stdout.py` and asserts none of it appears.

### 2.5 Egress proxy and refusal (`CP22`)

`internal/egress/proxy.go`. Empty `egress.allow` (default) keeps `NetworkMode = none`. When non-empty, the run
joins a per-run internal Docker network with the compute process as an HTTP CONNECT proxy; the container gets
`HTTP_PROXY` / `HTTPS_PROXY`, `NO_PROXY=""` and no DNS. Each entry is `{ "host": "api.example.com", "port": 443,
"ips": ["93.184.216.34"] }`, resolved and pinned by PHP through `SsrfGuard`; the proxy connects only to the
pinned IPs on that port and answers `403` to anything else, logging `egress.refused`. Entries without `ips`,
with private ranges, or above `COMPUTE_EGRESS_MAX_HOSTS` (default 8) are refused at submit (`400 egress_not_allowed`).
`COMPUTE_EGRESS_ENABLED=false` (default) refuses any non-empty list; health `features.egress` reflects it.

### 2.6 Quota and refusal shapes (`CP23`) — all share `{ "error": { "code", "message", "details" } }`, `application/problem+json`

| Code | Status | When |
| ---- | ------ | ---- |
| `workspace_quota_exceeded` | 409 | Inputs + current usage would exceed `quotaMb` |
| `workspace_not_owned` | 403 | `owner` differs from the workspace owner |
| `workspace_not_found` | 404 | Unknown or deleted id |
| `capacity_exceeded` | 429 | Queue full; `Retry-After` set |
| `egress_not_allowed` | 400 | Egress disabled, unpinned entry, private IP, too many hosts |
| `limits_exceed_caps` | 400 | Any limit above `caps` from health |
| `unauthorized` | 401 | Bearer missing or wrong |

### 2.7 Same request on T1 and T2 in CI (`CP24`)

`tests/tiers/tier_test.go` runs `run_request_python.json` and the whole hostile corpus on the default runtime
and, when `COMPUTE_TIER=gvisor` is available, again on `runsc`, asserting identical status, exit code and
artefact list. GitHub-hosted runners do not ship gVisor: the T2 job installs `runsc` from the gVisor apt
repository on `ubuntu-latest` and is **required nightly on `main`, optional on PRs** (label `tier:gvisor` makes
it required). A broken install turns the nightly red — a Cloud enablement blocker, not a flaky test to skip.

---

## 3. Tests and invariants

- **C3**: `TestRunWithForeignWorkspaceRefused` (403), `TestRunWithoutOwnerRefused`. **C4**: `TestEgressEmptyMeansNoNetwork`,
  `TestProxyRefusesUnpinnedHost`, `TestProxyRefusesPrivateIp`, `TestEgressDisabledRefusesAllowList`; `dns_attempt.py`
  still fails with the proxy attached.
- **C6**: corpus green on T1 in every PR and on T2 nightly (`CP24`). Cross-user (§4.3):
  `TestWorkspaceListNeverReturnsPaths`, `TestWorkspaceQuotaKillsRun`.
- Audit and auth: `TestAuditNeverLogsOutput`, `TestAuditRefusedRunHasReason`,
  `TestUnauthenticatedRunsRejected`, `TestHealthIsPublic`, `TestForcedTierFailsStartupWithoutRuntime`.

---

## 4. Exit criteria / demo

1. Two workspaces for two owners; a run on owner A's workspace with owner B in `request.json` → 403; the correct
   owner's second run sees the first run's file under `/workspace`. Filling a 64 MB workspace with `disk_fill.py`
   ends with `workspace_quota_exceeded` on the next submit and the usage endpoint at the cap.
2. `GET /v1/health` on a host with `runsc` reports `tier: gvisor`; the same `run_request_python.json`
   succeeds on both tiers with identical artefacts; nightly T2 job green once on `main`.
3. A run with an allow-list reaches the pinned IP and is refused for any other host; `egress.refused`
   appears in the audit stream.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| CP17 | `feat(compute): persistent user workspaces with quota and ownership` | n.a. (new repo) | A1 |
| CP18 | `feat(compute): tier detection and runsc selection` | n.a. (new repo) | A1 |
| CP19 | `feat(compute): health with protocol, tier, images and capacity` | n.a. (new repo) | CP18 |
| CP20 | `feat(compute): shared bearer authentication` | n.a. (new repo) | A1 |
| CP21 | `feat(compute): structured audit log without run output` | n.a. (new repo) | CP17 |
| CP22 | `feat(compute): per-run egress proxy with pinned allow-list` | n.a. (new repo) | CP19 |
| CP23 | `feat(compute): problem+json refusal shapes for quota and capacity` | n.a. (new repo) | CP17, CP19 |
| CP24 | `test(compute): same request on T1 and T2, nightly gvisor job` | n.a. (new repo) | CP18, CP23 |
