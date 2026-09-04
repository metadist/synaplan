# Sprint B1 — Client and capability

**Phase B (`synaplan/`), sprint 1 of 4.** Steps `CS1`–`CS10`.

**Goal:** Synaplan uses the frozen sidecar end to end behind a flag: a `ComputeClient`, the `BCOMPUTERUNS` audit
table, a `code_run` capability the planner picks for compute-with-files intents, a run card in the chat, artefacts
landing in `BFILES` with `source = compute`, and quotas in the existing rate-limit lanes. With the flag off or
`COMPUTE_URL` unset nothing changes — the characterization snapshots stay byte-identical.
**Depends on:** A3 (`protocol: 1` frozen, fixtures vendored by `CP27`). Schema ask recorded in master plan §0 row 15.
**Unlocks:** B2 (tools and policy), B3 (workspaces, egress). **Repos:** `synaplan/` only.
**Flag:** `COMPUTE.ENABLED` (`BCONFIG` group `COMPUTE`, setting `ENABLED`, default `0` in code and seeder) **and** env
`COMPUTE_URL` + `COMPUTE_TOKEN`. Both must be set; either missing means the feature is absent.

---

## 0. Why this sprint exists

Everything on the server side that "runs code" today is a documented gap (`PlatformCapabilityInventory::KNOWN_ABSENT`, id
`code_execution`). This sprint closes it in the narrowest honest way: one runner, one card, one provenance value, and the
same quota lanes every other paid action uses. It leaves the gateway tool (B2) and persistent workspaces (B3) out, so the
first PR that touches the planner is small enough to prove C1 with the existing characterization suite, not by argument.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Service/SelfAware/PlatformCapabilityInventory.php` (`KNOWN_ABSENT`); `backend/src/Service/Multitask/Plan/Capability.php`, `Skill/SkillDescriptor.php` (`available` closure), `Skill/SkillCatalog.php` | Entry to make conditional on `ComputeConfig`; new case + descriptor whose `available` keeps the planner blind when off |
| `backend/src/Service/Multitask/Execution/TaskRunner.php`, `Runner/DocumentGenerationRunner.php`, `Runner/MediaGenerationRunner.php` | Runner shape; a model-authored step output feeding a no-model step |
| `backend/src/Service/Multitask/Execution/Parallel/{MediaNodeDispatcher,ProcessMediaNodeJob}.php`, `config/packages/messenger.yaml` (`async_ai_high`); `backend/src/Service/Media/{MediaJob,MediaJobStore,MediaJobRealtimeNotifier}.php` | Long nodes run as worker jobs, not request-bound; progress → Centrifugo pattern for the card |
| `backend/src/Service/File/GeneratedDocumentStore.php` (`store()`), `backend/src/Entity/File.php` (`SOURCES`, `ORIGIN_KINDS`, `BSOURCE`, `BORIGINKIND`) | Artefact ingest path and provenance columns |
| `backend/src/Service/RateLimitService.php` (`checkLimit`, `recordUsage`), `backend/src/Seed/RateLimitConfigSeeder.php` (`RATELIMITS_<LEVEL>`); `frontend/src/components/multitask/{TaskCard,TaskCardMedia,TaskPlanBubble}.vue` | Quota lanes per `BUSERLEVEL` (`NEW`, `PRO`, `TEAM`, `BUSINESS`); card kinds switch where the new `compute` kind plugs in |
| `backend/tests/Characterization/` (`utterance_plans.json`, `planner_system_prompt.txt`); `backend/tests/Fixtures/compute-contract/` (`CP27`) | Must not change with the flag off; DTOs are built from the fixtures |

---

## 2. Developer steps

### 2.1 `ComputeConfig` and `ComputeClient` (`CS1`)

`backend/src/Service/Compute/ComputeConfig.php` (`CONFIG_GROUP = 'COMPUTE'`; `isEnabled()` = flag `ENABLED` on **and**
`COMPUTE_URL` **and** `COMPUTE_TOKEN` non-empty). `ComputeClient.php` (`final readonly`, Symfony `HttpClientInterface`):
`health()`, `submitRun(ComputeRunRequest, iterable $files): string` (multipart, `request.json` first), `status($runId):
ComputeRunStatus`, `streamLogs($runId, callable $onEvent)` (chunked SSE consumer with `Last-Event-ID`), `listArtefacts`,
`downloadArtefact($runId, $name)`, `cancel($runId)`. DTOs under `Service/Compute/Contract/` are built from the vendored
fixtures; `ComputeContractFixtureTest` (skipped since `CP27`) now runs fully. `ComputeRefusedException` carries the problem+json `code`.

### 2.2 Migration `BCOMPUTERUNS` (`CS2`)

```sql
CREATE TABLE IF NOT EXISTS BCOMPUTERUNS (
  BID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  BUSERID INT NOT NULL,
  BPROMPTID INT NULL,
  BMESSAGEID BIGINT NULL,
  BSAVEDTASKRUNID BIGINT NULL,
  BRUNID VARCHAR(26) NOT NULL,
  BINVOKEDVIA VARCHAR(32) NOT NULL,
  BIMAGE VARCHAR(32) NOT NULL,
  BPROGRAM VARCHAR(32) NOT NULL,
  BLIMITS JSON NOT NULL,
  BSTATUS VARCHAR(16) NOT NULL,
  BEXITCODE INT NULL,
  BREASON VARCHAR(32) NULL,
  BDURATIONMS INT NULL,
  BBYTESIN BIGINT NOT NULL DEFAULT 0,
  BBYTESOUT BIGINT NOT NULL DEFAULT 0,
  BARTEFACTIDS JSON NULL,
  BEGRESSHOSTS JSON NULL,
  BWORKSPACEID VARCHAR(26) NULL,
  BCREATED DATETIME NOT NULL,
  BFINISHED DATETIME NULL,
  PRIMARY KEY (BID),
  UNIQUE KEY uq_computerun_runid (BRUNID),
  KEY idx_computerun_user_created (BUSERID, BCREATED),
  KEY idx_computerun_status (BSTATUS)
) DEFAULT CHARSET=utf8mb4;
```

Raw `addSql` only, no `Schema` API (Galera rule). Entity `ComputeRun`, `ComputeRunRepository`. Metadata only — no stdout, no script text.

### 2.3 `Capability::CodeRun` and `CodeRunRunner` (`CS3`, `CS4`)

`Capability::CodeRun = 'code_run'`, `uiKind() → 'compute'`. Node inputs: `script` (the model's step output, written
as `main.py` or `main.js`), `image` (`python` | `node`), `inputFileIds` (selected conversation files, owner-checked
through `FileRepository`), optional `limits` clamped to `ComputeConfig` defaults. `Execution/Runner/CodeRunRunner.php`
declares `SkillDescriptor(capability: CodeRun, summary: "Run a short Python or Node script on the attached files and
return result files", available: fn() => $computeConfig->isEnabled())` — when unavailable the planner never sees the
line (**C1**). Flow: quota check → `BCOMPUTERUNS` row `queued` → `submitRun` → stream status/logs to the card → ingest
artefacts → row `succeeded|failed`. Long runs go through the `MediaNodeDispatcher` / `ProcessMediaNodeJob` seam on
`async_ai_high` (a `code_run` branch, no new transport). Refusals (`ComputeRefusedException`, quota) become a readable
`NodeResult` failure, never a user-facing exception.

### 2.4 `PlatformCapabilityInventory` flip (`CS5`) and run card (`CS6`, ota-candidate)

`code_execution` leaves `KNOWN_ABSENT` when `ComputeConfig::isEnabled()`; the class takes `ComputeConfig` in its constructor
and filters the constant; `PlatformCapabilityInventoryComputeTest` asserts both states. `frontend/src/components/multitask/ComputeRunCard.vue`
for kind `compute`: status pill (Queued / Running / Done / Failed), elapsed, exit code, collapsed logs (truncation marker
shown honestly), artefact chips reusing the existing file chip + preview, and **Re-run with changes** (opens the script in
a text area, re-submits as a new node through the existing follow-up path). Words from master plan §5 in all five locales;
never "sandbox", "container", "stdout" in primary copy. Path added to `ota-candidate` in `.github/mobile-impact-policy.json` and `tests/mobile-impact.test.mjs`.

### 2.5 Artefacts → `BFILES` (`CS7`)

`Service/Compute/ComputeArtefactStore.php` mirrors `GeneratedDocumentStore::store()`: downloads each accepted artefact,
re-checks size and MIME against the Synaplan upload allow-list, writes through the normal file path, sets
`setSource('compute')`, `setOriginKind('artefact')`, links to the message, records ids in `BARTEFACTIDS`. `File::SOURCES`
+= `compute`, `File::ORIGIN_KINDS` += `artefact` (varchar columns, no schema change). Vectorization only through the
existing upload path and quotas (**C5**); nothing from `/out` is ever opened by PHP as code or template.

### 2.6 Quotas in rate-limit lanes (`CS8`), seeder and config surface (`CS9`), demo (`CS10`)

`RateLimitConfigSeeder` adds per lane (`RATELIMITS_NEW|PRO|TEAM|BUSINESS`; `ANONYMOUS` gets `0`): `COMPUTE_RUNS_HOURLY`,
`COMPUTE_CONCURRENT`, `COMPUTE_CPU_SECONDS_DAILY`, `COMPUTE_WORKSPACE_MB` (used in B3). `RateLimitService::checkLimit($user,
'compute_run')` before submit, `recordUsage($user, 'compute_run', ['cpuSec' => …])` after; the compute service's own caps
stay the hard ceiling. Both refusals render in the card. `Seed/ComputeConfigSeeder.php` (`BConfigSeeder::insertIfMissing`):
`ENABLED=0`, `DEFAULT_TIMEOUT_SEC=60`, `DEFAULT_MEMORY_MB=512`, `DEFAULT_CPU=1.0`, `DEFAULT_PIDS=128`, `DEFAULT_OUTPUT_MB=50`,
`MAX_TIMEOUT_SEC=300`; exposed in `SystemConfigService` with `dbGroup: ComputeConfig::CONFIG_GROUP` so `AdminConfigView.vue`
(Operate → System config) renders them. `_devextras/testing/compute/csv-chart.sh` (`CS10`): uploads `data.csv`, sends "Make a
bar chart of revenue per region from this file", waits for the card, asserts a `BFILES` row with `BSOURCE = compute`. Not part of the gate.

---

## 3. Tests and invariants

- **C1**: `CodeRunRunnerAvailabilityTest` (descriptor omitted when off); `RoutingCharacterizationTest`, `UtterancePlanCharacterizationTest`,
  `PlannerPromptCharacterizationTest` run with the flag off and show an empty diff — snapshots are **not** re-recorded in this sprint.
  **C2**: `scripts/check-no-docker-sock.sh` (from `CP30`) still green.
- **C3**: `ComputeClientTest::testSubmitAlwaysSetsOwner`, `CodeRunRunnerTest::testRejectsForeignFileIds`, `::testClampsLimitsToCaps`.
- **C5**: `ComputeArtefactStoreTest::testRejectsDisallowedMime`, `::testNeverVectorizesDirectly`, `::testSetsComputeProvenance`.
  **C7**: `ComputeContractFixtureTest` fully green (checksums + DTO decode).
- Quotas: `ComputeRunQuotaTest` per lane; refusal text asserted in `ComputeRunCard.spec.ts`. i18n: five locales for every card string; `localeParity.spec.ts` green. Full unfiltered gate per PR.

---

## 4. Exit criteria / demo

1. Dev instance with `--profile compute`, `COMPUTE_URL`, `COMPUTE_TOKEN`, `COMPUTE.ENABLED=1`: "Make a chart from this CSV" yields a
   PNG chip and a `BFILES` row `BSOURCE=compute`, `BORIGINKIND=artefact`; `BCOMPUTERUNS` shows image, limits, exit code, duration, bytes, artefact ids — no script or output text.
2. Flag off: `code_execution` listed under unavailable, planner catalog without `code_run`, characterization diff empty.
3. Quota exceeded for a `NEW` user is a readable card message, not a 500.
4. Mobile-impact: PHP `backend-only`, card `ota-candidate`, policy test green.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| CS1 | `feat(compute-client): ComputeConfig and ComputeClient over protocol 1` | backend-only | A3 |
| CS2 | `feat(compute-client): BCOMPUTERUNS migration and repository` | backend-only | — |
| CS3 | `feat(multitask): Capability::CodeRun with availability-gated skill` | backend-only | CS1 |
| CS4 | `feat(multitask): CodeRunRunner with worker dispatch and audit rows` | backend-only | CS2, CS3 |
| CS5 | `feat(compute-client): flip code_execution in PlatformCapabilityInventory` | backend-only | CS1 |
| CS6 | `feat(multitask): compute run card with logs, artefacts and re-run` | ota-candidate | CS4 |
| CS7 | `feat(compute-client): ingest artefacts as BFILES source compute` | backend-only | CS4 |
| CS8 | `feat(compute-client): compute quotas in rate-limit lanes` | backend-only | CS4 |
| CS9 | `feat(compute-client): seeder defaults and system config fields` | backend-only | CS1 |
| CS10 | `test(compute-client): csv to chart dev demo script` | backend-only | CS6, CS7 |
