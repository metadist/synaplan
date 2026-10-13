# Agent briefs — how coding agents build this plan

One brief is one unit of work for one coding agent: one repository, one
branch, one pull request, a fixed list of files, named tests and a functional
acceptance. The briefs follow the steps of [`06_sprints.md`](./06_sprints.md)
one to one; the plan sections they cite are the contract, this file is the
work order. Functional tests after each sprint are in
[`09_functional_tests.md`](./09_functional_tests.md); the stacks are in
[`10_local_environments.md`](./10_local_environments.md).

---

## 0. The contract for an agent

### 0.1 Before you start a brief

1. [`00`](./00_master_plan.md) §3 is ticked in STATUS for every row the
   brief cites. If not, stop — no code before the decisions.
2. Every brief in **Depends on** is merged; for a core brief that an app
   brief depends on, the core change is also **released** (the app must never
   need an unreleased core — C1).
3. `env-check.sh` (`10` §5) is green for the profile the brief names.
4. Read the sections in **Reads** completely. They are short on purpose.
5. Run the repo gate once on a clean checkout of `main` to know the baseline.
6. Create the branch named in the brief from `main`.

### 0.2 While you work — rules R1–R14

| # | Rule |
| - | ---- |
| R1 | **Scope is the Build list.** A file not in **Touches** needs one sentence in the PR saying why. A feature not in **Build** is a suggestion in the PR text, not a change. |
| R2 | **Copy comes from the plan.** User-facing sentences are taken verbatim from `05` §3, `03` §6, `04` §A.1 / §C.4, `07` §10 and `02` §6.3, in all five locales in the same commit. No banned word of `00` §11 in primary copy. |
| R3 | **Contracts first.** Backend endpoints get full OpenAPI annotations; then `make -C frontend generate-schemas` and `vue-tsc`. No hand-written TypeScript interface for an API response. |
| R4 | **Schema only as written.** Tables and columns exactly as the cited schema section lists them (`02` §9, `03` §4, `04` §E.2, `07` §11). Raw idempotent `addSql`, no Schema API, children before parents (C11). Anything else is an ask (A1). |
| R5 | **Flags.** Identity and copies sit behind `PLATFORM_LINKS.ENABLED`; file state and watches behind the `cloud_files` FeatureModule. Off ⇒ route 404, surface absent, capability missing from `instances/self`. |
| R6 | **Terminal states.** Every ledger row, run, copy and pickup ends in a terminal state with one sentence; a sweep covers the rest (C8). No `setTimeout` race fixes. |
| R7 | **Tests named in the brief exist and pass** before the PR. A test the brief names but you did not write is a red PR. |
| R8 | **Gates unfiltered.** The repo's full gate (`06` §0) before every commit; the push gate before the PR. A filtered run is diagnostic. |
| R9 | **Mobile class.** Core PHP → `backend-only`; core UI → `ota-candidate`. New `frontend/` paths go into `.github/mobile-impact-policy.json` in the same PR; run `node scripts/mobile-impact.mjs --base main --head HEAD` and delete the manifest it writes (C12). |
| R10 | **Nothing private.** No hostnames, IPs, keys or tenant names of real installations (C13). Dev defaults (`localhost`, `host.docker.internal`, `example.org`) only. |
| R11 | **Apps hide what the core lacks.** An app feature checks `instances/self.capabilities` (or the runtime config block) first and renders nothing otherwise — never a disabled control, never a teaser (U11). |
| R12 | **No fourth auth scheme.** Person-held credential for interactive and unattended work; instance credential for the listed instance operations (`02` §4); nothing else. |
| R13 | **English in code and commits.** Conventional Commits with the type the brief gives; no AI attribution. |
| R14 | **Record as you go.** Facts you verified (file names, versions, API behaviour) go into the PR text; decisions that the plan did not make go to STATUS as a dated entry, not into code comments. |

### 0.3 Before you open the PR — definition of done D1–D10

| # | Done means |
| - | ---------- |
| D1 | Every **Build** item is in the diff; nothing else is. |
| D2 | Every **Tests** item exists and passes; the unfiltered gate is green. |
| D3 | Every **Acceptance** row was executed on the named stack and its result is in the PR text (and the sprint's `09` script, if it exists, passes). |
| D4 | Copy in five locales; locale-parity test green; banned-word check green (`cf1-locales.sh` from CF1.4 on). |
| D5 | OpenAPI → schemas → `vue-tsc` done for every endpoint change. |
| D6 | Flag off and older-core behaviour checked (R5, R11) where the brief touches a surface. |
| D7 | Light, dark, 320 px (Synaplan) or the host's narrow width (apps) for every new surface; one line in the PR per theme. |
| D8 | Mobile class recorded (core) or "app release" noted (apps). |
| D9 | STATUS: the step row set to `in review` with the PR link; a dated entry for any decision or spike answer. |
| D10 | The PR text follows §0.5. |

### 0.4 Stop and ask — A1–A7

| # | Ask when |
| - | -------- |
| A1 | A table, column or index is needed that the cited schema section does not list. |
| A2 | A new dependency (composer, npm, Go module, native plugin). |
| A3 | A change to CI, Docker, build or release files not named in **Touches**. |
| A4 | A plan sentence cannot be implemented as written (platform limit found). Record the finding, propose the fallback, wait. |
| A5 | A spike answer is "no" and the fallback would change a user-visible flow. |
| A6 | Anything that would read, log or store a secret differently than `02` §10 says. |
| A7 | The brief's Acceptance cannot be executed because a stack is missing — do not substitute a mock for a walk. |

### 0.5 PR text template

```markdown
## Brief
CF?.? — <title> (plan: 20261013-cloud-file-sharing/06 §?, briefs 08 §?)

## What changed
- …

## Contract
Endpoints / schema / capabilities touched (plan section): …

## Tests
- L1: <test classes / spec files>  → green, unfiltered gate: `<command>` ✓
- L2: `_devextras/testing/cloud-apps/<script>` → PASS n/n on <cloud> (<versions>)
- L3: <journey ids walked, stack, account, what was seen>

## Flags, class, locales
PLATFORM_LINKS / cloud_files off: <behaviour> · class: backend-only | ota-candidate | app release · locales: en de es fr tr ✓

## Not in this PR
<anything noticed but out of scope>
```

### 0.6 Branches and commit types

| Repo | Branch | Commit type |
| ---- | ------ | ----------- |
| all | `<type>/cf<wave>-<step>-<slug>` (e.g. `fix/cf0-1-platform-key-scope-map`, `feat/cf3-1-platform-destination`) | CF0 defects `fix(<area>)`; docs `docs(…)`; CI / tooling `chore(…)` or `ci(…)`; everything else `feat(<area>)` — the type drives the release version (`AGENTS.md`) |
| all | spikes: `spike/cf-s<n>-<slug>`, never merged; the answer is a STATUS entry (and a doc page if useful) | — |
| all | environment briefs: `chore/env-<n>-<slug>` | `chore(dev)` |

### 0.7 Repo cheat-sheet (where things live)

| Repo | Paths an agent will touch in this track |
| ---- | --------------------------------------- |
| `synaplan` backend | `backend/src/Security/ApiKeyScope.php`, `ApiKeyScopeSubscriber.php`, `OidcBearerAuthenticator.php` · `backend/src/Entity/{File,PlatformInstance,ExternalIdentity,SavedTask,SavedTaskRun,UrlWatch}.php` · `backend/src/Controller/{FileController,FileSendController,PlatformLinkController,PlatformInstanceController,AdminPlatformInstanceController,MePlatformLinkController,SavedTaskController,ApiKeyController}.php` · `backend/src/Service/PlatformLink/*` · `backend/src/Service/Destination/*` · `backend/src/Service/SavedTask/*` (`SavedTaskRunner.php`, `SavedTaskTickService.php`, `Graph/StepInputResolver.php`) · `backend/src/Service/Multitask/Plan/Capability.php`, `backend/src/Service/Multitask/Execution/Runner/*` · `backend/src/Service/Compute/ComputeConfig.php` · `backend/src/Service/Dav/WebDavClient.php`, `backend/src/Service/Security/SsrfGuard.php` · `backend/src/Module/*` (`Federation/FederationModule.php` is the pattern to copy) · `backend/migrations/` · `backend/tests/Unit/Security/ApiKeyScopeTest.php`, `backend/tests/Unit/Service/SavedTask/SavedTaskComputePauseTest.php` · `backend/.env.minimal`, `docker-compose.minimal.yml` |
| `synaplan` frontend | `frontend/src/apps/{catalog,status}.ts`, `frontend/src/components/apps/{AppCard,AppPanelHost}.vue`, `panels/`, `frontend/src/views/AppsView.vue` · `frontend/src/components/files/{FilePushMenu,FileVectorPill,FileSourceBadge,FilesGrid,FileRowActions}.vue`, `frontend/src/views/FilesView.vue` · `frontend/src/components/config/SavedTasksOverview.vue` · `frontend/src/i18n/locales/{en,de,es,fr,tr}/*.json`, `frontend/src/i18n/namespaces.ts` · `frontend/src/generated/api-schemas.ts` (generated only) · `.github/mobile-impact-policy.json` |
| `synaplan` testing / docs | `_devextras/testing/cloud-apps/` (E-6), `_devextras/testing/platform-links/` (reuse), `_docker/keycloak/setup.sh` (E-3), `docs/DEVELOPMENT.md`, this folder's `STATUS.md` |
| `synaplan-nextcloud` | `appinfo/{info.xml,routes.php}` · `lib/Controller/{ApiController,ChatController,ConsentController,LinkController,MediaController,SettingsController,AdminAiUsersController,PageController}.php` · `lib/Service/{SynaplanClient,SynaplanConfig,PlatformLinkService,UserAccountService,LanguageService}.php` · `lib/Listener/UserDeletedListener.php` · `lib/Settings/` · `src/{files-init.ts,files_actions/,components/,personal-settings.ts,settings.ts,chat-launcher.ts,research.ts}` · `l10n/` · `tests/Unit/` · `.github/workflows/` (new) · `docker-compose.dev.yml.example`, `docker/dev-hooks/`, `Makefile` |
| `synaplan-owncloud-online` | same layout as Nextcloud without `lib/Listener` and `LinkController` (both added in CF1.1) · `src/shared/` · `l10n/` (empty today) · `tests/` (empty today) · `.github/workflows/` (new) · `docker/` (new, E-5) |
| `synaplan-opencloud` | `backend/internal/{handler,cs3reader,synaplanapi,synaplanauth,tokenexchange}/` (`handler/{files,knowledge,me,summarize,translate,synaplan_call}.go`) · `frontend/src/{index.ts,manifest.json}`, `extensions/*.ts`, `components/*.vue`, `composables/*.ts`, `views/Synaplan.vue` · `frontend/tests/e2e/` · `dev/docker/*`, `docker-compose.yml` · `README.md` |
| `synaplan-docs` | `docs/plugins.md`, `docs/intro.md` |

### 0.8 Test hooks the apps must offer (dev mode only)

The L2 scripts of `09` need three switches in every app. They are off by
default, never visible in any UI, readable only through the app's admin CLI
(`occ config:app:set <app> dev_<name> --value 1` for Nextcloud and ownCloud,
`SYNAPLAN_DEV_<NAME>=1` on the Go service for OpenCloud) and documented in
the app's `README.md` under "Development":

| Switch | Effect | Introduced by |
| ------ | ------ | ------------- |
| `dev_hold_inbound` | The inbound endpoint accepts a verified notification and does nothing until the switch is cleared (tests the `pending` / `unconfirmed` path) | CF3.3 / CF3.4 / CF3.5 |
| `dev_mute_events` | File events are not forwarded (tests reconciliation) | CF4.3 / CF4.4 / CF4.5 |
| `dev_allow_self_write` | A watch may write its output into its own folder (tests the loop guard) | CF5.4 / CF5.5 / CF5.6 |

Production builds keep the switches; they are harmless when off and let a
hoster reproduce a report. The core needs two test-only environment values,
both read only when `APP_ENV=dev|test`: `CLOUD_DELIVERY_TTL_SEC` (ticket
lifetime, default 300) and `CLOUD_DELIVERY_UNCONFIRMED_SEC` (sweep threshold,
default 600) — introduced by CF3.1.

---

## 1. Dependency and parallelism map

```text
E-1 → E-3 → { E-2 ‖ E-4 } → E-5 → E-6 → E-7                 (10 §4; before CF0.7)

CF-S1 ‖ CF-S2 ‖ CF-S3 ‖ CF-S4 ‖ CF-S5                         (timebox 2 days each, parallel to CF0)

CF0.1 ‖ CF0.2 ‖ CF0.3 ‖ CF0.4   → release → CF0.5 ‖ CF0.6 → CF0.7 (walks)

CF1.1 → CF1.2 (ownCloud)  ‖  CF1.3 (Nextcloud)  ‖  CF1.5 (OpenCloud)  → CF1.4 (all three, last)

CF2.1 → CF2.2 → CF2.3 → CF2.4 → CF2.5 → release → CF2.6 ‖ CF2.7 ‖ CF2.8
CF2.2 → CF2.9 → release                                     (beside CF2.3–CF2.5; CF4.1 needs it)

CF3.1 → CF3.2 → release → CF3.3 ‖ CF3.4 ‖ CF3.5
CF3.6 → CF3.7 → release → CF3.8 ‖ CF3.9 ‖ CF3.10            (CF3.6 may start with CF3.1)

CF4.1 → CF4.2 → release → CF4.3 ‖ CF4.4 ‖ CF4.5              (CF4 core may run beside CF3 core)

CF5.1 → CF5.2 → CF5.7 → CF5.8 → CF5.3 → release → CF5.4 ‖ CF5.5 ‖ CF5.6 → CF5.9 (all three)
```

`‖` = may run in parallel (different repos or independent files). "release"
= a tagged Synaplan release that answers the new capability in
`instances/self`. Two agents never work on the same file list at the same
time; a brief that must touch a file another open brief touches waits.

---

## 2. Spike briefs (CF-S1 … CF-S5)

Common to all spikes: **timebox two working days**; branch `spike/…` with
throw-away code; deliverable = a dated STATUS entry with one line per question
(**yes / no / partly**, version tested, code pointer or test), the fallback
chosen for every "no" (from `06` §1), and the follow-up briefs whose text must
change. No spike code is merged. Stack per `10`.

### CF-S1 — OpenCloud web extension points

| | |
| - | - |
| Repo | `synaplan-opencloud` (web extension, OpenCloud 8.1 web SDK) |
| Reads | `06` §1 row S1; `04` §A.4, §B.8; `03` §5 (OpenCloud column); `frontend/src/extensions/*.ts` |
| Questions | (1) Row indicator / custom cell in the resource list? (2) Create-menu entry ("From Synaplan…")? (3) Folder context actions (two)? (4) `sidebarPanel` for files **and** folders? (5) Account / settings page extension? (6) User notifications from an extension? (7) Private-link format for a file id? |
| Method | Read the SDK's extension registry types in `node_modules/@opencloud-eu/web-pkg` (extension points, `ActionExtension`, `SidebarPanelExtension`, custom components); prototype each point in `useSynaplanActionsExtension.ts` on the `:9201` dev server; screenshot each working point |
| Blocks | CF2.7, CF3.5, CF3.10, CF4.5, CF5.6 |

### CF-S2 — Nextcloud organisation login through `user_oidc`

| | |
| - | - |
| Repo | `synaplan-nextcloud` on the E-2 stack with `NC_OIDC=1` |
| Reads | `06` §1 row S2; `02` §5.1; `10` §3.2 |
| Questions | (1) Which `user_oidc` version exposes the login token or a token-exchange call to apps (events, `store_login_token`)? (2) Does the exchanged token carry `azp` = the Nextcloud client id? (3) Refresh behaviour and expiry handling? (4) Works with Keycloak; expected to work with openDesk's Nubus (document the difference, do not test)? (5) Version floor for `appinfo/info.xml` |
| Method | Install `user_oidc` from the app store on the dev stack; log `ada` in through Keycloak; from a test controller read the token the app can get; decode it; call `GET /api/v1/auth/me` on Synaplan with it as bearer; record the result for each question |
| Blocks | CF2.6 |

### CF-S3 — OpenCloud unattended access

| | |
| - | - |
| Repo | `synaplan-opencloud` (Go service) on the E-4 stack |
| Reads | `06` §1 row S3; `02` §4; `03` §5; `07` §4; `04` §B.3 |
| Questions | (1) Service account or impersonation that lists and reads/writes a user's files without a request? (2) Event bus (NATS) consumer for upload finished, move, trash, restore, user deleted — config and delivery guarantees? (3) Unprotected proxy route for `/api/synaplan/inbound`? (4) Encrypted persistent store for per-user keys (Connect mode)? (5) Project spaces: events and writes? (6) Memory and rebuild time of the in-memory watch index at the cap — 5 000 watches over 10 pages (`04` §B.8)? |
| Method | Enable the service account and NATS exposure under `dev/docker/` (env overrides, documented); write a throw-away Go command that lists `ada`'s `Invoices` and uploads one file as `ada`; subscribe to the bus and print events while uploading in the UI; add a proxy route and call it unauthenticated |
| Blocks | CF2.7, CF3.5, CF3.10, CF4.5, CF5.6 |

### CF-S4 — ownCloud 11 Files APIs

| | |
| - | - |
| Repo | `synaplan-owncloud-online` on the E-5 stack (or the host install until E-5 is green — say which) |
| Reads | `06` §1 row S4; `04` §A.4; `03` §5; `07` §4 |
| Questions | (1) FileList plugin to decorate rows? (2) Detail tab API? (3) New-file menu plugin? (4) File hooks / dispatcher events for create, write, rename, delete, restore? (5) Notifications app available on the hoster's 11.0.10? (6) Background jobs and cron-mode detection? (7) Request time budget for inline pickups? (8) Private link format? (9) Does the local checkout behave like the hoster's 11.0.10 — ask the hoster for a test tenant and record the answer (10) Watch index at the cap: an app table of 5 000 targets — first sync over 10 pages, incremental sync, matching cost per file event (`04` §B.8)? |
| Blocks | CF1.2, CF3.4, CF3.9, CF4.4, CF5.5 |

### CF-S5 — Nextcloud Files APIs at scale (30–34)

| | |
| - | - |
| Repo | `synaplan-nextcloud` on both matrix images |
| Reads | `06` §1 row S5; `04` §A.3, §A.4, §B.3 |
| Questions | (1) Inline row rendering with one batched call on the 5 000-file `many/` folder (virtual scroll) — frame drops? (2) Sidebar tab API across 30–34 — one code path or two? (3) List header for the current folder? (4) New-file menu entry? (5) Node events for group folders, external storage, trash and restore? (6) Notifications API? (7) Private link `/f/<fileid>`? (8) Inline pickup budget inside a request; background-job latency with the `cron` container (9) Watch index at the cap: 5 000 targets synced over 10 pages and matched per node event — sync time, table size, matching cost on both images (`04` §B.8)? |
| Blocks | CF3.3, CF3.8, CF4.3, CF5.4 |

---

## 3. CF0 — Fix and align

Wave contract: `00` §2 (D1–D3), `01` §4, `06` §2. Tests: `09` §1.

### CF0.1 — Scope map for the paths the cloud apps call (D1)

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `fix(security)` |
| Branch | `fix/cf0-1-platform-key-scope-map` |
| Depends on | — |
| Reads | `02` §4.1; `01` §2 (the path list per app); `backend/src/Security/ApiKeyScope.php`, `ApiKeyScopeSubscriber.php`, `backend/tests/Unit/Security/ApiKeyScopeTest.php` |
| Touches | the two security files; `backend/tests/Unit/Security/ApiKeyScopeTest.php`; new `backend/tests/Fixtures/cloud_apps_paths.json` |

**Build**

1. Add exact method + path branches: `chat` → `POST /api/v1/summary/generate`, `POST /v1/chat/completions`, `GET /v1/models`, `POST /api/v1/media/generate`, the media download path (`FileServeController` route the apps call); `memories` → `GET /api/v1/config/memory-service/check`. Branch before the `/v1/*` prefix rule so a platform key never gains `/v1/*` as a whole (C3).
2. `cloud_apps_paths.json`: every path each app calls (from `01` §2), with method, the scope that must allow it, and a minimal valid body for the L2 script.
3. Data-provider test: for each fixture row, allow with the app's scope set (`chat files rag`) and deny for a key without that scope; deny `POST /v1/messages`, `POST /v1/images/generations`, `GET /api/v1/admin/users` for the app scope set.

**Tests** — `ApiKeyScopeTest` (extended with the fixture provider).

**Acceptance** — `09` §1 `cf0-scopes.sh` PASS; L3 rows 2–3 (Nextcloud link and provision modes: summarize, translate, chat, image without `insufficient_scope`).

**Don't** — widen a prefix; touch `desktop:*` scopes; add paths not in the fixture.

### CF0.2 — `owncloud` as a file source (D2)

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only + ota-candidate · `fix(files)` |
| Branch | `fix/cf0-2-owncloud-file-source` |
| Depends on | — |
| Reads | `00` §3 row 26; `01` §4 D2; `backend/src/Entity/File.php` (`SOURCES`, `INCOMING_SOURCES`), `FileController` upload whitelist, `FileRepository::findByUserSourceIds`, `frontend/src/components/files/FileSourceBadge.vue` |
| Touches | `File.php`, `FileController.php`, one migration, `FileSourceBadge.vue` and the Library source filter, `frontend/src/i18n/locales/*/files.json` (5), tests |

**Build**

1. `owncloud` in `File::SOURCES` and `INCOMING_SOURCES` exactly like `nextcloud`.
2. Source label "ownCloud.online" (badge + filter) in five locales — `de` "ownCloud.online" (product name, unchanged in all locales).
3. Migration (Galera-safe, raw SQL): `UPDATE BFILES SET BSOURCE='owncloud' WHERE BSOURCE='web_upload' AND BSOURCEID IS NOT NULL AND BOWNERID IN (SELECT BUSERID FROM BEXTERNALIDENTITIES WHERE BCLIENT='owncloud')` — the PR text carries the dry-run `SELECT COUNT(*)` on the dev DB and the exact column names verified against `ExternalIdentity.php`.

**Tests** — `FileControllerTest`: upload with `source=owncloud` stored and listed; `check-stale` finds it by `source_id`. Locale parity.

**Acceptance** — `cf0-sources.sh` first block; L3 row 4 (ownCloud.online knowledge dialog shows the real state).

**Don't** — rename `web_upload` rows that have no `BSOURCEID`.

### CF0.3 — Upload option `intake=direct` (D3)

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `fix(files)` |
| Branch | `fix/cf0-3-intake-direct` |
| Depends on | — |
| Reads | `01` §4 D3; C10; `FileController` upload (`BINCOMING`, `group_key`) |
| Touches | `FileController.php` (+ OpenAPI), `FileControllerTest`, generated schemas |

**Build** — accept `intake=direct` on `POST /api/v1/files/upload`; with a `group_key` the file is stored with `BINCOMING=0` in that knowledge folder; without `group_key` the option is ignored (400 is wrong — ignore and document); default behaviour unchanged.

**Tests** — three cases (direct + group → not incoming; direct without group → incoming; no option → incoming).

**Acceptance** — `cf0-sources.sh` second block; L3 rows 5–6.

### CF0.4 — `check-stale` returns `vector_state`

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `fix(files)` |
| Branch | `fix/cf0-4-check-stale-vector-state` |
| Reads | `FileController::checkStale`, `BVECTORSTATE` values in `File.php`; `04` §A.1 (the states the bird will need) |
| Touches | `FileController.php` (+ OpenAPI), test, generated schemas |

**Build** — additive field `vector_state` per item with the entity's value; nothing removed. **Tests** — response shape test. **Acceptance** — `cf0-sources.sh` asserts the field.

### CF0.5 — Docs truth (D10, D11)

| | |
| - | - |
| Repo · type | `synaplan-docs` (`docs/plugins.md` OpenCloud section, `docs/intro.md` bullet), `synaplan-nextcloud` (`docs/HOSTING_GUIDE.md` roadmap) · `docs(…)` |
| Branch | `docs/cf0-5-cloud-apps-truth` in each repo |
| Reads | `01` §4 D10, D11; the OpenCloud `README.md`; CF0.7 row 7 |

**Build** — rewrite the OpenCloud section to what the code does (shared identity provider, personal space, manual updates, token exchange in one sentence without jargon in the user part); Nextcloud roadmap: stale handling shipped, scopes enforced after CF0.1. **Acceptance** — `09` §1 row 7.

### CF0.6 — STATUS hygiene (D14)

| | |
| - | - |
| Repo · type | `synaplan` · `docs(planning)` |
| Branch | `docs/cf0-6-planning-status` |
| Reads | `00` §12; `../20260903_more_nextcloud/STATUS.md`; `../20260925_roadmap.md` row 5 |

**Build** — More-Nextcloud STATUS: S2 merged, S3 absorbed here except the pairing-code fallback; roadmap row 5 and "Nextcloud S3" point to this folder. Only after `00` §3 is ticked.

### CF0.7 — First walks

| | |
| - | - |
| Repo | none (walk); fixes found become their own `fix/…` PRs |
| Depends on | CF0.1–CF0.4 released; E-1…E-4 (E-5 optional) |

**Build** — execute `09` §1 L2 and L3 on the three stacks; one STATUS entry per cloud in the `09` §0.5 format. A red row blocks the sprint exit.

---

## 4. CF1 — Parity floor

Wave contract: `00` §5, `01` §5.2, `06` §3. Tests: `09` §2.

### CF1.1 — ownCloud.online: link mode, consent everywhere, MIME filters, encrypted keys

| | |
| - | - |
| Repo · class · type | `synaplan-owncloud-online` · app release · `feat(app)` |
| Branch | `feat/cf1-1-link-mode-consent-mime` |
| Depends on | CF0.1, CF0.2 released |
| Reads | `01` §4 D4–D6; `02` §2, §5.2; Nextcloud's `lib/Service/PlatformLinkService.php`, `lib/Controller/LinkController.php`, `src/components/AiConsentGate.vue`, `src/personal-settings.ts`; `05` W1, W8 |
| Touches | `lib/Service/SynaplanConfig.php` (mode enum `shared` / `provision` / `link` replacing the boolean `per_user_accounts`, with a one-time migration of the stored value), new `lib/Service/PlatformLinkService.php`, new `lib/Controller/LinkController.php`, `appinfo/routes.php`, new personal settings page (`lib/Settings/`, `src/personal-settings.ts`), admin settings (**Register this instance**, four-mode copy of W8 — organisation login shown as "not available on ownCloud.online"), the consent gate component on every AI surface (files actions, research, chat), `src/files_actions/*` MIME lists, key storage through `ICrypto`, `l10n/` (five locales, CF1.4 finishes the wording) |

**Build**

1. Mode enum + admin UI + stored-value migration (`per_user_accounts=true` → `provision`).
2. Port link mode: register instance (`POST /api/v1/platform-links/instances`), redirect flow, exchange, per-user key storage **encrypted** (`ICrypto::encrypt`, prefix `enc:`; a value without the prefix is read as plaintext once and re-saved encrypted — D4).
3. Consent gate on every AI surface, same sentence as Nextcloud (W1).
4. MIME filters: each of the four actions declares the types it supports (text/PDF/Office for summarize and translate; same plus images for chat; any for knowledge where the extractor supports it) — D6.
5. Chat launcher on every page (parity with Nextcloud).

**Tests** — PHPUnit (CF1.2 adds the suite; this brief adds at least `SynaplanConfigTest` for the mode migration and `KeyStorageTest` for encrypt / re-save). Vitest where the repo has it.

**Acceptance** — `09` §2 `cf1-consent.sh`, `cf1-keys.sh` (ownCloud), L3 rows 1–5.

**Don't** — invent an ownCloud organisation login (v2); keep `SynaplanClient::SOURCE='owncloud'`.

### CF1.2 — ownCloud.online: tests, CI, AGENTS.md, local stack

| | |
| - | - |
| Repo · type | `synaplan-owncloud-online` · `chore(ci)` + `test(…)` |
| Branch | `chore/cf1-2-ci-tests-agents` |
| Depends on | CF1.1; E-5 (the stack is the E-5 brief; this brief consumes it) |
| Reads | Nextcloud `tests/Unit/` class list, `.github/` of `synaplan-opencloud` (gate job shape), `synaplan/AGENTS.md` |
| Touches | `tests/Unit/*` (mirror Nextcloud's classes: client, config, consent, media, settings, link), `phpunit.xml`, `.github/workflows/ci.yml` with the final job **`All Checks Passed`**, `AGENTS.md`, `Makefile` (`test`, `ci-local`) |

**Acceptance** — CI green on the PR; `make ci-local` documented and green; `AGENTS.md` carries the house rules (English, Conventional Commits, no attribution, gate before commit, locales).

### CF1.3 — Nextcloud: CI, AGENTS.md, encrypted keys, Playwright, disabled-user listener

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` · app release · `feat(app)` (+ `chore(ci)` commits) |
| Branch | `feat/cf1-3-ci-keys-e2e` |
| Depends on | E-2 |
| Reads | `01` §4 D4; `02` §7 (disabled / enabled rows); `synaplan-opencloud/.github/`; `docs/E2E_TESTING.md` in `synaplan` for the Playwright conventions |
| Touches | `.github/workflows/ci.yml` (`All Checks Passed`), `AGENTS.md`, `lib/Service/UserAccountService.php` + `SynaplanConfig.php` (key encryption with transparent migration), new `lib/Listener/UserChangedListener.php` (enabled flag — stores the event for CF2.6's lifecycle call), `tests/e2e/` (Playwright: J-NC-1 link flow, Summarize), `Makefile` (`test-e2e`, runs against `make dev-up` on `:8081`) |

**Acceptance** — `cf1-keys.sh` (Nextcloud); L3 rows 6 and 9; CI green.

### CF1.4 — Five locales and the core's words (all three apps)

| | |
| - | - |
| Repo · class · type | three app repos · app releases · `feat(i18n)` |
| Branch | `feat/cf1-4-locales-core-words` in each |
| Depends on | CF1.1, CF1.3, CF1.5 (last in the wave so it renames finished surfaces once) |
| Reads | `00` §11 (terms and banned words); `05` §3 (copy table); `frontend/src/i18n/locales/*/files.json` in `synaplan` for the exact core strings |
| Touches | `l10n/{en,de,es,fr,tr}.json` (Nextcloud, ownCloud), `frontend/src/translations.json` or the per-locale files the OpenCloud SDK expects; every string literal in `src/` that still carries "Add to AI Knowledge" / "Add to knowledge…" → **Make searchable by AI**; a locale-parity test in each CI (Nextcloud / ownCloud: PHPUnit or a Node script; OpenCloud: Vitest) |

**Acceptance** — `cf1-locales.sh` PASS for each repo; L3 row 7.

### CF1.5 — OpenCloud: chat about a file, research chat, media, binary Save to Files (cut line)

| | |
| - | - |
| Repo · class · type | `synaplan-opencloud` · app release · `feat(app)` |
| Branch | `feat/cf1-5-chat-media-binary-save` |
| Depends on | CF0.1 released |
| Reads | `01` §4 D7; Nextcloud `lib/Http/SseProxyResponse.php`, `lib/Controller/ChatController.php`, `MediaController.php`; `backend/internal/handler/{summarize,files}.go`, `cs3reader/write.go` |
| Touches | Go: `handler/chat.go` (SSE proxy to `/v1/chat/completions` with the exchanged token), `handler/media.go`, `handler/files.go` + `cs3reader/write.go` (binary upload, size limit from `ComputeConfig`-independent constant 100 MB, replaces the 2 MiB text-only `SaveToSpace`), tests; web: `components/ChatDialog.vue`, `views/Synaplan.vue` (research chat), the `kindFolder()` mapping for all kinds |

**Acceptance** — `09` §2 row 8; Go tests for binary save (size, MIME, conflict rename); Playwright spec "chat streams" against `:9201`.

---

## 5. CF2 — One identity

Wave contract: `02` (all), `05` W1, W2, W8, `06` §4. Tests: `09` §3.

### CF2.1 — Instance columns, capabilities, OpenCloud instances, approved addresses

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only + ota-candidate · `feat(platform-links)` |
| Branch | `feat/cf2-1-instance-capabilities` |
| Depends on | — (schema ask: `00` §3 row 6 ticked) |
| Reads | `02` §8 (first two rows), §9 (`BPLATFORMINSTANCES`), §6.2; `03` §2 (reachability test); `PlatformInstance.php`, `PlatformInstanceController.php`, `AdminPlatformInstanceController.php`, `RedirectUriPolicy.php`, `SsrfGuard.php` |
| Touches | migration (`ADD COLUMN IF NOT EXISTS` × 6), entity, `PlatformInstanceService`, `GET`/`PATCH instances/self` (+ OpenAPI), admin list + approval dialog (`frontend/src/components/…admin platform instances` — find the existing view by `AdminPlatformInstanceController` routes), five locales |

**Build**

1. Columns `BDELIVERYURL`, `BOIDCCLIENTID`, `BCAPABILITIES`, `BAPPVERSION`, `BAPPROVEDADDRS`, `BSERVERID`.
2. `GET instances/self` adds `capabilities` (this release: `[]` until CF2.2 adds `sso_bind`; the list grows per brief). `PATCH instances/self {delivery_url, oidc_client_id, app_version, server_id}` (`server_id` = the cloud's own `instanceid`, stored for adoption — `02` §3.1): `delivery_url` must be https (or local-dev http per `WebDavClient::allowsInsecureLocal()`), on the instance's registered host, and pass `SsrfGuard` unless the instance has an approved address; store the reachability result ("Synaplan can reach this cloud: yes / no") from a `HEAD` at save time.
3. Admin → People → Platform instances: `client=opencloud` listed; approval dialog with the allowlist sentence (`05` §3) writing `BAPPROVEDADDRS`.

**Tests** — migration review (C11); `PlatformInstanceServiceTest` (URL rules, SSRF, approved address); controller test for `PATCH`; OpenAPI → schemas → `vue-tsc`.

**Acceptance** — `cf2-identity.sh` first block (register, PATCH, capabilities); L3 row 8.

### CF2.2 — Canonical identity rows, SSO status, bind, bearer attribution

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `feat(platform-links)` |
| Branch | `feat/cf2-2-identity-rows-sso-bind` |
| Depends on | CF2.1 |
| Reads | `02` §3, §3.1, §5.1, §8 (`sso/status`, `sso/bind`, `identities/adopt`, admin users), §9 (`BEXTERNALIDENTITIES` columns), §10; `01` §4 D15; `OidcBearerAuthenticator.php`, `OidcUserService::oidcSource()`, `ExternalIdentity.php`, `ExternalIdentityRepository::upsert()`, `AdminUserProvisioningService.php`, `AdminUserProvisioningController.php`, `PlatformLinkExchangeService.php` |
| Touches | migration (`BMODE`, `BSTATUS`, `BREVOKEDAT`, `BREADSCOPE` — all four now, so later briefs add no migration to this table — plus `BMODE` on existing rows), entity, `AdminUserProvisioningService` + `AdminUserProvisioningController` (instance credential → canonical row, key bound), new `PlatformSsoService`, routes in `PlatformLinkController` (`sso/*`, `identities/adopt`; + OpenAPI), authenticator (attribution), audit rows |

**Build**

1. Canonical rows (`02` §3.1): provisioning and key minting with the instance credential write `(client, instance, uid)`, `BMODE = provision`, and bind the minted key; without the credential both behave as today. `POST identities/adopt` (key + instance credential) rewrites the key owner's legacy row `(client, '', '<server id>:<uid>')` when the server id equals the instance's `BSERVERID`, binds that key, and answers 200 again on a replay. The migration sets `BMODE` on existing rows (`link`, `provision`, `NULL` for `oidc:`). Provisioning without the credential never writes a second row for an adopted person.
2. `GET sso/status` (bearer + instance credential) → `{bound, mode, status}`.
3. `POST sso/bind`: requires a live bearer whose `azp` equals the instance's `BOIDCCLIENTID`, writes the identity row (`mode=sso`, `status=active`), audit `platform_link.bound`.
4. Attribution: every bearer call whose `azp` maps to an instance updates `BLASTSEEN`; a bearer call for a revoked or paused identity answers `403 platform_disconnected` with the sentence of `02` §6.3.
5. `capabilities` += `sso_bind`, `identity_adopt`.

**Tests** — handshake-style tests: wrong `azp`, unknown instance, revoked identity, replay of bind (idempotent 200), paused → 403. Authenticator unit test. Canonical rows: provisioning with and without the instance credential; adoption with the person's key (canonical row, key bound), with someone else's key (403), with a wrong server id (403), without the instance credential (401), replayed (200, still one row); provisioning after adoption writes no second row.

**Acceptance** — `cf2-identity.sh` bind and adoption blocks; L3 row 1.

### CF2.3 — Background key and `cloud:watch`

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `feat(platform-links)` |
| Branch | `feat/cf2-3-background-key-cloud-watch` |
| Depends on | CF2.2 |
| Reads | `02` §4, §4.1, §4.2, §8 (`sso/background-key`); `ApiKeyScope.php`, `ApiKeyController.php` |
| Touches | `ApiKeyScope.php` (+ `cloud:watch`, map for `/api/v1/cloud/watches*` → `cloud:watch`, `/api/v1/cloud/status` + `/api/v1/cloud/files/*` → `files`), `PlatformLinkController` route, key labelling ("<App>: <host> (<user>) — while you are away", linked-platform badge), tests |

**Tests** — `ApiKeyScopeTest` (new scope, exact branches, `/api/v1/cloud/watches` denied for `files`); background-key mint test (scopes exactly `files rag cloud:watch`, bound to the identity, plaintext returned once).

**Acceptance** — `cf2-identity.sh` background-key block.

### CF2.4 — Lifecycle endpoint

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `feat(platform-links)` |
| Branch | `feat/cf2-4-lifecycle` |
| Depends on | CF2.3 |
| Reads | `02` §7 (incl. "Deleting a person in one cloud"), §8 (`lifecycle`, admin delete); `01` §4 D16; C18; `AdminController::deleteUser()`, `AdminUserProvisioningService::PROVIDER_ID` |
| Touches | `PlatformLinkController` (+ OpenAPI), `PlatformSsoService` or a new `PlatformLifecycleService`, new `Service/PlatformLink/ProvisionedAccountPolicy.php`, `AdminController.php` (API-key path of the delete, + OpenAPI `409`), tests; `capabilities` += `lifecycle` |

**Build** — `POST lifecycle {external_id, event}`: `deleted` → this identity revoked and the keys bound to it revoked; the account is deleted only when `ProvisionedAccountPolicy` finds all four conditions of `02` §7, otherwise it stays and the audit row `platform_link.account_kept` names the reason; `disabled` → identity `paused` with reason; `enabled` → `active`. The generic admin delete (`AdminController::deleteUser()`) asks the same policy when an API key calls it and answers `409 account_in_use` for an account the policy keeps; a session admin in the admin screen is unchanged. Watch effects arrive in CF5.1 through a hook this brief leaves (`LifecycleEffect` interface, no-op implementation). Audit rows.

**Tests** — three events × (sso identity, linked identity, provisioned account); idempotency. Policy: a sole provisioned account is deleted; failing any one condition keeps it (provisioned for another identity, a second active identity, a password, the admin role) with only the identity revoked; a legacy provisioned account whose minted key is not bound to its row is still deleted; the admin delete with an API key → 409 for a kept account and 200 for a sole one; a session admin delete is unchanged.

**Acceptance** — `cf2-identity.sh` lifecycle and deletion blocks; L3 rows 7 and 10.

### CF2.5 — Apps cards for three clouds

| | |
| - | - |
| Repo · class · type | `synaplan` · ota-candidate + backend-only · `feat(apps)` |
| Branch | `feat/cf2-5-cloud-apps-cards` |
| Depends on | CF2.4 |
| Reads | `02` §6.1, §6.3; `05` W2, §3; `01` §4 D9; `frontend/src/apps/{catalog,status}.ts`, `components/apps/AppCard.vue`, `panels/`, `MePlatformLinkController.php` |
| Touches | `catalog.ts` (`opencloud` card), `status.ts` (mode-aware status from identity rows), the Nextcloud / ownCloud / OpenCloud panels, `GET /api/v1/me/platform-links` (+ `mode`, `status`, `last_seen`, counts), new `POST …/{id}/turn-off`, `…/resume`, `…/disconnect` with consequence sentences, five locales (`apps.json` or the namespace that owns the siblings — check `NAMESPACE_KEYS`), `.github/mobile-impact-policy.json` for new paths |

**Acceptance** — `cf2-apps-card.sh`; L3 rows 1–2 and 6; D7 (dark, V2, 320 px).

### CF2.6 — Nextcloud: organisation login (after CF-S2 = yes), identity rows, lifecycle

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` · app release · `feat(app)` |
| Branch | `feat/cf2-6-organisation-login` |
| Depends on | CF2.5 released; CF1.3; CF-S2 yes for the organisation-login part only — identity rows and lifecycle ship whatever the spike answers |
| Reads | `02` §3.1, §5.1, §4.2, §7; `01` §4 D15, D16; `05` W1, W8; the CF-S2 STATUS entry |
| Touches | `lib/Service/SynaplanClient.php` (bearer path), new `lib/Service/OrganisationLoginService.php` (token from `user_oidc`, cache, refresh), `lib/Controller/ConsentController.php` (bind on consent), `lib/Settings/Admin*` + `src/settings.ts` (four-mode screen W8, **Check organisation login**, registration also in provision mode — decision 6), Connect card fallback for users without an OIDC session, `lib/Service/UserAccountService.php` (provisioning and key minting with the instance credential; `deleteRemoteAccount()` replaced by `lifecycle(deleted)` — D16), new `lib/BackgroundJob/AdoptIdentitiesJob.php` (one-time, once registered and `capabilities` has `identity_adopt`: per user with a stored key → `identities/adopt` — D15), `PATCH instances/self` with `server_id` on admin save, `lib/Listener/UserDeletedListener.php` + `UserChangedListener.php` → `lifecycle` calls, `appinfo/info.xml` (`user_oidc` optional dependency, version floor from the spike), `l10n/` |

**Acceptance** — after the upgrade on the dev stack, the adoption job leaves no legacy row for this cloud's provisioned users (`db_query` on `BEXTERNALIDENTITIES`) and a second run changes nothing; L3 rows 3–4, 6–7 and 10 on Nextcloud; Playwright spec "organisation login consent" with Keycloak on the dev stack (`NC_OIDC=1`).

### CF2.7 — OpenCloud: register, consent + bind, lifecycle, account extension (Connect mode = cut line)

| | |
| - | - |
| Repo · class · type | `synaplan-opencloud` · app release · `feat(app)` |
| Branch | `feat/cf2-7-register-consent-bind` |
| Depends on | CF2.5 released; CF-S1, CF-S3 answers |
| Touches | Go: `handler/me.go` (consent state, `sso/status` + `sso/bind` calls with the exchanged token and the instance credential), new `handler/instance.go` (registration with an admin key or pending approval, `PATCH instances/self` with delivery URL and `oidc_client_id`), `handler/lifecycle.go` (user-deleted events → `lifecycle`), config for the instance credential; web: consent card (W1) before the first action, account extension "Synaplan" (connection state, turn off) per CF-S1; Connect-your-account mode only if CF-S3 found an encrypted store — otherwise moved to CF6.6 in STATUS |

**Acceptance** — L3 rows 1–2 and 6 on OpenCloud; Playwright "consent then summarize"; Go tests for registration and bind.

### CF2.8 — ownCloud.online lifecycle and admin copy

| | |
| - | - |
| Repo · class · type | `synaplan-owncloud-online` · app release · `feat(app)` |
| Branch | `feat/cf2-8-lifecycle-admin-copy` |
| Depends on | CF2.5 released; CF1.1 |
| Touches | user hooks → `lifecycle` calls (`preDelete` → `lifecycle(deleted)` instead of `deleteRemoteAccount()` — D16; disable / enable); provisioning and key minting with the instance credential, the one-time adoption job and `PATCH instances/self` with `server_id` (D15, as in CF2.6); admin screen copy per W8 with "not available on ownCloud.online" for organisation login; `l10n/` |

**Acceptance** — the adoption check of CF2.6 on the E-5 stack; L3 rows 5–7 and 10 on ownCloud.online.

### CF2.9 — Files remember their cloud (D17)

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `fix(files)` |
| Branch | `fix/cf2-9-file-source-instance` |
| Depends on | CF2.2 (canonical identity rows, bearer attribution); schema ask `00` §3 row 40 ticked |
| Reads | `01` §4 D17; `04` §A.3, §A.5, §E.2 (`BFILES.BSOURCEINSTANCE`); C19; `File.php` (indexes), `FileRepository::findForOverwrite()` / `findByUserSourceIds()`, `FileUploadService.php` (overwrite path), `FileController::checkStale`, `ExternalIdentityRepository::findOneByApiKeyId()` |
| Touches | migration (`ADD COLUMN IF NOT EXISTS BSOURCEINSTANCE`, index, backfill), `File.php`, `FileRepository.php`, `FileUploadService.php`, `FileController.php`, new `Service/PlatformLink/SourceInstanceResolver.php`, tests |

**Build**

1. `BFILES.BSOURCEINSTANCE VARCHAR(64) NULL` and the index (`BSOURCE`, `BSOURCEINSTANCE`, `BSOURCEID`) for instance-level source events; per-user lookups keep `idx_file_user_source_sid`.
2. `SourceInstanceResolver`: per-user key → its identity row (`BAPIKEYID`) → instance; bearer token → `azp` → instance (CF2.2). Nothing else — no request field names the instance. No identity (shared key, personal key) → `null`.
3. Upload stores it; `findForOverwrite()` and `findByUserSourceIds()` (so `check-stale` too) match it: a lookup with an instance never matches another instance's rows, and a lookup without an instance matches only rows without one.
4. Rows written before CF2.9 carry no instance. A lookup for instance X matches such a row only when its owner has no other identity row for that client, in any shape, and then stores X on it — so people whose row becomes canonical later (adoption, a first bind) keep their files. The migration does the same up front for every such owner (raw SQL, Galera-safe); the PR text carries the dry-run counts (filled, left empty). Rows of owners with two identities for one client stay unscoped and never match a scoped lookup.

**Tests** — two instances of the same client sharing remote id `5678` for one user: upload, overwrite and `check-stale` never cross; an upload without an identity keeps today's behaviour and matches neither; a row without an instance is matched and scoped for an owner with one identity of that client, and never for an owner with two.

**Acceptance** — `cf2-file-instance.sh`.

**Don't** — read the instance from a request field; touch rows of other sources.

---

## 6. CF3 — Files both ways

Wave contract: `03` (all), `07` §3–§5, §8–§11, `05` W3, W4, W9, W11, `06` §5. Tests: `09` §4.

### CF3.1 — Copy protocol, ledger, `platform` destination

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `feat(files)` |
| Branch | `feat/cf3-1-platform-destination` |
| Depends on | CF2.4 (schema ask: `00` §3 row 11 ticked) |
| Reads | `03` §2, §3, §4, §6, §8; C4, C8, C20; `Destination/{DestinationProvider,DestinationResult,DestinationRegistry,WebDavDestinationProvider,FileSendService,RequestedFolderDelivery}.php`, `FileSendController.php`, `RedisService::getAndDelete()`, `LinkCodeService.php` (ticket pattern), `SavedTaskTickService.php` (sweep pattern), `Execution/Runner/SaveToFolderRunner.php`, `Service/Federation/{FederationSigner,FederationIdentityStore}.php` and `EncryptionService.php` (signing and key-storage pattern) |
| Touches | migration `BCLOUDDELIVERIES` (columns of `03` §4), new `Entity/CloudDelivery.php` + repository, new `Destination/PlatformDestinationProvider.php`, `DestinationResult::pending()`, new `Service/PlatformLink/NotifyKeyStore.php` (Ed25519 pair from `FederationSigner::generate()`, encrypted in `BCONFIG` group `PLATFORM_LINKS` like `FederationIdentityStore`, created on first use) + console command `app:platform-links:rotate-notify-key`, `GET instances/self` += `notify_keys`, new `Service/PlatformLink/DeliveryNotifier.php` (POST the signed `{type, id, instance_id, ts, kid, sig}` to the delivery URL, 30 s budget, host match, https / local-dev rule, `SsrfGuard` unless approved), claim + result routes in `PlatformLinkController` (+ OpenAPI) with a per-instance failed-claim counter (`429` above 120 a minute), sweep in the scheduler's `tasks` lane (`queued` > 10 min → `unconfirmed`), `SaveToFolderRunner` + `RequestedFolderDelivery` accepting a `platform` target incl. `next_to`, test-only env `CLOUD_DELIVERY_TTL_SEC` and `CLOUD_DELIVERY_UNCONFIRMED_SEC` (§0.8), `capabilities` += `deliveries` |

**Build order** — ledger + entity → notify key, signing and `notify_keys` → provider returning `pending()` when the notify budget is exceeded → notifier → claim (`GETDEL`, instance-bound, returns platform uid, name, mime, size, target, on_conflict, one-time download URL) → result (idempotent) → sweep → `save_to_folder` integration.

**Tests** — C4 suite (replay 409/410, expiry, wrong instance, wrong user), signing (a body verifies with the published key and fails with any other; rotation lists both keys for 24 hours and signs with the new one from the next hour), failed-claim limit, notifier (budget, host rule, SSRF, approved address), provider (`success` / `pending` / `failure` with `DestinationFailureCode`), sweep, `SaveToFolderRunner` platform target.

**Acceptance** — `cf3-copy.sh` and `cf3-ticket.sh` with a stub inbound endpoint (the script starts a tiny local HTTP responder on the Docker network that verifies each notification with the published key) before any app brief exists.

**Don't** — authenticate the notification with the instance secret (Synaplan keeps only its hash); put anything but `{type, id, instance_id, ts, kid, sig}` in it.

### CF3.2 — Copy-to-cloud menu, toast, Sent to

| | |
| - | - |
| Repo · class · type | `synaplan` · ota-candidate · `feat(files)` |
| Branch | `feat/cf3-2-copy-to-cloud-ui` |
| Depends on | CF3.1 |
| Reads | `03` §4 (UI rows), §6, §7; `05` W3, §3; `FilePushMenu.vue`, `FileRowActions.vue`, the file detail view in `FilesView.vue` |
| Touches | `FilePushMenu.vue` (linked clouds by app name and host with the landing-folder line, above WebDAV), toast with **Open in …**, "Sent to" block on the file detail (ledger rows: where, when, state), `files.json` × 5 (`en` "Send to cloud" → "Copy to cloud"), generated schemas, mobile policy |

**Acceptance** — L3 rows 1–3; D7; `make test-e2e` plus a new `@ci` spec for the menu.

### CF3.3 — Nextcloud: inbound, claim and write, From Synaplan, Save to Files kinds

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` · app release · `feat(app)` |
| Branch | `feat/cf3-3-inbound-from-synaplan` |
| Depends on | CF3.2 released; CF-S5 (private link, notifications, new-file menu) |
| Reads | `03` §2, §3, §5 (Nextcloud column), §6, §8; C20; `05` W4; `lib/Controller/MediaController.php` (`KIND_FOLDERS`, `IRootFolder::getUserFolder`) |
| Touches | new `lib/Controller/InboundController.php` (`#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit]`, route `api/v1/inbound`; verifies before it dispatches on `type`), new `lib/Service/NotificationVerifier.php` (signature with the `notify_keys` cached from `instances/self` — refreshed hourly, on admin save and at start, never on an unknown `kid`; own `instance_id`; `ts` ± 5 min; seen ids for 10 minutes in the distributed cache), new `lib/Service/DeliveryService.php` (claim, download, resolve user, target, conflict rule, write, result; own-write memory 10 min), registration of the delivery URL through `PATCH instances/self` on admin save, notification "Synaplan copied … to …" with link, personal setting "Save files from Synaplan to" (file picker), `src/files-init.ts` new-file menu entry **New → From Synaplan…** + picker component (`GET /api/v1/files`, Generated first), Save to Files for every generated kind in the chat, `l10n/` |

**Tests** — `NotificationVerifierTest` and `InboundControllerTest`: forged, replayed, stale and other-instance notifications → `401` and zero outbound calls (HTTP client mock); an unknown `kid` → `401` without a key refresh.

**Acceptance** — `CLOUD=nextcloud cf3-copy.sh` and `cf3-inbound.sh`; L3 rows 1–6 on Nextcloud; Playwright "copy lands in Synaplan/Images".

### CF3.4 — ownCloud.online: same (CF-S4)

Branch `feat/cf3-4-inbound-from-synaplan`; depends on CF3.2 released, CF1.1, CF-S4. Same Build list and tests as CF3.3 with ownCloud's APIs (file hooks, notifications app per the spike; the per-address limit as a counter in the app's cache). Acceptance: `CLOUD=owncloud cf3-copy.sh` and `cf3-inbound.sh`; L3 rows 1–6 by hand.

### CF3.5 — OpenCloud: inbound behind the proxy, impersonated write, binary save, From Synaplan

Branch `feat/cf3-5-inbound-from-synaplan`; depends on CF3.2 released, CF1.5, CF-S1, CF-S3. Go: `handler/inbound.go` (unprotected proxy route; the checks of CF3.3 with Go tests, keys from `instances/self`), `service/delivery.go` (claim, download, write as the user through the service account), notification; web: create-menu extension "From Synaplan…" + picker. Acceptance: `CLOUD=opencloud cf3-copy.sh` and `cf3-inbound.sh`; L3 rows 1–4 and 6; Playwright spec.

### CF3.6 — Pickup protocol, `folder_fetch`, read-scope mirror, WebDAV `list()` / `get()`

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `feat(tasks)` |
| Branch | `feat/cf3-6-folder-fetch-pickup` |
| Depends on | CF3.1 (shares the notifier); schema ask `00` §3 row 29 ticked |
| Reads | `07` §3, §4, §5, §8, §9, §10, §11; C15, C16; `Capability.php`, `Execution/Runner/{UrlFetchRunner,McpFetchRunner,CodeRunRunner}.php`, `SavedTaskRunner.php`, `Message::addFile()`, `WebDavClient.php`, `ExternalIdentity.php` (`BREADSCOPE` exists since CF2.2) |
| Touches | migration `BCLOUDPICKUPS`; `Capability::FolderFetch` (read class); new `FolderFetchRunner` (channels: linked clouds with readable folders, WebDAV connections); new `Service/PlatformLink/PickupService.php` (ticket 10 min in Redis, claim `GETDEL`, listing → ids to upload, files, result, partial, limits 50 / 200 MB / 100 MB, 20 per person per hour); `PUT platform-links/read-scope` (writes `BREADSCOPE`); pre-check against the mirror before a ticket; `WebDavClient::list()` / `get()`; attachments on the run message as working copies (hidden, 7-day retention); sentences of `07` §10 as failure codes; `capabilities` += `pickups`, `read_scope`; OpenAPI, schemas |

**Tests** — C16 suite; runner tests (list vs fetch; attached files; fail-closed when no file could be read — C15; partial result sentence; scope refusal before any ticket); limits; WebDAV list/get against a local WebDAV fixture; `StepInputResolver` sees the files of the step.

**Acceptance** — `cf3-pickup.sh` with a stub app (same responder as CF3.1 extended for pickups); `cf3-webdav.sh`.

**Don't** — give the runner a path input; let the instance choose the person.

### CF3.7 — Run card "Read n files", refusal sentence, read scope on the Apps card

| | |
| - | - |
| Repo · class · type | `synaplan` · ota-candidate · `feat(tasks)` |
| Branch | `feat/cf3-7-read-files-card` |
| Depends on | CF3.6 |
| Reads | `05` W9, §3; `07` §10; the chat run card components and the task run detail view (find by `SavedTaskRun` usage) |
| Touches | run card component (progress "Reading files from … n of m", final sentence, file chips, **Open in …**), task run detail, Apps card line "Synaplan may read: …", locales, generated schemas, mobile policy |

**Acceptance** — L3 rows 7–10 (Synaplan side); D7.

### CF3.8 — Nextcloud pickup handler and read setting

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` · app release · `feat(app)` |
| Branch | `feat/cf3-8-pickup-read-scope` |
| Depends on | CF3.7 released; CF3.3; CF-S5 (request budget, job latency) |
| Reads | `07` §4, §5, §8; `05` W11; `03` §5 (own-write) |
| Touches | `InboundController` (`type=pickup`), new `lib/Service/PickupService.php` (claim, resolve selector in the user's view, scope check, listing, uploads — inline 25 s then a `QueuedJob`, result), read setting in personal settings (three choices, folder list, W11), files action **Let Synaplan read this folder**, admin cap, `PUT read-scope` reporting on every change, `l10n/` |

**Acceptance** — `CLOUD=nextcloud cf3-pickup.sh`; L3 rows 7–9 on Nextcloud.

### CF3.9 — ownCloud.online pickup handler and read setting (CF-S4)

Branch `feat/cf3-9-pickup-read-scope`; depends on CF3.7 released, CF3.4, CF-S4. Same Build list with ownCloud's background jobs. Acceptance: `CLOUD=owncloud cf3-pickup.sh`; L3 rows 7–9 by hand.

### CF3.10 — OpenCloud pickup handler and read setting (CF-S1, CF-S3)

Branch `feat/cf3-10-pickup-read-scope`; depends on CF3.7 released, CF3.5. Go: pickup through the service account; web: read setting in the account extension, folder action. Acceptance: `CLOUD=opencloud cf3-pickup.sh`; L3 rows 7–9.

---

## 7. CF4 — Searchable state

Wave contract: `04` §A, §E.1 (status, files, sources/events), `05` W5, `06` §6. Tests: `09` §5.

### CF4.1 — Status call, file detail, source events, FeatureModule `cloud_files`

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `feat(files)` |
| Branch | `feat/cf4-1-cloud-status-source-events` |
| Depends on | CF0.4; CF2.2 (identity rows); CF2.9 (files carry their instance) |
| Reads | `04` §A.1, §A.3, §A.5, §A.6, §E.1, §E.2 (`BFILES.BSOURCEDELETEDAT`); `00` §3 row 22; `Module/Federation/FederationModule.php`, `ModuleRegistry.php`, `backend/.env.minimal`, `docker-compose.minimal.yml`; `FileRepository::findByUserSourceIds`, the ephemeral reaper |
| Touches | new `Module/CloudFiles/CloudFilesModule.php` (decisive env `CLOUD_FILES_ENABLED`, default off; drives runtime config block `cloud_files`, gate 404, `capabilities` `cloud_status`), `.env.minimal`, `docker-compose.minimal.yml`; migration `BSOURCEDELETEDAT`; new `Controller/CloudFileController.php` (`POST /api/v1/cloud/status` — batch ≤ 500 ids, states per §A.1, cache ≤ 60 s keyed by user + instance + ids hash, header `X-Synaplan-Cache: hit\|miss`; `GET /api/v1/cloud/files/{id}`), `PlatformLinkController` `POST sources/events` (matching (instance, remote id) — C19; changed → `changed` + re-vectorize when "keep up to date"; moved → path update; deleted → `BSOURCEDELETEDAT`, hidden, vectors removed; restored → cleared, re-vectorized); reaper: delete after 7 days; dev command `app:cloud-files:reap --now=<date>` for the test; OpenAPI, schemas |

**Tests** — state matrix, cache, batch limit, each event type, reaper, gate (404 when off, no runtime block, capability absent).

**Acceptance** — `cf4-status.sh`, `cf4-status-perf.sh` (p95 < 1.5 s on 500 ids), `cf4-flag-off.sh`.

### CF4.2 — Glyph kit and runtime-config override

| | |
| - | - |
| Repo · class · type | `synaplan` + design · ota-candidate · `feat(ui)` |
| Branch | `feat/cf4-2-glyph-kit` |
| Depends on | CF4.1 |
| Reads | `04` §A.2; `00` §3 row 13; `docs/FRONTEND_CONVENTIONS.md` § Iconography |
| Touches | SVG set (bird; bird + clock / arrows / alert badges; bird with eyes; paused variant) as a package the apps copy (`frontend/src/assets/cloud-glyphs/` + a `README` with the usage rules and WCAG numbers), `FileVectorPill.vue` using the same glyphs in the Library, runtime-config field `cloud_files.glyphs` (override URLs for white-label), locales for tooltips / `aria-label`, mobile policy |

**Acceptance** — contrast ≥ 3:1 measured on light and dark (numbers in the PR); the Library pill matches the apps' glyph; override swaps both glyphs.

### CF4.3 — Nextcloud: row glyph, list header, sidebar tab, Make-searchable dialog, source events

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` · app release · `feat(app)` |
| Branch | `feat/cf4-3-bird-sidebar` |
| Depends on | CF4.2 released; CF-S5; CF3.3 (delivery URL registered) |
| Reads | `04` §A.3 (batcher: one call per folder view, 500 ids per page, cache 60 s), §A.4, §A.6, §A.7; `05` W5 |
| Touches | `src/files-init.ts` (row glyph through the batcher, list header), new sidebar tab component (five questions, **Remove from AI**, **Open in Synaplan**), Make-searchable dialog with "Keep it up to date" (default on), node-event listeners → `QueuedJob` → `POST sources/events` (changed, moved, deleted, restored), app page list of searchable files, feature detection on `capabilities.cloud_status`, `l10n/` |

**Acceptance** — `CLOUD=nextcloud cf4-status.sh`; L3 rows 1–8 on Nextcloud; Playwright "bird appears, sidebar answers".

### CF4.4 — ownCloud.online: same (CF-S4)

Branch `feat/cf4-4-bird-sidebar`; depends on CF4.2 released, CF3.4, CF-S4 (FileList plugin, detail tab, hooks). Fallback per `06` §1: sidebar-only state. Acceptance: `CLOUD=owncloud cf4-status.sh`; L3 rows by hand.

### CF4.5 — OpenCloud: sidebar panel, action-label state, event-bus source events, upstream request

Branch `feat/cf4-5-sidebar-state`; depends on CF4.2 released, CF3.5, CF-S1, CF-S3. Web: `sidebarPanel` extension with the five questions, state in the action label, searchable-files list in `views/Synaplan.vue`; Go: event-bus consumer → `sources/events` (or reconcile-only per the spike). Open an upstream issue for a row indicator; link it in STATUS. Acceptance: `CLOUD=opencloud cf4-status.sh`; L3 rows 1–8 (row 1 via the sidebar).

---

## 8. CF5 — Watched folders and automations

Wave contract: `04` §B, §C, §E; `07` §2, §6, §7; `05` W6, W7, W10; `06` §7. Tests: `09` §6.

### CF5.1 — Watch model, ledger, `file_event` trigger, upload and reconcile, lifecycle effects

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `feat(tasks)` |
| Branch | `feat/cf5-1-watch-model` |
| Depends on | CF4.1; CF3.1 (copy ledger for the loop guard); CF2.4 (`LifecycleEffect` hook); schema ask `00` §3 rows 16–21 ticked |
| Reads | `04` §B.1–§B.9, §C.2, §E.1, §E.2; `07` §2 (payload `files` + `set`); C6–C9, C14; `UrlWatch.php` + its service (shape to copy), `SavedTask.php` (`BTRIGGERTYPE`), `SavedTaskWebhookIngress.php` (payload size + trigger payload pattern), `SavedTaskTickService.php` |
| Touches | migrations `BCLOUDWATCHES`, `BCLOUDWATCHITEMS` (children-before-parent delete order in the service); entities + repositories; new `Service/CloudWatch/{CloudWatchService,WatchItemLedger,QuietPeriod,LoopGuard,WatchLimits}.php`; `SavedTask` trigger `file_event`; person API `GET/POST/PATCH/DELETE /api/v1/cloud/watches`, `GET …/options`, `POST …/{id}/files` (multipart; `(watch, source id, version)` unique → "already handled"), `POST …/{id}/reconcile`; folder and file targets (a file watch matches only new versions of that file; deletion → `target_gone`, a restore within 7 days → active — `04` §B.1, §B.2); instance API `GET platform-links/watches` for the apps' watch index (pages of ≤ 500 with `cursor`, `since` with tombstones, `ETag` / `304`, `410` for an expired cursor — `04` §B.8); `watches_changed` notify through the CF3.1 notifier; working copies (hidden, 7-day retention) unless the watch keeps them searchable; states and reasons (`active`, `paused:<reason>`, `stopped:<reason>`, incl. `policy_off`, `disconnected`, `disabled`); limits (100 / 500 per watch per hour, 1 000 per instance, 100 MB per file) with the waiting counts; caps of 50 active watches per person and 5 000 per instance (`409` with the sentences of `04` §B.7); dev-only command `app:cloud-watches:seed-test` (`APP_ENV=dev\|test`) for the index test; sweep for `running` > 30 min; `LifecycleEffect` implementation (pause / resume / stop watches); `capabilities` += `watches`; OpenAPI, schemas |

**Tests** — ledger unique key (C6); quiet period with a fake clock; loop guard three layers (C7) incl. a translate watch writing into its own folder; file targets (only new versions of that file match; deletion and restore); limits and waiting counts; caps per person and per instance; watch index (pages without duplicates, one change → one item in `since`, a stopped watch → a tombstone, nothing changed → `304`, expired cursor → `410`); reconcile answers (process / forget); lifecycle effects (C9); sweep (C8); scope `cloud:watch` on every route; flag off → 404.

**Acceptance** — `cf5-watch.sh` (Summarize preset arrives with CF5.2; until then the script uses a manual task), `cf5-quiet.sh`, `cf5-loop.sh`, `cf5-reconcile.sh`, `cf5-limits.sh`, `cf5-index.sh`, `cf5-lifecycle.sh`, `cf5-flag-off.sh` — all with the stub app.

### CF5.2 — Presets → task templates, Keep-searchable sync, mail with links

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `feat(tasks)` |
| Branch | `feat/cf5-2-presets` |
| Depends on | CF5.1 |
| Reads | `04` §C.1, §C.3, §C.4; `07` §6; `Capability.php` (`translate`, `summarize`, `email_me`, `save_to_folder`, `document_generation`), `Graph/SavedTaskPlanFactory.php`, `Graph/StepInputResolver.php`, the mail templates of `email_me` |
| Touches | new `Service/CloudWatch/PresetFactory.php` (Translate: `translate` → `document_generation` → `save_to_folder` next-to / chosen folder with `<stem>.<lang>.<ext>`; Summarize and mail me: `summarize` (4–5 sentences) → `email_me` with **Open in …** and **See this run in Synaplan**, HTML + plain text, 20 per watch per hour; Use one of my tasks: bind an existing manual task), Keep-searchable sync (`intake=direct` into the knowledge folder, no task), per-file failure sentences of §C.4, mail template |

**Tests** — one test per preset (steps, inputs, outputs, failure sentence); mail render (sentence count, two links, plain part); 20-per-hour wait.

**Acceptance** — `cf5-watch.sh` full; MailHog shows the mail; `cf5-limits.sh`.

### CF5.7 — Files into runs: attachments, file sets, run limits

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `feat(tasks)` |
| Branch | `feat/cf5-7-files-into-runs` |
| Depends on | CF5.2 |
| Reads | `07` §1, §2, §6, §8, §11; C15; `SavedTaskRunner::run()`, `Message::addFile()`, `MessagePreProcessor`, `CodeRunRunner` (mounts message attachments), the scheduler's `tasks` lane |
| Touches | `SavedTaskRunner::run()` attaches the run's files to the run message before processing; `file_event` payload `files[]` + `set {kind, count, window_started_at}`; "Files in this run" block in the run summary (`SavedTaskSummary.php`); file sets: `new_file` (default), `together` (window from the first file, `BWINDOWSEC` default 600, min 60 for tests, `BMAXFILES` default 50 — the scheduler closes windows), `whole_folder` (`POST …/snapshots` listing → versions to upload → `…/close` → one run; at most once per window; refusal above 50 files with the sentence); run limits 50 files / 200 MB; `CodeRunRunner` mounts the run's attachments; OpenAPI, schemas |

**Tests** — fail-closed without attachments (C15); windowing with a fake clock (three files → one run; the 51st opens a second run); snapshot (unchanged versions not re-sent; refusal sentence); `CodeRunRunner` sees the files by name.

**Acceptance** — `cf5-fileset.sh`.

### CF5.8 — Try it on a file, then automate; unattended file-work consent

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only + ota-candidate · `feat(tasks)` |
| Branch | `feat/cf5-8-trial-then-automate` |
| Depends on | CF5.7 |
| Reads | `07` §7, §9, §11 (`trials`, `from-message`); C17; `05` W10, §3; "Schedule this" step pinning (`SavedTaskGraphCapture.php`), `SavedTask::BALLOWUNATTENDED`, `SavedTaskComputePauseTest.php`, `COMPUTE.WORKSPACES_ENABLED` |
| Touches | `POST /api/v1/cloud/trials` (uploaded file(s) + instruction + watched folder → a chat with the files attached; returns the chat URL); `POST /api/v1/cloud/watches/from-message/{id}` (pins the turn's steps into a watch task; body carries `file_set`, `window_sec`, `output`, `allow_unattended`); pinning validator: a file-work script that contains a test file's name is rejected with the C17 sentence (422); replay test helper that runs the pinned steps on a differently named file; serialization of runs for a watch that keeps a working folder (`workspace_busy` → wait, not fail); chat UI: **Do this for every new file in “<folder>”** under a turn whose files came from one cloud folder, with the file-set question and the file-work question (absent when Compute is off); Tasks trigger row for `file_event`; locales; mobile policy |

**Tests** — trial creates a chat with attachments; from-message pins exactly the turn's steps; `allow_unattended` set / unset; 422 on a test-file name; replay on `invoice-1003.pdf` after a trial on `invoice-0912.pdf`; Compute off → no file-work question in the API response.

**Acceptance** — `cf5-trial.sh`; L3 rows 5 and 9 (Synaplan side).

### CF5.3 — Tasks → Watched folders tab, trigger row, run detail, knowledge-folder badge

| | |
| - | - |
| Repo · class · type | `synaplan` · ota-candidate · `feat(tasks)` |
| Branch | `feat/cf5-3-watched-folders-tab` |
| Depends on | CF5.8 |
| Reads | `04` §C.5; `05` W7, §3; `SavedTasksOverview.vue` (Watched pages tab is the pattern), the Library knowledge-folder header |
| Touches | `SavedTasksOverview.vue` (+ **Watched folders** tab: rows with cloud, folder, preset, last run, counts; Pause / Resume / Stop from the row with the kind-specific consequence sentence; empty state one sentence + action), task trigger row for `file_event`, run detail with "Files in this run" and **Open in …**, knowledge-folder badge "Kept in sync with …" with **Stop syncing**, session-authenticated equivalents of the watch API under the Saved Tasks controller, locales, mobile policy, `@ci` Playwright spec |

**Acceptance** — L3 row 11; D7; `make test-e2e`.

### CF5.4 — Nextcloud: watch dialog, permission card, eyes, queue, reconciliation, admin policy

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` · app release · `feat(app)` |
| Branch | `feat/cf5-4-watches` |
| Depends on | CF5.3 released; CF4.3; CF-S5 |
| Reads | `04` §B (all), §C.1, §C.5; `02` §4.2; `05` W6; `07` §2 (file-set choice arrives in CF5.9 — leave the dialog slot) |
| Touches | files action **Watch with Synaplan…** (folders, and single files with the file variant of W6) → dialog W6 (presets, inputs from `GET …/options`, "Also the n files already here", **Start watching**, the cap sentences of `04` §B.7); permission card (`02` §4.2) → re-link with `cloud:watch` / re-mint / background key by mode; the watch index in an app table keyed by target id (`04` §B.8: synced incrementally on `watches_changed` and every 10 minutes, a full walk daily or on `410`) for the eyes and for matching node events, list header, sidebar watch section (owner, description, Pause / Stop for the owner only); node events → job list with the 60 s quiet period → `POST …/{id}/files`; hourly reconciliation job (`…/reconcile`); own-write guard; admin policy "Allow watching shared folders" and the cron-mode warning; Watched folders list on the app page; `l10n/` |

**Acceptance** — `CLOUD=nextcloud cf5-*.sh`; L3 rows 1–4, 6–8, 10, 12–14 on Nextcloud; Playwright "watch a folder, drop a file, see the run".

### CF5.5 — ownCloud.online: same (CF-S4)

Branch `feat/cf5-5-watches`; depends on CF5.3 released, CF4.4, CF-S4 (hooks, background jobs, cron detection). Acceptance: `CLOUD=owncloud cf5-*.sh`; L3 by hand.

### CF5.6 — OpenCloud: same with event bus and impersonation (CF-S3)

Branch `feat/cf5-6-watches`; depends on CF5.3 released, CF4.5, CF-S1, CF-S3. Folder context action, sidebar, app-view list; Go consumer → quiet period → uploads with the person's background key; reconcile-only if the spike found no events. Acceptance: `CLOUD=opencloud cf5-*.sh`; L3 rows.

### CF5.9 — Dialog: what each run gets, try it on a file, whole-folder snapshots (all three apps)

| | |
| - | - |
| Repo · class · type | three app repos · app releases · `feat(app)` |
| Branch | `feat/cf5-9-file-sets-trial` in each |
| Depends on | CF5.8 released; CF5.4 / CF5.5 / CF5.6 |
| Reads | `07` §2, §7; `05` W6 (file-set block), W10; `00` §11 (file-set terms, trial terms) |
| Touches | dialog block **What should each run get?** (three choices with their one-sentence consequences; window and max shown for "together"; absent for a file watch); **Try it on a file / files** (upload with the person's credential to `POST /api/v1/cloud/trials`, open the chat URL); whole-folder snapshot push (`…/snapshots` listing → upload the requested versions → `…/close`) in the job list; `l10n/` |

**Acceptance** — L3 rows 5 and 9 on all three; `cf5-fileset.sh`, `cf5-trial.sh` against the real apps.

---

## 9. CF6 — Beyond the apps

Briefs are written when an item is scheduled; each gets the same shape. Until
then `06` §8 is the list.

## 10. Environment briefs E-1 … E-8

Defined in [`10`](./10_local_environments.md) §4 with steps and acceptance;
they follow §0 of this file (type `chore(dev)`, branch `chore/env-<n>-<slug>`).
