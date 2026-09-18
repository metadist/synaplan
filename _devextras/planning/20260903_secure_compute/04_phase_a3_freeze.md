# Sprint A3 — Freeze

**Phase A (`synaplan-compute`), sprint 4 of 4 — the last sidecar sprint.** Steps `CP25`–`CP31`.

**Goal:** `protocol: 1` is reviewed, frozen and provable: fixtures are committed in the compute repo and
vendored byte-for-byte into `synaplan/` with a checksum test on both sides, unknown fields are rejected
everywhere, `docs/` in the compute repo describes the API, the T1/T2/T3 deployments and sizing, images are
signed with cosign and pinned by digest, and the two deployment blocks (compose profile for `synaplan/`,
service block for the separate compute node in `synaplan-platform`) exist. After this sprint a contract
change is `protocol: 2`.
**Depends on:** A1 + A2 complete; nightly T2 job green at least once. **Unlocks:** Phase B (B1 may start
the day this merges).
**Repos:** `synaplan-compute` (freeze, docs, signing); `synaplan/` (fixtures + checksum test + compose
profile — compose is an **ask first** item recorded in `STATUS.md` before `CP30` opens);
`synaplan-platform` (private; service block).
**Flag:** the `synaplan/` compose block is opt-in by profile; no `BCONFIG` flag is touched here.

---

## 0. Why this sprint exists

The desktop track showed the value of freezing before the consumer exists: Phase B then conforms instead
of negotiating, and a later "small client convenience" cannot reshape the boundary (desktop A3 §2.5). For
compute the stakes are higher — the request shape *is* the security boundary (no shell string, image key
not reference, program from an allow-list, limits within caps, owner on every run). Freezing it with a
checksum test in both repos means a PHP change that would loosen it fails the Synaplan gate, not a review.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `tests/fixtures/compute-contract/` (A1 `CP15`, extended in A2) | The files that become the frozen set |
| `pkg/contract/*.go` | Every struct decoded with `DisallowUnknownFields`; this sprint audits that no decoder bypasses it |
| `synaplan/_devextras/testing/desktop/fixtures/` + `/wwwroot/synaplan-desktop/src-tauri/synaplan-core/src/contract.rs` (`include_bytes!` + checksum) | The two-repo checksum pattern to copy |
| `synaplan/docker-compose.yml` lines for `collabora` and `tts` (`profiles:`, digest-pinned `image:`, `networks:`) | Shape of the `compute` profile block |
| `/wwwroot/synaplan-platform/docker-compose.yml` (`OFFICE_CONVERT_URL` injection, `collabora` service) | Where the platform service block and env injection go (private repo) |
| `synaplan/AGENTS.md` "Ask First Before → Modifying Docker/CI/build configs" | Why `CP30` needs a recorded ask |

---

## 2. Developer steps

### 2.1 Contract review checklist (`CP25`)

`docs/CONTRACT_REVIEW.md`, filled in by two engineers, one from the PHP side. Each row is ticked with a fixture or test name:

| Check | Proof |
| ----- | ----- |
| Every request field has a type, a bound and a refusal code | `pkg/contract/run.go` doc comments, `error_*.json` fixtures |
| No field can carry a shell string or an image reference | `entry.program` allow-list test; `image` map test; no-shell guard |
| `owner` is required on runs and workspaces | `TestRunRejectsMissingOwner`, `TestWorkspaceRejectsMissingOwner` |
| All enums closed and documented (`status`, `reason`, `role`, `workspace.kind`, `error.code`) | `docs/API.md` §Enums, `TestEnumsClosed` |
| Unknown fields rejected on every decoder, including multipart part names | `TestUnknownFieldRejected` per struct, `TestUnknownMultipartPartRejected` |
| Health shape sufficient for PHP's gate (tier, caps, features) | `health.json` fixture |
| Egress entry shape matches what `SsrfGuard` can produce (host, port, pinned IPs) | `run_request_egress.json` |
| `synaplan-compute` never receives a Synaplan credential, user email or file path | Audit field list review |

### 2.2 Frozen fixtures, checksum tests in both repos, single decoder (`CP26`, `CP27`)

Final set in `tests/fixtures/compute-contract/`: `run_request_python.json`, `run_request_node.json`,
`run_request_user_workspace.json`, `run_request_egress.json`, `run_status_succeeded.json`,
`run_status_timeout.json`, `run_status_cancelled.json`, `artefact_list.json`, `health.json`,
`workspace_create.json`, `workspace_usage.json`, `error_limits_exceed_caps.json`,
`error_workspace_quota_exceeded.json`, `error_egress_not_allowed.json`, `logs.sse`, plus
`CHECKSUMS.sha256` and a `README.md` naming the source commit. Compute: `pkg/contract/freeze_test.go`
(`TestFixtureChecksums`) fails if any byte drifts. `internal/api/decode.go` becomes the single JSON entry
point (`DisallowUnknownFields`, `MaxBytes` from `COMPUTE_MAX_REQUEST_BYTES`); `scripts/decoder-guard.sh`
fails CI if `json.Unmarshal` or `json.NewDecoder` appears outside it.
Synaplan (`CP27`): the directory is vendored to `synaplan/backend/tests/Fixtures/compute-contract/` and
`backend/tests/Unit/Service/Compute/ComputeContractFixtureTest.php` asserts the same checksums and that
every fixture decodes into the B1 DTOs (the DTO half is skipped until B1; the checksum half runs at once).
Changing a fixture in either repo is a `protocol: 2` decision with a `STATUS.md` entry, never a
convenience commit.

### 2.3 `docs/` in the compute repo (`CP28`)

- `docs/API.md` — every endpoint, request/response, enums, error codes, SSE events, fixtures by reference.
- `docs/DEPLOYMENT.md` — T1 (same host, compose profile, the honest limits statement from master plan §8
  step 3), T2 (`runsc` install, `daemon.json` `runtimes`, `COMPUTE_TIER=gvisor`, separate compute node or
  separate daemon), T3 (Kata / Firecracker runtime name, documented only). Network rule: compute is
  reachable from PHP only, never published.
- `docs/SIZING.md` — memory = `maxConcurrent × memoryMb + 256 MB`; CPU = `maxConcurrent × cpu`; scratch
  disk = `maxConcurrent × outputMb × 2` plus workspaces; defaults for a 4 GB and a 16 GB node.
- `docs/THREAT_MODEL.md` (A0) updated with the A2 controls and test names.

### 2.4 Image signing and digest pinning (`CP29`)

CI signs `synaplan-compute`, `synaplan-compute-python`, `synaplan-compute-node` with cosign keyless (GitHub
OIDC) on tags; `docs/DEPLOYMENT.md` shows `cosign verify` with the expected identity.
`internal/images/map.go` refuses a non-digest reference at startup (`TestImageMapRequiresDigest`); Renovate
updates digests via PR. Release tags `v1.x.y`; no compose block written here references `latest`.

### 2.5 Deployment blocks (`CP30`, `CP31`)

`synaplan/docker-compose.yml` (`CP30`, after the recorded ask):

```yaml
  # Optional secure compute sidecar (T1 on this host). Enable with:
  #   docker compose --profile compute up -d
  # PHP never mounts the Docker socket; only this service does (C2).
  compute:
    image: ghcr.io/metadist/synaplan-compute:1.0.0@sha256:<digest>
    container_name: synaplan-compute
    profiles: [compute]
    restart: unless-stopped
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock
      - compute_scratch:/var/lib/synaplan-compute
    environment:
      COMPUTE_AUTH_TOKEN: ${COMPUTE_TOKEN:?set COMPUTE_TOKEN in .env}
      COMPUTE_SCRATCH_DIR: /var/lib/synaplan-compute
      COMPUTE_MAX_CONCURRENT: "2"
    networks: [synaplan-network]
```

`backend` and `worker` gain `COMPUTE_URL: ${COMPUTE_URL:-}` and `COMPUTE_TOKEN: ${COMPUTE_TOKEN:-}` (unset =
feature absent), mirroring `OFFICE_CONVERT_URL`; `.env.example` documents both. A CI grep
(`scripts/check-no-docker-sock.sh` in `synaplan/`) asserts `docker.sock` appears only under the `compute`
service (C2). `synaplan-platform` (`CP31`, private): a `compute` service for a **separate compute node**
with `COMPUTE_TIER=gvisor`, its own Docker daemon with `runsc`, reachable from the web hosts over the
private network only; the web hosts set `COMPUTE_URL` to that node. No node names, IPs or tokens here.

---

## 3. Tests and invariants

- **C2**: `scripts/check-no-docker-sock.sh` in `synaplan/` CI; compose review shows the socket only on `compute`.
- **C4**: `run_request_egress.json` decodes; `error_egress_not_allowed.json` matches the A2 refusal byte-for-byte.
- **C6**: hostile corpus still required; freeze changes nothing there.
- **C7**: `TestFixtureChecksums` (Go) and `ComputeContractFixtureTest` (PHP) assert identical
  `CHECKSUMS.sha256`; `scripts/decoder-guard.sh` in CI.
- Supply chain: `TestImageMapRequiresDigest`; `cosign verify` step in the release workflow.

---

## 4. Exit criteria / demo

1. `docs/CONTRACT_REVIEW.md` fully ticked by two engineers.
2. Fixtures + `CHECKSUMS.sha256` identical in both repos; both checksum tests green; `synaplan/` gate
   green with the vendored directory.
3. `docker compose --profile compute up -d` on a dev machine, then the A1 CSV → chart example against
   `compute:8080` from inside the network — no consumer yet, `curl` only.
4. `cosign verify` succeeds for the three images of the release tag.
5. `STATUS.md`: "protocol: 1 frozen" entry with the fixture commit hash and the `owner` refinement recorded.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| CP25 | `docs(compute): protocol 1 contract review checklist` | n.a. (new repo) | A2 |
| CP26 | `test(compute): freeze fixtures, checksum test and single decoder guard` | n.a. (new repo) | CP25 |
| CP27 | `test(compute-client): vendor compute contract fixtures with checksum test` | backend-only | CP26 |
| CP28 | `docs(compute): api, deployment tiers and sizing` | n.a. (new repo) | CP25 |
| CP29 | `ci(compute): cosign signing and digest-only image map` | n.a. (new repo) | CP26 |
| CP30 | `chore(compose): optional compute profile and COMPUTE_URL passthrough` | backend-only | CP29, recorded ask |
| CP31 | `feat(platform): compute node service block (T2)` — in `synaplan-platform` | n.a. (private repo) | CP29 |
