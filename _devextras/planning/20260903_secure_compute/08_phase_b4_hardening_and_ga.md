# Sprint B4 — Hardening and GA

**Phase B (`synaplan/`), sprint 4 of 4 — the last sprint of the track.** Steps `CS26`–`CS33`.

**Goal:** Compute is safe to leave on: a load test shows the limits hold under concurrency, Operate → Feature
status shows tier, capacity and image versions, orphan runs and expired workspaces are reaped, the seeder
turns the flag on for **new** installs only when `COMPUTE_URL` is set, and Synaplan Cloud enablement is gated
on a verified T2 tier reported by `/v1/health` from a separate compute node. The master plan §10 success
criteria are checked off one by one and the rollback path is exercised.
**Depends on:** B1–B3; compute release `v1.x` with A3 signing.
**Unlocks:** track closure (directory moves to `2026-archive/` with the "shipped in vX.Y" note, roadmap §7 step 7).
**Repos:** `synaplan/`; a load tool in `synaplan-compute`; cron entries in `synaplan-platform` (private).
**Flag:** `COMPUTE.ENABLED` seeded `1` for new installs **only when `COMPUTE_URL` is set at seed time**;
existing installs unchanged (`BCONFIG` defaults are bootstrap-only).

---

## 0. Why this sprint exists

Everything before this sprint proves the feature works. GA needs proof that it keeps working when eight users
run at once, when a worker dies mid-run, when a user leaves and their workspace stays behind, and when a
hoster enables it on a host that does not meet the posture the docs promise. Row 14 of the master plan makes
the last point non-negotiable for Synaplan Cloud: T2 on a separate node, verified by the service itself, not
by a checklist.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Controller/ConfigController.php` (`features` payload, `/api/v1/config/features/status`), `frontend/src/views/FeatureStatusView.vue` (`/admin/features`) | Where compute health, tier and capacity are surfaced |
| `backend/src/Service/Media/MediaJobReaper.php`, `/wwwroot/synaplan-platform/cron-media-reaper.sh`, `cron-ephemeral-reaper.sh` | Reaper command + platform cron pattern to copy |
| `backend/src/Seed/RateLimitConfigSeeder.php`, `Seed/ComputeConfigSeeder.php` (B1), `Command/SeedAllCommand.php` | Where the conditional flip lives |
| `backend/src/Service/Compute/{ComputeClient,ComputeConfig}.php` | `health()` is the gate input |
| `docs/HEALTH_MONITORING.md`, `docs/OBSERVABILITY.md`; `00_master_plan.md` §8 (rollout), §10 (success criteria) | Where new signals are documented; the checklist this sprint closes |

---

## 2. Developer steps

### 2.1 Load test plan and tool (`CS26`, in `synaplan-compute`)

`cmd/compute-load/main.go`: submits N concurrent runs from a scenario file (`scenarios/mixed.yaml`: 60 % CSV →
chart, 20 % XLSX recalculation, 10 % `long_sleep.py`, 10 % `huge_stdout.py`) against a service URL, records
p50/p95 submit latency, cold-start, queue wait, refusals by code, and the service RSS / host load from
`/v1/health` plus `docker stats`. Runs in the compute repo's nightly on T1 and T2 with `maxConcurrent = 4` and
`N = 32`. Pass bars in `docs/SIZING.md`: no run exceeds its `timeoutSec` by more than 2 s; host load average
stays below `cores × 1.5`; zero containers left after the run; `capacity_exceeded` is the only refusal seen for
the overflow. Results are pasted into the release notes of the compute tag used for GA.

### 2.2 Capacity signals in Feature status (`CS27`, ota-candidate)

`ConfigController` `features/status` gains a `compute` entry built from `ComputeClient::health()` (cached 30 s):
`enabled`, `reachable`, `protocol`, `tier`, `tierMeetsRequirement`, `capacity{ maxConcurrent, running, queued }`,
`images[{ key, digest }]`, `runsLast24h`, `failedLast24h` (from `BCOMPUTERUNS`). `FeatureStatusView.vue` renders
a card: tier badge (Standard / Strong isolation / Virtual machine for `docker` / `gvisor` / `microvm`), capacity
bar, short image digests, last-24h counts, and the posture line from `CS31`. Five locales; helper text may say "gVisor".

### 2.3 Cleanup jobs (`CS28`, `CS29`)

`app:compute:reap-runs` (`CS28`): `BCOMPUTERUNS` rows `queued|running` older than `MAX_TIMEOUT_SEC + 120 s` → ask
compute for status; if unknown or finished, close the row (`failed`, reason `lost`) and `DELETE /v1/runs/{id}`;
also `DELETE` any compute run whose row is already final (artefacts pulled) older than 10 minutes. Redis lock
like `MediaJobReaper`; exits immediately when `ComputeConfig::isEnabled()` is false (flag off = idle, not
broken). `app:compute:expire-workspaces` (`CS29`): `BCOMPUTEWORKSPACES` with `BLASTUSED < now − WORKSPACE_TTL_DAYS`
→ status `expiring`, owner notified once (existing notification path); 7 days later → `DELETE /v1/workspaces/{id}`
and row `deleted`. Both are Symfony commands run by the platform cron (`synaplan-platform`:
`cron-compute-reaper.sh`, private PR); in dev the worker tick suffices.

### 2.4 Seeder flip for new installs (`CS30`)

`ComputeConfigSeeder`: `ENABLED` is inserted as `1` **iff** `COMPUTE_URL` and `COMPUTE_TOKEN` are non-empty at
seed time, else `0`; `insertIfMissing` guarantees existing rows are never touched. No migration ships for
existing installs — enabling there is an admin decision in Operate → System config (documented in
`docs/COMPUTE.md`). `ComputeConfigSeederTest` covers both environments.

### 2.5 Synaplan Cloud enablement gate (`CS31`)

New setting `COMPUTE.REQUIRE_TIER` (`docker` | `gvisor` | `microvm`, default `docker`; Synaplan Cloud sets `gvisor`
in its platform env seed). `ComputeConfig::isEnabled()` additionally requires `health().tier` ≥ `REQUIRE_TIER`
(cached, re-checked every 60 s; unreachable = disabled). Saving `ENABLED = 1` in System config with a lower tier
is refused with "the compute service reports Standard isolation; this instance requires Strong isolation".
Feature status shows `tierMeetsRequirement` as the posture line. The separate-node requirement is verified
operationally: `docs/COMPUTE.md` states that `COMPUTE_URL` on Cloud must not resolve to a web host, and the
platform runbook (private) records the check. The flag is enabled on `web.synaplan.com` for internal users
first (master plan §8 step 2), then for all.

### 2.6 Success criteria run-through (`CS32`) and rollback rehearsal (`CS33`)

`STATUS.md` gets a table with the six §10 criteria, each with its evidence:

| # | Criterion | Evidence |
| - | --------- | -------- |
| 1 | Hostile corpus contained on T1 and T2 | Compute CI run link (T1 PR job, T2 nightly) |
| 2 | XLSX recalculation returns a downloadable XLSX with `source: compute` | Demo script `_devextras/testing/compute/xlsx-recalc.sh` output |
| 3 | No `compute:run` scope ⇒ no `code_execution`; assistant without `code_run` never plans it | `GatewayCodeExecutionToolTest`, `AssistantSkillGateTest` |
| 4 | Scheduled task pauses for approval, runs after approval, audit row complete | `SavedTaskComputePauseTest` + a manual run on staging |
| 5 | Flag off / URL unset: gate green, snapshots untouched, `code_execution` unavailable, no `docker.sock` in PHP containers | Full gate run, `check-no-docker-sock.sh` |
| 6 | Fixtures identical in both repos | `TestFixtureChecksums` + `ComputeContractFixtureTest` |

Rollback (`CS33`) on staging: flag on → ten runs → flag off. Assert: planner catalog loses `code_run` on the
next turn, gateways stop offering `code_execution`, `code_execution` returns to unavailable, reapers exit
immediately, run history in `BCOMPUTERUNS` and artefacts in Files remain readable, the workspace browser shows a
"not available" state instead of an error. Then `docker compose --profile compute down`: `ComputeClient`
failures surface as "not available" in the card, never as a 500. Documented as the rollback section of `docs/COMPUTE.md`.

---

## 3. Tests and invariants

- **C1**: flag off after being on — `ComputeRollbackTest` asserts the four disappearances above and an empty
  characterization diff. **C2**: `check-no-docker-sock.sh` remains in CI; the load tool runs only against the
  compute service, never through PHP containers.
- **C5**: reaper never opens artefacts; `ComputeReaperTest::testMetadataOnly`. **C6**: compute nightly (T1 + T2 +
  load) green on the GA tag. **C7**: checksum tests green in both repos on the GA tag.
- **C8**: mobile-impact classification per PR (`backend-only`, the Feature status card `ota-candidate`);
  gateways unchanged without the scope.
- Gate: `ComputeConfigTierGateTest::testLowerTierDisables`, `::testUnreachableDisables`,
  `SystemConfigServiceComputeTest::testRefusesEnableBelowRequiredTier`.
- Reapers and seeder: `ComputeReaperTest::testLostRunClosed`, `::testIdleWhenDisabled`;
  `ComputeWorkspaceExpiryTest::testNotifyThenDelete`; `ComputeConfigSeederTest::testEnabledOnlyWithUrl`,
  `::testNeverOverwrites`.

---

## 4. Exit criteria / demo

1. Load run on staging T2: pass bars met; results linked from `STATUS.md`.
2. Operate → Feature status shows the compute card with tier, capacity, images and last-24h counts; a T1 host
   with `REQUIRE_TIER = gvisor` shows the posture warning and refuses enablement.
3. Orphan run and expired workspace both cleaned by the commands within one cron cycle; owner notified first.
4. Fresh install with `COMPUTE_URL` set: compute enabled after `app:seed`; without it: disabled; existing
   install: unchanged.
5. §10 criteria table complete with evidence; rollback rehearsal recorded; track directory archived with the
   "shipped in vX.Y" entry in the roadmap.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| CS26 | `test(compute): load tool and nightly scenario on T1 and T2` — in `synaplan-compute` | n.a. (compute repo) | B3 |
| CS27 | `feat(admin): compute tier, capacity and images in feature status` | ota-candidate | B3 |
| CS28 | `feat(compute-client): app:compute:reap-runs for orphaned runs` | backend-only | B1 |
| CS29 | `feat(compute-client): app:compute:expire-workspaces with owner notice` | backend-only | B3 |
| CS30 | `feat(compute-client): seed COMPUTE.ENABLED on for new installs with COMPUTE_URL` | backend-only | CS31 |
| CS31 | `feat(compute-client): COMPUTE.REQUIRE_TIER enablement gate from health` | backend-only | CS27 |
| CS32 | `docs(planning): secure compute success criteria evidence in STATUS.md` | backend-only | CS26–CS31 |
| CS33 | `docs: compute rollback section after staging rehearsal` | backend-only | CS32 |
