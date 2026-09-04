# Sprint B3 — Workspaces and egress

**Phase B (`synaplan/`), sprint 3 of 4.** Steps `CS18`–`CS25`.

**Goal:** A user's compute work can persist and, where the instance allows it, a run can talk to a short
allow-list of hosts. `BCOMPUTEWORKSPACES` maps a user to an opaque compute workspace id; "Open workspace"
shows its files in the existing Files UI; the per-run egress allow-list is resolved and pinned by PHP through
`SsrfGuard` and refused by compute for anything else; admins set limits in Operate → System config; the
feature is documented for users, hosters and the platform team.
**Depends on:** B2 (policy — egress and workspace writes never loosen below the tool policy); A2 `CP17`,
`CP22` (compute side of workspaces and egress). Schema ask recorded in master plan §0 row 15.
**Unlocks:** B4. **Repos:** `synaplan/`; a page in `synaplan-docs`; a pointer in `synaplan-platform` (private).
**Flag:** `COMPUTE.WORKSPACES_ENABLED` (default `0`), `COMPUTE.EGRESS_ENABLED` (default `0`). Both sit
under `COMPUTE.ENABLED`; off means B1/B2 behaviour.

---

## 0. Why this sprint exists

Offline, ephemeral runs are a complete v1 (master plan cut line). What they cannot do is build on
yesterday's result or fetch one public dataset. Both additions widen the boundary, so both are default-off
per instance (§11 decisions 5 and 6) and both reuse boundaries that already exist: workspace ownership is
enforced by compute (`CP17`) with PHP only mapping user → id, and egress hosts pass through the same
`SsrfGuard` every outbound fetch in Synaplan already passes. The cut order stays: egress first, persistent
workspaces second, if capacity forces a choice.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `backend/src/Service/Security/SsrfGuard.php` (`isBlockedHost`, private `resolveIps`) | Needs a public `pinnedIps(host): list<string>` returning the resolved, non-blocked addresses |
| `backend/src/Service/Compute/{ComputeClient,ComputeConfig,CodeRunRunner}.php` (B1) | Client gains workspace calls; runner gains `workspace` and `egress` in the request |
| `backend/src/Service/File/FileListService.php`, `backend/src/Controller/FileController.php`, `frontend/src/components/files/{FilePreview,DocumentPreviewModal}.vue` | Files UI to reuse for the workspace browser — list + preview + download, no upload in v1 |
| `backend/src/Service/Admin/SystemConfigService.php` (`dbGroup` fields), `backend/src/Controller/AdminSystemConfigController.php`, `frontend/src/views/AdminConfigView.vue` | Where the limits UI renders from field definitions |
| `backend/tests/Fixtures/compute-contract/{run_request_user_workspace,run_request_egress,workspace_*}.json` | Frozen shapes PHP must emit |
| `docs/DESKTOP.md`, `docs/CONNECTIONS.md`; `/wwwroot/synaplan-docs/docs/{desktop,tts,office-documents}.md` | Tone and structure for `docs/COMPUTE.md` and the docs-site page |

---

## 2. Developer steps

### 2.1 Migration `BCOMPUTEWORKSPACES` (`CS18`)

```sql
CREATE TABLE IF NOT EXISTS BCOMPUTEWORKSPACES (
  BID INT NOT NULL AUTO_INCREMENT,
  BUSERID INT NOT NULL,
  BWORKSPACEID VARCHAR(26) NOT NULL,
  BQUOTAMB INT NOT NULL,
  BUSEDMB INT NOT NULL DEFAULT 0,
  BSTATUS VARCHAR(16) NOT NULL DEFAULT 'active',
  BCREATED DATETIME NOT NULL,
  BLASTUSED DATETIME NULL,
  BEXPIRESAT DATETIME NULL,
  PRIMARY KEY (BID),
  UNIQUE KEY uq_computews_user (BUSERID),
  UNIQUE KEY uq_computews_wsid (BWORKSPACEID),
  KEY idx_computews_expires (BEXPIRESAT)
) DEFAULT CHARSET=utf8mb4;
```

One workspace per user in v1 (`uq_computews_user`). Raw `addSql`, no `Schema` API. Entity `ComputeWorkspace`,
repository, and `Service/Compute/ComputeWorkspaceService.php`: `forUser(User): ?ComputeWorkspace`, `ensure(User)`
(creates on compute with `owner = user:{id}` and `quotaMb = RATELIMITS_<LEVEL>.COMPUTE_WORKSPACE_MB`),
`refreshUsage`, `delete`. PHP never stores or passes a path.

### 2.2 Workspace runs (`CS19`) and the "Open workspace" browser (`CS20`)

`CodeRunRunner`: when `COMPUTE.WORKSPACES_ENABLED` and the node input `useWorkspace = true`, the request carries
`workspace: { kind: "user", id }` and `owner: "user:{id}"` — the same owner string, so compute's ownership check
(`CP17`) is the real boundary. The card gains an **Open workspace** chip. `CS20` (ota-candidate):
`GET /api/v1/compute/workspace` (usage, quota), `GET /api/v1/compute/workspace/files?path=`,
`GET /api/v1/compute/workspace/files/{path}` proxy to compute for the session user's own workspace only; the
frontend route `/files/workspace` renders the existing file list and preview components against this source
with a read-only toolbar (download; delete whole workspace via `useDialog().confirm({ danger: true })`). Words:
Workspace / Arbeitsbereich / Espacio de trabajo / Espace de travail / Çalışma alanı. Nav: child of Files, no
new rail item.

### 2.3 Egress allow-list through `SsrfGuard` (`CS21`)

`Service/Compute/ComputeEgressResolver.php`: input is the list of host names the policy allows for this run
(assistant definition from track 2, capped by `COMPUTE.EGRESS_MAX_HOSTS`, default 8); for each host:
`SsrfGuard::isBlockedHost` → refuse; `SsrfGuard::pinnedIps(host)` (new public method exposing the existing
resolution) → `{ host, port: 443, ips }`; empty resolution → refuse. Output is the frozen `egress.allow` shape;
compute connects only to those IPs (`CP22`) and refuses everything else. `COMPUTE.EGRESS_ENABLED = 0` (default)
means the resolver always returns `allow: []` and the run stays offline. A request with egress **never** lowers
the B2 policy decision; an instance may additionally require `approve` for any run with egress
(`COMPUTE.EGRESS_REQUIRES_APPROVAL`, default `1`).

### 2.4 Admin limits UI (`CS22`)

`SystemConfigService` field group `dbGroup: ComputeConfig::CONFIG_GROUP` rendered in Operate → System config
(`AdminConfigView.vue`): `ENABLED`, `WORKSPACES_ENABLED`, `EGRESS_ENABLED`, `EGRESS_REQUIRES_APPROVAL`,
`EGRESS_MAX_HOSTS`, `DEFAULT_TIMEOUT_SEC`, `DEFAULT_MEMORY_MB`, `DEFAULT_CPU`, `DEFAULT_OUTPUT_MB`,
`MAX_TIMEOUT_SEC`, `POLICY_INTERACTIVE`, `POLICY_UNATTENDED`, `WORKSPACE_TTL_DAYS` (default 90; used by B4
cleanup). Values above the compute caps from `/v1/health` are refused on save with a message naming the cap.
Helper text may say "isolated container"; labels use the master plan §5 vocabulary.

### 2.5 Documentation (`CS23`, `CS24`, `CS25`)

- `docs/COMPUTE.md` (`CS23`, in `synaplan/`): what compute is in three sentences, the flag and env, the `compute`
  profile (T1) with its honest limits, T2 on a separate node, quotas per lane, provenance `source=compute`, the
  `compute:run` scope, policy defaults, workspaces and egress, the audit join `BCOMPUTERUNS` ↔ `BAPPROVALS`, and
  "Related" links to `docs/DESKTOP.md` and `docs/ANTHROPIC_COMPATIBLE_API.md`.
- `synaplan-docs/docs/compute.md` (`CS24`): the user- and hoster-facing page (Run, Result files, Workspace; how
  to enable; what the AI can and cannot do); linked from `desktop-skills.md` as the server-side counterpart and
  registered in the docs manifest so `PlatformCapabilityInventory` can point to `docsSlug: 'compute'`.
- `synaplan-platform` guide pointer (`CS25`, private): one section linking `docs/DEPLOYMENT.md` of the compute
  repo for the T2 node; no node details here.

---

## 3. Tests and invariants

- **C3**: `ComputeWorkspaceServiceTest::testOwnerStringMatchesUser`, `::testNoPathEverSent`;
  `CodeRunRunnerTest::testWorkspaceOnlyWhenFlagOn`.
- **C4**: `ComputeEgressResolverTest::testBlockedHostRefused`, `::testPrivateResolutionRefused`,
  `::testPinnedIpsEmitted`, `::testEgressDisabledYieldsEmptyAllow`, `::testMaxHostsCap`;
  `ComputePolicyTest::testEgressNeverLoosensDecision`.
- **C5**: workspace files are listed and downloaded, never ingested or vectorized unless the user saves one to
  Files through the existing upload path (`ComputeWorkspaceControllerTest::testNoImplicitIngest`).
- **C1**: both flags off → B1/B2 suites and characterization unchanged. Cross-user (§4.3):
  `ComputeWorkspaceControllerTest::testOtherUsersWorkspace404`.
- Admin UI: `SystemConfigServiceComputeTest::testRefusesAboveComputeCaps`; fields in five locales;
  `localeParity.spec.ts` green. Fixtures: `run_request_user_workspace.json` and `run_request_egress.json`
  produced byte-equal by the PHP request builder (`ComputeRequestBuilderTest`).

---

## 4. Exit criteria / demo

1. Two runs by the same user with `useWorkspace`: the second reads the first's output from `/workspace`;
   Files → Workspace lists and previews it. Another user's session cannot list that workspace (404).
2. With `EGRESS_ENABLED = 1` and an assistant allowing `data.example.org`, a script downloads from it and fails
   for any other host; the audit row shows `BEGRESSHOSTS`. With the flag off the same run has no network.
3. Operate → System config saves compute limits and refuses a timeout above the compute cap with a readable message.
4. `docs/COMPUTE.md` and the docs-site page published; full gate green.

---

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| CS18 | `feat(compute-client): BCOMPUTEWORKSPACES migration and workspace service` | backend-only | B2 |
| CS19 | `feat(multitask): user workspace runs behind COMPUTE.WORKSPACES_ENABLED` | backend-only | CS18 |
| CS20 | `feat(files): open compute workspace in the files UI` | ota-candidate | CS19 |
| CS21 | `feat(compute-client): per-run egress allow-list resolved through SsrfGuard` | backend-only | B2 |
| CS22 | `feat(admin): compute limits and policy fields in system config` | ota-candidate | CS21 |
| CS23 | `docs: COMPUTE.md for flag, profile, quotas, policy, workspaces and egress` | backend-only | CS22 |
| CS24 | `docs(site): compute page and desktop-skills cross-link` — in `synaplan-docs` | n.a. (docs repo) | CS23 |
| CS25 | `docs(platform): compute node pointer` — in `synaplan-platform` | n.a. (private repo) | CS23 |
