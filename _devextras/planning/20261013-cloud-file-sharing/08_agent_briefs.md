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

1. Every [`00`](./00_master_plan.md) §3 row the brief cites says "yes" there.
   If not, stop — no code before the decisions.
2. Every brief in **Depends on** is merged to `main`. That is enough to start
   an app brief against the local stack. The app **release** waits until a
   Synaplan release answers the capability in `instances/self` (C1).
3. The brief belongs to the current phase (§0.9), and `env-check.sh` (`10` §5)
   is green for that phase's profile.
4. Read the sections in **Reads** completely. They are short on purpose. In
   P1, read [`11`](./11_nextcloud_release.md) first: milestones, the
   engineering standard and the regression guards apply to every P1 brief.
5. Run the repo gate once on a clean checkout of `main` to know the baseline.
6. Create the branch named in the brief from `main`.

### 0.2 While you work — rules R1–R14

| # | Rule |
| - | ---- |
| R1 | **Scope is the Build list.** A file not in **Touches** needs one sentence in the PR saying why. A feature not in **Build** is a suggestion in the PR text, not a change. |
| R2 | **Copy comes from the plan.** User-facing sentences are taken verbatim from `05` §3, `03` §6, `04` §A.1 / §B.2 / §C.4, `07` §10 and `02` §6.3. Where the plan gives only English, the English is binding and the agent translates de, es, fr, tr in the same commit with the term table of `00` §11; the PR lists every new key so a native speaker can review it. No banned word of `00` §11 in primary copy. |
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

### 0.3 Before you open the PR — definition of done DoD-1…DoD-11

(Named `DoD-n` so they never collide with the defects D1–D20 of `01` §4.)

| # | Done means |
| - | ---------- |
| DoD-1 | Every **Build** item is in the diff; nothing else is. |
| DoD-2 | Every **Tests** item exists and passes; the unfiltered gate is green. |
| DoD-3 | Every **Acceptance** row was executed on the named stack and its result is in the PR text (and the sprint's `09` script, if it exists, passes). A core brief from CF2.1 on is accepted against the reference client (`00` row 45); journeys are walked in the app brief that follows it. |
| DoD-4 | Copy in five locales; locale-parity test green; banned-word check green (`cf1-locales.sh` from CF1.4 on). |
| DoD-5 | OpenAPI → schemas → `vue-tsc` done for every endpoint change. |
| DoD-6 | Flag off and older-core behaviour checked (R5, R11) where the brief touches a surface; a cloud whose app does not report the feature is not offered (C21). |
| DoD-7 | Light, dark, 320 px (Synaplan) or the host's narrow width (apps) for every new surface; one line in the PR per theme. |
| DoD-8 | Mobile class recorded (core) or "app release" noted (apps). |
| DoD-9 | STATUS: the step row set to `in review` with the PR link; a dated entry for any decision or spike answer. |
| DoD-10 | The PR text follows §0.5. |
| DoD-11 | A core brief that adds or changes an app-facing path, body, code or capability updates the contract fixtures in `backend/tests/Fixtures/cloud-apps-contract/` and the reference client in the same PR (C22 applies after CF5.10). |

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
| `synaplan` backend | `backend/config/packages/security.yaml` (`openai_compat` firewall) · `backend/src/Security/ApiKeyScope.php`, `ApiKeyScopeSubscriber.php`, `OidcBearerAuthenticator.php` · `backend/src/Service/Federation/FederationSigner.php` (notify signing) · `backend/src/Repository/ExternalIdentityRepository.php` · `backend/src/Command/ReapEphemeralFilesCommand.php` · `backend/src/Entity/{File,PlatformInstance,ExternalIdentity,SavedTask,SavedTaskRun,UrlWatch}.php` · `backend/src/Controller/{FileController,FileSendController,PlatformLinkController,PlatformInstanceController,AdminPlatformInstanceController,MePlatformLinkController,SavedTaskController,ApiKeyController}.php` · `backend/src/Service/PlatformLink/*` · `backend/src/Service/Destination/*` · `backend/src/Service/SavedTask/*` (`SavedTaskRunner.php`, `SavedTaskTickService.php`, `Graph/StepInputResolver.php`) · `backend/src/Service/Multitask/Plan/Capability.php`, `backend/src/Service/Multitask/Execution/Runner/*` · `backend/src/Service/Compute/ComputeConfig.php` · `backend/src/Service/Dav/WebDavClient.php`, `backend/src/Service/Security/SsrfGuard.php` · `backend/src/Module/*` (`Federation/FederationModule.php` is the pattern to copy) · `backend/migrations/` · `backend/tests/Unit/Security/ApiKeyScopeTest.php`, `backend/tests/Unit/Service/SavedTask/SavedTaskComputePauseTest.php` · `backend/.env.minimal`, `docker-compose.minimal.yml` |
| `synaplan` frontend | `frontend/src/apps/{catalog,status}.ts`, `frontend/src/components/config/LinkedPlatformsConfiguration.vue` (renders the cloud cards; shared with the Outlook connection — C2), `frontend/src/components/apps/{AppCard,AppPanelHost}.vue`, `frontend/src/views/AppsView.vue` · `frontend/src/components/people/PlatformInstancesTab.vue` (Admin → People → Platform instances) · `frontend/src/components/multitask/{TaskCard,ComputeRunCard,TaskPlanBubble}.vue` (step cards in chat), `frontend/src/components/config/SavedTaskCard.vue` (task runs) · `frontend/src/components/files/{FilePushMenu,FileVectorPill,FileSourceBadge,FilesGrid,FileRowActions}.vue`, `frontend/src/views/FilesView.vue` · `frontend/src/components/config/SavedTasksOverview.vue` · `frontend/src/i18n/locales/{en,de,es,fr,tr}/*.json`, `frontend/src/i18n/namespaces.ts` · `frontend/src/generated/api-schemas.ts` (generated only) · `.github/mobile-impact-policy.json` |
| `synaplan` testing / docs | `_devextras/testing/cloud-apps/` (E-6; `fixture-app/` = the reference client, E-9), `_devextras/testing/platform-links/` (reuse), `backend/tests/Fixtures/cloud-apps-contract/` (contract fixtures, from CF0.1 on), `_docker/keycloak/setup.sh` (E-3), `docs/DEVELOPMENT.md`, this folder's `STATUS.md` |
| `synaplan-nextcloud` | `appinfo/{info.xml,routes.php}` · `lib/Controller/{ApiController,ChatController,ConsentController,LinkController,MediaController,SettingsController,AdminAiUsersController,PageController}.php` · `lib/Service/{SynaplanClient,SynaplanConfig,PlatformLinkService,UserAccountService,LanguageService}.php` (per-user keys live in the user preference `synaplan_user_api_key`) · `lib/Listener/UserDeletedListener.php` · `lib/Settings/{SynaplanAdmin,SynaplanPersonal,SynaplanSection}.php` · `src/{files-init.ts,files_actions/,components/,personal-settings.ts,settings.ts,chat-launcher.ts,research.ts}` · `l10n/` · `tests/Unit/` · `.github/workflows/` (new) · `docker-compose.dev.yml.example`, `docker/dev-hooks/`, `Makefile` |
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

The reference client (E-9) offers the same three switches through its `/control` endpoint, so the core briefs can run these tests before an app exists.

Production builds keep the switches; they are harmless when off and let a
hoster reproduce a report. The core needs two test-only environment values,
both read only when `APP_ENV=dev|test`: `CLOUD_DELIVERY_TTL_SEC` (ticket
lifetime, default 300) and `CLOUD_DELIVERY_UNCONFIRMED_SEC` (sweep threshold,
default 600) — introduced by CF3.1, set on `backend`, `worker` **and**
`scheduler` (the sweeps run in the scheduler).

**Development addresses.** Synaplan reaches the apps on the shared dev host
list of `03` §2 (`nextcloud`, `owncloud`, `cloud-fixture`,
`host.docker.internal`, …) over plain http, only while
`RedirectUriPolicy::isDev()` is true. Every location Synaplan hands an app is
a path the app joins to its configured Synaplan address (`03` §2).

**The reference client** (`00` row 45, E-9) lives in
`_devextras/testing/cloud-apps/fixture-app/` and runs as the container
`cloud-fixture` on the Synaplan network. Each core brief that adds an
app-facing protocol adds the app side of it there in the same PR (DoD-11):
CF2.1 registration and ping, CF2.2 bind and adoption, CF3.1 signed
notifications, claim, write and result, CF3.6 pickups, CF4.1 source events
and re-uploads, CF5.1 watch uploads, reconciliation and the watch index,
CF5.7 snapshots. Profile `keyed` behaves like Nextcloud, profile `sso` like
OpenCloud (OIDC bearer, background key, ids `storage$space!node`, quoted
etags).

### 0.9 Phases for agents (`00` row 42)

| Phase | Briefs an agent may take | Stack profile |
| ----- | ------------------------ | ------------- |
| P0 | E-1, E-2, E-3, E-4, E-6 (`10` §4) | `env-check.sh --cloud nextcloud --oidc` |
| P1 | spikes CF-S2, CF-S5 (start), CF-S1, CF-S3, CF-S4 (before CF3.1 and CF5.1 merge); E-9 (before CF2.1); every `synaplan` brief except CF2.11; every `synaplan-nextcloud` brief (CF1.3a–e, CF1.4-nc, CF2.6, CF2.10, CF3.3, CF3.8, CF4.3, CF5.4, CF5.9-nc, CF5.10); optionally CF1.5 (`00` row 34). Order and releases: `11` §2 | `env-check.sh --cloud nextcloud --oidc` |
| P2 | every `synaplan-opencloud` brief (CF1.4-oc, CF1.5, CF2.7, CF3.5, CF3.10, CF4.5, CF5.6, CF5.9-oc) and CF2.11 | `env-check.sh --cloud opencloud --oidc` |
| P3 | E-5; every `synaplan-owncloud-online` brief (CF1.1, CF1.2, CF1.4-oco, CF2.8, CF3.4, CF3.9, CF4.4, CF5.5, CF5.9-oco) | `env-check.sh --cloud owncloud --oidc` |

In P2 and P3 a core change is a separate `synaplan` PR: additive only, a
dated STATUS decision, the reference-client suite and the Nextcloud
regression set green (C22).

---

## 1. Dependency and parallelism map

```text
P0  E-1 → E-3 → E-2 → E-6                 E-4 (beside, for spikes and the compatibility check)

P1  CF-S2 ‖ CF-S5                          (start, 2 days each)
    CF0.0 → { CF0.1 ‖ CF0.2 ‖ CF0.3 ‖ CF0.4 } → CF0.7 (walks) → release
    E-9 (reference client) → CF2.1
            CF0.5 ‖ CF0.6 (docs, any time in CF0)
    CF1.3a (fast lane, 1.6.1) → CF1.3b → { CF1.3c ‖ CF1.3d ‖ CF1.3e } → CF1.4-nc → release (Nextcloud 1.7.0)
    CF2.1 → CF2.2 → CF2.3 → CF2.4 → CF2.5 ;  CF2.2 → CF2.9
           → CF2.10 (Nextcloud identity basics) → CF2.6 (only if CF-S2 = yes) → release
    CF-S1 ‖ CF-S3 ‖ CF-S4  ─── must be answered before CF3.1 and CF5.1 merge
    CF3.1 → CF3.2 → CF3.3 ;  CF3.1 → CF3.6 → CF3.7 → CF3.8      (CF3.6 starts after CF3.1 is merged)
    CF4.1 → CF4.2 ;  CF3.3 + CF4.2 → CF4.3                      (CF4.1 may run beside CF3 core)
           → release
    CF5.1 → CF5.2 → CF5.7 → CF5.8 → CF5.3 → CF5.4 → CF5.9-nc → CF5.10 (freeze) → release = P1 exit → break (row 48)

P2  CF1.5 → CF1.4-oc ;  CF2.11 ‖ CF2.7 → CF3.5 → CF3.10 ;  CF3.5 → CF4.5 → CF5.6 → CF5.9-oc → release

P3  E-5 → CF1.1 → CF1.2 → CF1.4-oco → CF2.8 → CF3.4 → CF3.9 ;  CF3.4 → CF4.4 → CF5.5 → CF5.9-oco → release
```

`‖` = may run in parallel (different repos or independent files). `→` =
merged before the next starts. "release" = a tagged Synaplan release (and the
Nextcloud app release in P1) that answers the new capabilities in
`instances/self`. Two agents never work on the same file list at the same
time; a brief that must touch a file another open brief touches waits.

---

## 2. Spike briefs (CF-S1 … CF-S5)

Common to all spikes: **timebox two working days**; branch `spike/…` with
throw-away code; deliverable = a dated STATUS entry with one line per question
(**yes / no / partly**, version tested, code pointer or test), the fallback
chosen for every "no" (from `06` §1), and the follow-up briefs whose text must
change. No spike code is merged. Stack per `10`. Timing (`06` §1): CF-S2 and
CF-S5 at the start of P1; CF-S1, CF-S3 and CF-S4 during P1, answered before
CF3.1 and CF5.1 merge. A spike answer that needs a core change becomes a
`synaplan` brief text change before the freeze.

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
| Questions | (1) Which `user_oidc` version exposes the login token or a token-exchange call to apps (events, `store_login_token`)? (2) Which client id is the `azp` of the token Nextcloud would send to Synaplan — that value becomes the instance's `oidc_client_id` (`02` §3)? (3) Refresh behaviour and expiry handling? (4) Works with Keycloak; expected to work with openDesk's Nubus (document the difference, do not test)? (5) Version floor for `appinfo/info.xml` |
| Method | Install `user_oidc` from the app store on the dev stack; log `ada` in through Keycloak; from a test controller read the token the app can get; decode it; call `GET /api/v1/auth/me` on Synaplan with it as bearer; record the result for each question |
| Blocks | CF2.6 |

### CF-S3 — OpenCloud unattended access

| | |
| - | - |
| Repo | `synaplan-opencloud` (Go service) on the E-4 stack |
| Reads | `06` §1 row S3; `02` §4; `03` §5; `07` §4; `04` §B.3 |
| Questions | (1) Service account or impersonation that lists and reads/writes a user's files without a request? (2) Event bus (NATS) consumer for upload finished, move, trash, restore, user deleted — config and delivery guarantees? (3) Unprotected proxy route for `/api/synaplan/inbound`? (4) Encrypted persistent store for per-user keys (Connect mode)? (5) Project spaces: events and writes? (6) Memory and rebuild time of the in-memory watch index at the cap — 1 000 watches over 2 pages (`04` §B.8)? |
| Method | Enable the service account and NATS exposure under `dev/docker/` (env overrides, documented); write a throw-away Go command that lists `ada`'s `Invoices` and uploads one file as `ada`; subscribe to the bus and print events while uploading in the UI; add a proxy route and call it unauthenticated |
| Blocks | CF2.7, CF3.5, CF3.10, CF4.5, CF5.6 |

### CF-S4 — ownCloud 11 Files APIs

| | |
| - | - |
| Repo | `synaplan-owncloud-online` on the host install (`:8088`, BW-Tech checkout at 11.0.11 + 39 commits) — E-5 is a P3 brief; say which version was tested |
| Reads | `06` §1 row S4; `02` §7; `04` §A.4; `03` §5; `07` §4 |
| Questions | (1) FileList plugin to decorate rows? (2) Detail tab API? (3) New-file menu plugin? (4) File hooks / dispatcher events for create, write, rename, delete, restore? (5) Notifications app available on the hoster's installation? (6) Background jobs and cron-mode detection? (7) Request time budget for inline pickups? (8) Private link format? (9) User disabled / re-enabled events for `lifecycle`? (10) Which version does the hoster run (the app README says 11.0.10; the BW-Tech repository has tags up to `v11.0.13`), and can it give us a test tenant? (11) Watch index at the cap: an app table of 1 000 targets — first sync over 2 pages, incremental sync, matching cost per file event (`04` §B.8)? |
| Blocks | CF1.2, CF2.8, CF3.4, CF3.9, CF4.4, CF5.5; the E-5 tag pin |

### CF-S5 — Nextcloud Files APIs at scale (33–35)

| | |
| - | - |
| Repo | `synaplan-nextcloud` on both matrix images |
| Reads | `06` §1 row S5; `04` §A.3, §A.4, §B.3 |
| Questions | (1) Inline row rendering with one batched call on the 5 000-file `many/` folder (virtual scroll) — frame drops? (2) Sidebar tab API across 33–35 — one code path or two? (3) List header for the current folder? (4) New-file menu entry? (5) Node events for group folders, external storage, trash and restore? (6) Notifications API? (7) Private link `/f/<fileid>`? (8) Inline pickup budget inside a request; background-job latency with the `cron` container (9) Watch index at the cap: 1 000 targets synced over 2 pages and matched per node event — sync time, table size, matching cost on both images (`04` §B.8)? |
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
| Touches | the two security files; `backend/tests/Unit/Security/ApiKeyScopeTest.php`; new `backend/tests/Fixtures/cloud-apps-contract/paths.json` and `README.md` — the contract fixture C3 asks for (if row 44 is agreed, CF5.10 grows this folder into contract v1) |

**Build**

1. Add exact method + path branches: `chat` → `POST /api/v1/summary/generate`, `POST /v1/chat/completions`, `GET /v1/models`, `POST /api/v1/media/generate`; `GET /api/v1/config/memory-service/check` allowed for `chat` **or** `memories` (provisioned keys never carry `memories` — `ApiKeyScope::provisionedPlatformScopes()`). Generated media is fetched through `/api/v1/files/uploads/…` (`StaticUploadController`), already covered by `files` — no change. Branch before the `/v1/*` prefix rule so a platform key never gains `/v1/*` as a whole (C3).
2. `paths.json`: every path each app calls today (from `01` §2) — `{method, path, scope, app: [nextcloud, owncloud, opencloud], body}` with a minimal valid body for the L2 script. The README names the source commit.
3. Data-provider test: for each fixture row, allow with the provisioned scope set (`chat files rag`) and deny for a key without that scope; deny `POST /v1/messages`, `POST /v1/images/generations`, `GET /api/v1/admin/users` for the app scope set.

**Tests** — `ApiKeyScopeTest` (extended with the fixture provider).

**Acceptance** — `09` §1 `cf0-scopes.sh` PASS. The Nextcloud walks (`09` §1 L3 rows 2–3) follow in CF0.7.

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
3. Migration (Galera-safe, raw SQL): `UPDATE BFILES SET BSOURCE='owncloud' WHERE BSOURCE='web_upload' AND BSOURCEID IS NOT NULL AND BUSERID IN (SELECT BUSERID FROM BEXTERNALIDENTITIES WHERE BSOURCE='owncloud')` — `BFILES.BUSERID` is the owner, `BEXTERNALIDENTITIES.BSOURCE` holds the client (provisioning writes the source the app sends, `owncloud`). The PR text carries the dry-run `SELECT COUNT(*)` with the same `WHERE` on the dev DB. Files uploaded in shared mode (no identity row) stay `web_upload`; the app's next **Update** re-uploads them with the right source.

**Tests** — `FileControllerTest`: upload with `source=owncloud` stored and listed; `check-stale` finds it by `source_id`. Locale parity.

**Acceptance** — `cf0-sources.sh` first block. The ownCloud.online knowledge dialog is checked in the CF0.7 compatibility walk (`09` §1 L3 row 8).

**Don't** — rename `web_upload` rows that have no `BSOURCEID`.

### CF0.3 — Cloud uploads that name a knowledge folder skip Incoming (D3)

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `fix(files)` |
| Branch | `fix/cf0-3-cloud-intake-knowledge-folder` |
| Depends on | — (independent of CF0.2; if CF0.2 merges first, `owncloud` is covered by the same rule) |
| Reads | `01` §4 D3; C10; `File::INCOMING_SOURCES`; `FileUploadService::createFileEntity()` (`setIncoming(...)`); `FileController::upload` (`group_key`) |
| Touches | `FileUploadService.php`, `File.php` (a constant `KNOWLEDGE_INTAKE_SOURCES = ['nextcloud', 'opencloud', 'owncloud']`), `FileUploadServiceTest` / `FileControllerTest`; OpenAPI description of `group_key` |

**Build** — today `setIncoming(in_array($source, INCOMING_SOURCES))` ignores the knowledge folder. New rule: for a source in `KNOWLEDGE_INTAKE_SOURCES`, the file is incoming only when `group_key` is empty or `DEFAULT` (the Nextcloud app sends `DEFAULT` when the person picked nothing); any other `group_key` stores `BINCOMING = 0` in that folder. `outlook` keeps today's behaviour. No new request field — released app versions benefit at once.

**Tests** — four cases per cloud source: named folder → not incoming; `DEFAULT` → incoming; no `group_key` → incoming; `outlook` with a named folder → incoming (unchanged).

**Acceptance** — `cf0-sources.sh` second block. Walk: CF0.7 rows 5 and 9.

### CF0.4 — `check-stale` returns `vector_state`

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `fix(files)` |
| Branch | `fix/cf0-4-check-stale-vector-state` |
| Depends on | — |
| Reads | `FileController::checkStale`, `BVECTORSTATE` values in `File.php`; `04` §A.1 (the states the bird will need) |
| Touches | `FileController.php` (+ OpenAPI), test, generated schemas |

**Build** — additive field `vector_state` per item with the entity's value; nothing removed. **Tests** — response shape test. **Acceptance** — `cf0-sources.sh` asserts the field.

### CF0.5 — Docs truth (D10, D11)

| | |
| - | - |
| Repo · type | `synaplan-docs` (`docs/plugins.md` OpenCloud section, `docs/intro.md` bullet), `synaplan-nextcloud` (`docs/HOSTING_GUIDE.md` roadmap) · `docs(…)` |
| Branch | `docs/cf0-5-cloud-apps-truth` in each repo |
| Depends on | — (documents today's code; CF0.7 row 7 checks it) |
| Reads | `01` §4 D10, D11; the OpenCloud `README.md`; CF0.7 row 7 |

**Build** — rewrite the OpenCloud section to what the code does (shared identity provider, personal space, manual updates, token exchange in one sentence without jargon in the user part); Nextcloud roadmap: stale handling shipped, scopes enforced after CF0.1. **Acceptance** — `09` §1 row 7.

### CF0.6 — STATUS hygiene (D14)

| | |
| - | - |
| Repo · type | `synaplan` · `docs(planning)` |
| Branch | `docs/cf0-6-planning-status` |
| Depends on | — (the track is agreed: `00` §3 rows 1–36) |
| Reads | `00` §12; `../20260903_more_nextcloud/STATUS.md`; `../20260925_roadmap.md` row 5 |

**Build** — More-Nextcloud STATUS: S2 merged, S3 absorbed here except the pairing-code fallback; roadmap row 5 and "Nextcloud S3" point to this folder.

### CF0.7 — First walks

| | |
| - | - |
| Repo | none (walk); fixes found become their own `fix/…` PRs |
| Depends on | CF0.1–CF0.4 merged; CF0.0 (E-1, E-2, E-3, E-6 green; E-4 for the OpenCloud check) |

**Build** — execute `09` §1 L2 and L3: Nextcloud rows on the E-2 stack; the compatibility rows on the OpenCloud E-4 stack and the ownCloud.online host install (`:8088`); one STATUS entry per cloud in the `09` §0.5 format. A red row blocks the sprint exit.

---

## 4. CF1 — Parity floor

Wave contract: `00` §5, `01` §5.2, `06` §3. Tests: `09` §2.

### CF1.1 — ownCloud.online: link mode, consent everywhere, MIME filters, encrypted keys

| | |
| - | - |
| Repo · class · type | `synaplan-owncloud-online` (P3) · app release · `feat(app)` |
| Branch | `feat/cf1-1-link-mode-consent-mime` |
| Depends on | P2 exit; E-5; CF0.1, CF0.2 released; vendor the contract v1 fixtures (CF5.10) with a checksum test in this PR |
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
| Repo · type | `synaplan-owncloud-online` (P3) · `chore(ci)` + `test(…)` |
| Branch | `chore/cf1-2-ci-tests-agents` |
| Depends on | CF1.1; E-5 (the stack is the E-5 brief; this brief consumes it); CF-S4 answered |
| Reads | Nextcloud `tests/Unit/` class list, `.github/` of `synaplan-opencloud` (gate job shape), `synaplan/AGENTS.md` |
| Touches | `tests/Unit/*` (mirror Nextcloud's classes: client, config, consent, media, settings, link), `phpunit.xml`, `.github/workflows/ci.yml` with the final job **`All Checks Passed`**, `AGENTS.md`, `Makefile` (`test`, `ci-local`) |

**Acceptance** — CI green on the PR; `make ci-local` documented and green; `AGENTS.md` carries the house rules (English, Conventional Commits, no attribution, gate before commit, locales).

### CF1.3 — Nextcloud: split into CF1.3a–e (2026-10-19)

The briefs are in [`11`](./11_nextcloud_release.md) §8: **CF1.3a** Nextcloud
33–35 (D21, fast lane 1.6.1), **CF1.3b** engineering baseline (CI gate,
contract test, PHPStan, vue-tsc, Vitest, Playwright, Renovate, `AGENTS.md` —
no behaviour change), **CF1.3c** per-user keys encrypted (D4, 1.7.0),
**CF1.3d** release workflow, **CF1.3e** honest app states (D23, D24). The
disabled / re-enabled listener moved to CF2.10, where it calls `lifecycle`.

### CF1.4 — Five locales and the core's words (one PR per app, in its phase)

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` (CF1.4-nc, P1), `synaplan-opencloud` (CF1.4-oc, P2), `synaplan-owncloud-online` (CF1.4-oco, P3) · app releases · `feat(i18n)` |
| Branch | `feat/cf1-4-locales-core-words` in each |
| Depends on | the app's other CF1 briefs, so it renames finished surfaces once: CF1.3c (Nextcloud); CF1.5 (OpenCloud); CF1.1 and CF1.2 (ownCloud.online — its CI exists only after CF1.2) |
| Reads | `00` §11 (terms and banned words); `05` §3 (copy table); `frontend/src/i18n/locales/*/files.json` in `synaplan` for the exact core strings |
| Touches | `l10n/{en,de,es,fr,tr}.json` (Nextcloud, ownCloud), `frontend/src/translations.json` or the per-locale files the OpenCloud SDK expects; every string literal in `src/` that still carries "Add to AI Knowledge" / "Add to knowledge…" → **Make searchable by AI**; a locale-parity test in each CI (Nextcloud / ownCloud: PHPUnit or a Node script; OpenCloud: Vitest) |

**Acceptance** — `cf1-locales.sh` PASS for each repo; L3 row 7.

### CF1.5 — OpenCloud: chat about a file, research chat, media, binary Save to Files (cut line)

| | |
| - | - |
| Repo · class · type | `synaplan-opencloud` (first step of P2; may run during P1 — `00` row 34) · app release · `feat(app)` |
| Branch | `feat/cf1-5-chat-media-binary-save` |
| Depends on | CF2.2 merged (OIDC bearer tokens on `/v1/chat/completions` — D18); E-4; vendor the contract fixtures with a checksum test in the first P2 PR (after CF5.10) |
| Reads | `01` §4 D7; Nextcloud `lib/Http/SseProxyResponse.php`, `lib/Controller/ChatController.php`, `MediaController.php`; `backend/internal/handler/{summarize,files}.go`, `cs3reader/write.go` |
| Touches | Go: `handler/chat.go` (SSE proxy to `/v1/chat/completions` with the exchanged token), `handler/media.go`, `handler/files.go` + `cs3reader/write.go` (binary upload, size limit from `ComputeConfig`-independent constant 100 MB, replaces the 2 MiB text-only `SaveToSpace`), tests; web: `components/ChatDialog.vue`, `views/Synaplan.vue` (research chat), the `kindFolder()` mapping for all kinds |

**Acceptance** — `09` §2 row 8; Go tests for binary save (size, MIME, conflict rename); Playwright spec "chat streams" against `:9201`.

---

## 5. CF2 — One identity

Wave contract: `02` (all), `05` W1, W2, W8, `06` §4. Tests: `09` §3.

### CF2.1 — Instance columns, capabilities both ways, delivery origin, approved hostnames

| | |
| - | - |
| Repo · class · type | `synaplan` (P1) · backend-only + ota-candidate · `feat(platform-links)` |
| Branch | `feat/cf2-1-instance-capabilities` |
| Depends on | E-9 (reference client skeleton, row 45); schema asks `00` §3 rows 6 and 43 agreed |
| Reads | `02` §8 (first two rows), §8.1, §9 (`BPLATFORMINSTANCES`), §6.2; `03` §2 (two origins, delivery-origin rule, probe, paths); `PlatformInstance.php`, `PlatformInstanceController.php`, `AdminPlatformInstanceController.php`, `RedirectUriPolicy.php` (`DEV_LOCAL_HOSTS`, `isDev()`), `SsrfGuard.php` |
| Touches | migration (`ADD COLUMN IF NOT EXISTS` × 7), entity, `PlatformInstanceService`, new `Service/PlatformLink/DeliveryOriginPolicy.php`, `RedirectUriPolicy.php` (the dev host list moves to one shared constant), `GET`/`PATCH instances/self` (+ OpenAPI), `frontend/src/components/people/PlatformInstancesTab.vue` (OpenCloud rows, reachability, approval by hostname), five locales, the reference client (register + ping), contract fixtures |

**Build**

1. Columns of `02` §9: `BDELIVERYURL`, `BREACHABLE`, `BOIDCCLIENTID`, `BAPPCAPABILITIES`, `BAPPVERSION`, `BAPPROVEDHOSTS`, `BSERVERID`.
2. `GET instances/self` adds `capabilities` (this release: `[]`; the list grows per brief, `02` §8.1), `app_capabilities`, `delivery_url`, `reachable`. `PATCH instances/self` with the optional fields of `02` §8; `app_capabilities` names the core does not know are ignored (stored list = known names only), so a newer app keeps working with an older core (C1).
3. `DeliveryOriginPolicy` implements the delivery-origin rule of `03` §2 (registered hostname over https with `SsrfGuard`; approved hostname; the shared dev list while `RedirectUriPolicy::isDev()`); the dev list = `localhost`, `127.0.0.1`, `::1`, `nextcloud`, `owncloud`, `cloud-fixture`, `host.docker.internal`, one constant for registration, redirect URIs and delivery. On save, the unsigned ping probe (5 s, any HTTP answer = reachable) writes `BREACHABLE`.
4. Admin → People → Platform instances: `client=opencloud` listed; approval dialog with the sentence of `05` §3 ("Approval") writing the hostname to `BAPPROVEDHOSTS`; removing it is one click.

**Tests** — migration review (C11); `DeliveryOriginPolicyTest` (each of the three rules, the dev list only under `isDev()`, loopback refused even when approved, self-signed TLS refused); `PlatformInstanceServiceTest` (PATCH merge semantics, unknown capability names ignored and the rest of the PATCH applied, probe result stored); controller test; OpenAPI → schemas → `vue-tsc`.

**Acceptance** — `cf2-identity.sh` **Registration** block (register the reference client as `cloud-fixture` in both profiles — possible now that the dev host list has it — PATCH, `reachable = true`, `capabilities` present and empty). The admin screen is walked in `09` §3 L3 row 8 (Synaplan side, P1).

### CF2.2 — Canonical identity rows, SSO status, bind, bearer attribution

| | |
| - | - |
| Repo · class · type | `synaplan` (P1) · backend-only · `feat(platform-links)` |
| Branch | `feat/cf2-2-identity-rows-sso-bind` |
| Depends on | CF2.1; schema ask `00` §3 row 39 agreed (canonical rows and adoption) |
| Reads | `02` §3 (incl. "Which client is `azp`", "Bearer tokens on chat"), §3.1, §5.1, §8 (`sso/status`, `sso/bind`, `identities/adopt`, admin users), §9 (`BEXTERNALIDENTITIES` columns), §10; `01` §4 D8, D15, D18; `backend/config/packages/security.yaml`, `OidcBearerAuthenticator.php`, `OidcUserService::oidcSource()`, `ExternalIdentity.php`, `ExternalIdentityRepository::upsert()`, `AdminUserProvisioningService.php`, `AdminUserProvisioningController.php`, `PlatformLinkExchangeService.php` |
| Touches | migration (`BMODE`, `BSTATUS`, `BREVOKEDAT`, `BREADSCOPE` — all four now, so later briefs add no migration to this table — plus `BMODE` on existing rows), entity, `AdminUserProvisioningService` + `AdminUserProvisioningController` (instance credential → canonical row, key bound), new `PlatformSsoService`, routes in `PlatformLinkController` (`sso/*`, `identities/adopt`; + OpenAPI), authenticator (attribution; `supports()` for the two `/v1` paths), `security.yaml` (`openai_compat` gains `OidcBearerAuthenticator`), audit rows, the reference client (`sso` profile: bind; adoption), contract fixtures |

**Build**

1. Canonical rows (`02` §3.1): provisioning and key minting with the instance credential write `(client, instance, uid)`, `BMODE = provision`, and bind the minted key; without the credential both behave as today. `POST identities/adopt` (key + instance credential) rewrites the key owner's legacy row `(client, '', '<server id>:<uid>')` when the server id equals the instance's `BSERVERID`, binds that key, and answers 200 again on a replay; when the canonical row already exists it binds the key to that row and deletes the legacy row. The migration sets `BMODE` on existing rows (`link`, `provision`, `NULL` for `oidc:`). Provisioning without the credential never writes a second row for an adopted person.
2. `GET sso/status` (bearer + instance credential) → `{bound, mode, status}`.
3. `POST sso/bind`: requires a live bearer whose `azp` equals the instance's `BOIDCCLIENTID` (the client that appears as `azp`, `02` §3 — in dev the exchange client `synaplan-opencloud`), writes the identity row (`mode=sso`, `status=active`), audit `platform_link.bound`.
4. Attribution (D8): every bearer call whose `azp` maps to an instance updates `BLASTSEEN`; a bearer call for a revoked or paused identity answers `403 platform_disconnected` with the sentence of `02` §6.3.
5. Bearer on chat (D18): the `openai_compat` firewall accepts OIDC bearer tokens for exactly `POST /v1/chat/completions` and `GET /v1/models`, with the same attribution; every other `/v1/*` path keeps refusing them.
6. `capabilities` += `sso_bind`, `identity_adopt`.

**Tests** — handshake-style tests: wrong `azp`, unknown instance, revoked identity, replay of bind (idempotent 200), paused → 403. Authenticator unit test. Bearer on chat: a valid bearer on `/v1/chat/completions` and `/v1/models` → 200, on `/v1/messages` → 401, a paused identity → 403. Canonical rows: provisioning with and without the instance credential; adoption with the person's key (canonical row, key bound), with someone else's key (403), with a wrong server id (403), without the instance credential (401), replayed (200, still one row), onto an existing canonical row (key bound, legacy row gone); provisioning after adoption writes no second row.

**Acceptance** — `cf2-identity.sh` **Bind**, **Chat** and **Adoption** blocks against the reference client (`sso` and `keyed` profiles). J-CF-1 is walked in CF2.6 (Nextcloud, if CF-S2 says yes) or CF2.7 (OpenCloud, P2).

### CF2.3 — Background key and `cloud:watch`

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `feat(platform-links)` |
| Branch | `feat/cf2-3-background-key-cloud-watch` |
| Depends on | CF2.2 |
| Reads | `02` §4, §4.1, §4.2, §8 (`sso/background-key`); `ApiKeyScope.php`, `ApiKeyController.php` |
| Touches | `ApiKeyScope.php` + `ApiKeyScopeSubscriber.php` (every row of `02` §4.1 from "files covers" down: `cloud:watch` for `/api/v1/cloud/watches` and `/api/v1/cloud/watches/*` except `from-message`, and `POST /api/v1/cloud/trials`; `from-message` session only; `/api/v1/cloud/status` + `/api/v1/cloud/files/*` → `files`; `identities/adopt` for `chat`, `files` or `rag`), `PlatformLinkController` route, key labelling ("<App>: <host> (<user>) — while you are away", linked-platform badge), tests, contract fixtures |

**Tests** — `ApiKeyScopeTest` (new scope, exact branches, `/api/v1/cloud/watches` denied for `files`, `trials` allowed for `cloud:watch`, `from-message` denied for every key, `identities/adopt` allowed for each platform scope); background-key mint test (scopes exactly `files rag cloud:watch`, bound to the identity, plaintext returned once).

**Acceptance** — `cf2-identity.sh` **Background key** block.

### CF2.4 — Lifecycle endpoint

| | |
| - | - |
| Repo · class · type | `synaplan` (P1) · backend-only · `feat(platform-links)` |
| Branch | `feat/cf2-4-lifecycle` |
| Depends on | CF2.3; `00` §3 row 38 agreed |
| Reads | `02` §7 (incl. "Deleting a person in one cloud"), §8 (`lifecycle`, admin delete); `01` §4 D16; C18; `AdminController::deleteUser()`, `AdminUserProvisioningService::PROVIDER_ID` |
| Touches | `PlatformLinkController` (+ OpenAPI), `PlatformSsoService` or a new `PlatformLifecycleService`, new `Service/PlatformLink/ProvisionedAccountPolicy.php`, `AdminController.php` (API-key path of the delete, + OpenAPI `409`), tests; `capabilities` += `lifecycle` |

**Build** — `POST lifecycle {external_id, event}`: `deleted` → this identity revoked and the keys bound to it revoked; the account is deleted only when `ProvisionedAccountPolicy` finds all four conditions of `02` §7, otherwise it stays and the audit row `platform_link.account_kept` names the reason; `disabled` → identity `paused` with reason; `enabled` → `active`. The generic admin delete (`AdminController::deleteUser()`) asks the same policy when an API key calls it and answers `409 account_in_use` for an account the policy keeps; a session admin in the admin screen is unchanged. Watch effects arrive in CF5.1 through a hook this brief leaves (`LifecycleEffect` interface, no-op implementation). Audit rows.

**Tests** — three events × (sso identity, linked identity, provisioned account); idempotency. Policy: a sole provisioned account is deleted; failing any one condition keeps it (provisioned for another identity, a second active identity, a password, the admin role) with only the identity revoked; a legacy provisioned account whose minted key is not bound to its row is still deleted; the admin delete with an API key → 409 for a kept account and 200 for a sole one; a session admin delete is unchanged.

**Acceptance** — `cf2-identity.sh` **Lifecycle** and **Deletion** blocks against the reference client. The cloud-side walks (`09` §3 L3 rows 7 and 10) run in CF2.10 (Nextcloud) and CF2.8 (ownCloud.online); OpenCloud has no disabled-user event and no provisioned accounts, so CF2.7 covers only user deletion. `identity_revoked` notifications are sent from CF3.1 on; until then the app learns at its next call (401 / 403).

### CF2.5 — Apps cards: mode-aware status, turn off, disconnect that can be undone

| | |
| - | - |
| Repo · class · type | `synaplan` (P1) · ota-candidate + backend-only · `feat(apps)` |
| Branch | `feat/cf2-5-cloud-apps-cards` |
| Depends on | CF2.4 |
| Reads | `02` §6.1, §6.3, §7 (disconnect and connecting again), §8 (`me/platform-links` rows); `05` W2, §3; `01` §4 D9, D19; `frontend/src/apps/{catalog,status}.ts`, `frontend/src/components/config/LinkedPlatformsConfiguration.vue`, `components/apps/AppCard.vue`, `MePlatformLinkController.php`, `PlatformLinkExchangeService::disconnect()` and `exchange()` |
| Touches | `status.ts` (mode-aware status from identity rows), `LinkedPlatformsConfiguration.vue` (Nextcloud and ownCloud.online cards; the Outlook card unchanged — C2), feature line from `app_capabilities`, `GET /api/v1/me/platform-links` (+ `mode`, `status`, `last_seen`, counts), new `POST …/{id}/turn-off` and `…/resume` (organisation-login identities), `DELETE …/{id}` keeps the row as `revoked` (D19) and answers `403 managed_by_administrator` for provisioned identities, `PlatformLinkExchangeService::exchange()` reactivates a revoked row on the same triple, consequence sentences, five locales (the namespace that owns the siblings — check `NAMESPACE_KEYS`), `.github/mobile-impact-policy.json` for new paths. No `opencloud` catalog entry (CF2.11, P2) |

**Tests** — controller tests for turn-off / resume / disconnect (row kept, key revoked), reconnect on the same triple reactivates; Vitest for the cards with an identity per mode; the Outlook card renders as before.

**Acceptance** — `cf2-apps-card.sh` against the reference client; the Synaplan cards walked (`09` §3 L3 row 6, Synaplan side); Synamail's sign-in steps unchanged (C2); DoD-7 (dark, V2, 320 px).

### CF2.6 — Nextcloud: organisation login (only if CF-S2 says yes)

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` (P1) · app release · `feat(app)` |
| Branch | `feat/cf2-6-organisation-login` |
| Depends on | CF2.10; CF2.2 and CF2.3 merged; CF-S2 = yes (otherwise this brief moves to CF6 and STATUS says so) |
| Reads | `02` §3 ("Which client is `azp`"), §5.1, §4.2; `05` W1, W8; the CF-S2 STATUS entry |
| Touches | `lib/Service/SynaplanClient.php` (bearer path), new `lib/Service/OrganisationLoginService.php` (token from `user_oidc`, cache until expiry − 30 s, refresh), `lib/Controller/ConsentController.php` (bind on consent), `lib/Settings/SynaplanAdmin.php` + `src/settings.ts` (the organisation-login row of W8, **Check organisation login**, `oidc_client_id` from the spike in `PATCH instances/self`), Connect card fallback for users without an OIDC session, `appinfo/info.xml` (`user_oidc` optional dependency, version floor from the spike), `l10n/` |

**Acceptance** — L3 rows 3–4 on Nextcloud; Playwright spec "organisation login consent" with Keycloak on the dev stack (`NC_OIDC=1`).

### CF2.7 — OpenCloud: register, consent + bind, lifecycle, account extension (Connect mode = cut line)

| | |
| - | - |
| Repo · class · type | `synaplan-opencloud` (P2) · app release · `feat(app)` |
| Branch | `feat/cf2-7-register-consent-bind` |
| Depends on | P1 exit (contract v1); CF-S1, CF-S3 answers |
| Touches | Go: `handler/me.go` (consent state, `sso/status` + `sso/bind` calls with the exchanged token and the instance credential), new `handler/instance.go` (registration with an admin key or pending approval; `PATCH instances/self` with `oidc_client_id` = the token-exchange client — `synaplan-opencloud` in dev — `server_id` and `app_capabilities`), `handler/lifecycle.go` (user-deleted events → `lifecycle`), config for the instance credential; web: consent card (W1) before the first action, account extension "Synaplan" (connection state, turn off) per CF-S1; Connect-your-account mode only if CF-S3 found an encrypted store — otherwise moved to CF6.6 in STATUS |

**Acceptance** — L3 rows 1–2 and 6 on OpenCloud; Playwright "consent then summarize"; Go tests for registration and bind.

### CF2.8 — ownCloud.online lifecycle and admin copy

| | |
| - | - |
| Repo · class · type | `synaplan-owncloud-online` (P3) · app release · `feat(app)` |
| Branch | `feat/cf2-8-lifecycle-admin-copy` |
| Depends on | CF1.1; CF-S4 (disable / enable events) |
| Touches | user hooks → `lifecycle` calls (`preDelete` → `lifecycle(deleted)` instead of `deleteRemoteAccount()` — D16; disable / enable); provisioning and key minting with the instance credential, the one-time adoption job and `PATCH instances/self` with `server_id` (D15, as in CF2.10); admin screen copy per W8 with "not available on ownCloud.online" for organisation login; `l10n/` |

**Acceptance** — the adoption check of CF2.10 on the E-5 stack; L3 rows 5–7 and 10 on ownCloud.online.

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

**Acceptance** — `cf2-file-instance.sh` against two `keyed` reference-client instances of client `nextcloud` sharing remote id `5678`, plus one `sso` instance with a 110-character id.

**Don't** — read the instance from a request field; touch rows of other sources.

### CF2.10 — Nextcloud identity basics (whatever CF-S2 answers)

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` (P1) · app release · `feat(app)` |
| Branch | `feat/cf2-10-identity-basics` |
| Depends on | CF2.1–CF2.5 and CF2.9 merged; CF1.3c |
| Reads | `02` §2, §3.1, §7, §8, §8.1; `01` §4 D15, D16; `05` W8 |
| Touches | `lib/Service/PlatformLinkService.php` (registration also in provision mode — decision 6; `PATCH instances/self` with `server_id` and `app_capabilities` on admin save), `lib/Service/UserAccountService.php` (provisioning and key minting with the instance credential; `deleteRemoteAccount()` replaced by `lifecycle(deleted)` — D16), new `lib/BackgroundJob/AdoptIdentitiesJob.php` (one-time, once registered and `capabilities` has `identity_adopt`: per user with a stored key → `identities/adopt` — D15), `lib/Listener/UserDeletedListener.php` and a new `lib/Listener/UserChangedListener.php` (the `enabled` flag; moved here from CF1.3) → `lifecycle` calls, `lib/Settings/SynaplanAdmin.php` + `src/settings.ts` (the four-mode screen W8, after `AdminSettings.vue` is split per `11` §3.3; the organisation-login row says "Needs the Nextcloud app user_oidc" until CF2.6), `l10n/` |

**Tests** — PHPUnit: adoption job (adopts, then a second run changes nothing; skips users without a key; stops on `403` with one log line), lifecycle calls on delete / disable / enable with a mocked client, provisioning sends the instance credential.

**Acceptance** — after the upgrade on the dev stack the adoption job leaves no legacy row for this cloud's provisioned users (`db_query` on `BEXTERNALIDENTITIES`) and a second run changes nothing; `09` §3 L3 rows 6, 7 and 10 on Nextcloud.

### CF2.11 — OpenCloud Apps card (P2)

| | |
| - | - |
| Repo · class · type | `synaplan` (P2) · ota-candidate · `feat(apps)` |
| Branch | `feat/cf2-11-opencloud-apps-card` |
| Depends on | P1 exit; may merge before or with CF2.7 |
| Reads | `02` §6.1; `05` W2, §3; CF2.5 |
| Touches | `frontend/src/apps/catalog.ts` (`opencloud` entry), the card in `LinkedPlatformsConfiguration.vue` with the CF2.5 status, feature line and Turn off; five locales; mobile policy |

**Acceptance** — `09` §3 L3 rows 1–2 (Synaplan side) on the P2 stack; DoD-7.

---

## 6. CF3 — Files both ways

Wave contract: `03` (all), `07` §3–§5, §8–§11, `05` W3, W4, W9, W11, `06` §5. Tests: `09` §4.

### CF3.1 — Copy protocol, ledger, `platform` destination

| | |
| - | - |
| Repo · class · type | `synaplan` (P1) · backend-only · `feat(files)` |
| Branch | `feat/cf3-1-platform-destination` |
| Depends on | CF2.4, CF2.5; `00` §3 rows 11 and 37 agreed (schema ask, signed notifications); CF-S1, CF-S3, CF-S4 answered (inbound route, inline budget) |
| Reads | `03` §2 (wire format, two origins, delivery-origin rule, probe, paths), §3, §4 (send response, claim / result table), §6, §8; C4, C8, C20; `Destination/{DestinationProvider,DestinationResult,DestinationRegistry,WebDavDestinationProvider,FileSendService,RequestedFolderDelivery}.php`, `FileSendController.php`, `RedisService::getAndDelete()`, `LinkCodeService.php` (ticket pattern), `SavedTaskTickService.php` (sweep pattern), `Execution/Runner/SaveToFolderRunner.php`, `Service/Federation/{FederationSigner,FederationIdentityStore}.php` and `EncryptionService.php` (signing and key-storage pattern) |
| Touches | migration `BCLOUDDELIVERIES` (columns of `03` §4), new `Entity/CloudDelivery.php` + repository, new `Destination/PlatformDestinationProvider.php`, `DestinationResult::pending()`, new `Service/PlatformLink/NotifyKeyStore.php` (Ed25519 pair from `FederationSigner::generate()`, encrypted in `BCONFIG` group `PLATFORM_LINKS` like `FederationIdentityStore`, created on first use) + console command `app:platform-links:rotate-notify-key`, `GET instances/self` += `notify_keys`, new `Service/PlatformLink/DeliveryNotifier.php` (POST the signed `{type, id, instance_id, ts, kid, sig}` to the delivery URL through `DeliveryOriginPolicy` from CF2.1, 30 s budget; also sends `identity_revoked` on disconnect, turn-off, `lifecycle(deleted)` and instance revocation — best effort, the app learns at its next call otherwise), `notify-vectors.json` test vectors in the contract folder, claim, one-time download and result routes in `PlatformLinkController` (+ OpenAPI; shapes and error codes of `03` §4) with a per-instance failed-claim counter (`429` above 120 a minute), `DestinationFailureCode` += `forbidden`, `unconfirmed`, `FileSendController` platform answers (`200` delivered / `202` pending, `03` §4), sweep in the scheduler's `tasks` lane (`queued` > 10 min → `failed` with `unconfirmed`), `SaveToFolderRunner` + `RequestedFolderDelivery` accepting a `platform` target `{kind, ref}` incl. `next_to`, test-only env `CLOUD_DELIVERY_TTL_SEC` and `CLOUD_DELIVERY_UNCONFIRMED_SEC` (§0.8), `capabilities` += `deliveries`, the reference client (verify, claim, write into its folder, answer inline or with the result call), contract fixtures |

**Build order** — ledger + entity → notify key, signing, `notify_keys` and the test vectors → provider returning `pending()` when the notify budget is exceeded → notifier → claim (`GETDEL`, instance-bound, the answer of `03` §4 with a `download_path`) → download → result (idempotent; may turn a `failed` / `unconfirmed` row into `delivered`) → sweep → `identity_revoked` → `save_to_folder` integration.

**Tests** — C4 suite (replay `409 already_claimed`, expiry `410`, wrong instance `403`, wrong user), signing (a body verifies with the published key and fails with any other; the test vectors pass; rotation lists both keys for 24 hours and signs with the new one from the next hour), failed-claim limit, download path (once, 5 minutes), notifier (budget, the three origin rules, SSRF), provider (`success` / `pending` / `failure` with `DestinationFailureCode`), sweep (`failed` + `unconfirmed`; a late result turns it into `delivered`, nothing else changes back), `identity_revoked` sent for each of the four events, `SaveToFolderRunner` platform target.

**Acceptance** — `cf3-copy.sh`, `cf3-ticket.sh` and `cf3-inbound.sh` against the reference client (both profiles) before any app brief exists.

**Don't** — authenticate the notification with the instance secret (Synaplan keeps only its hash); put anything but `{type, id, instance_id, ts, kid, sig}` in it.

### CF3.2 — Copy-to-cloud menu, toast, Sent to

| | |
| - | - |
| Repo · class · type | `synaplan` · ota-candidate · `feat(files)` |
| Branch | `feat/cf3-2-copy-to-cloud-ui` |
| Depends on | CF3.1 |
| Reads | `03` §4 (UI rows), §6, §7; `05` W3, §3; `FilePushMenu.vue`, `FileRowActions.vue`, the file detail view in `FilesView.vue` |
| Touches | `FilePushMenu.vue` (linked clouds whose identity is active, whose app reports `deliveries` and whose instance is reachable — `03` §4 — by app name and host with the landing-folder line, above WebDAV), toast with **Open in …**, "Sent to" block on the file detail (ledger rows: where, when, state), `files.json` × 5 (`en` "Send to cloud" → "Copy to cloud"), generated schemas, mobile policy |

**Acceptance** — `make test-e2e` plus a new `@ci` spec for the menu (a reference-client instance with and without `deliveries`); the Synaplan side of `09` §4 L3 rows 1–3 against the reference client; DoD-7. The full journey J-CF-3 is walked in CF3.3.

### CF3.3 — Nextcloud: inbound, claim and write, From Synaplan, Save to Files kinds

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` (P1) · app release · `feat(app)` |
| Branch | `feat/cf3-3-inbound-from-synaplan` |
| Depends on | CF3.1, CF3.2 merged; CF2.10; CF-S5 (private link, notifications, new-file menu) |
| Reads | `03` §2, §3, §4 (claim / result shapes), §5 (Nextcloud column), §6, §8; C20; `05` W4; `lib/Controller/MediaController.php` (`KIND_FOLDERS`, `IRootFolder::getUserFolder`) |
| Touches | new `lib/Controller/InboundController.php` (`#[PublicPage]`, `#[NoCSRFRequired]`, `#[AnonRateLimit]`, route `api/v1/inbound`; verifies before it dispatches on `type`: `delivery`, `identity_revoked` — deletes the person's stored key; unsigned or failed → `401`), new `lib/Service/NotificationVerifier.php` (signature with the `notify_keys` cached from `instances/self` — refreshed hourly, on admin save and at start, never on an unknown `kid`; own `instance_id`; `ts` ± 5 min; seen ids for 10 minutes in the distributed cache; passes the shared `notify-vectors.json`), new `lib/Service/DeliveryService.php` (claim, download the `download_path` joined to `synaplan_url`, resolve user, target, conflict rule, write, result; own-write memory 10 min), the admin field "Address Synaplan uses to reach this Nextcloud" (empty = the absolute URL of `api/v1/inbound`; the dev stack sets `http://nextcloud/index.php/apps/synaplan_integration/api/v1/inbound`) sent as `delivery_url` with `app_capabilities` += `deliveries` through `PATCH instances/self` on admin save, notification "Synaplan copied … to …" with link, personal setting "Save files from Synaplan to" (file picker), `src/files-init.ts` new-file menu entry **New → From Synaplan…** + picker component (`GET /api/v1/files`, Generated first), Save to Files for every generated kind in the chat, `l10n/` |

**Tests** — `NotificationVerifierTest` (the shared test vectors) and `InboundControllerTest`: forged, replayed, stale and other-instance notifications → `401` and zero outbound calls (HTTP client mock); an unknown `kid` → `401` without a key refresh; `identity_revoked` deletes the stored key.

**Acceptance** — `CLOUD=nextcloud cf3-copy.sh` and `cf3-inbound.sh`; L3 rows 1–6 on Nextcloud; Playwright "copy lands in Synaplan/Images".

### CF3.4 — ownCloud.online: same (CF-S4)

**P3.** Branch `feat/cf3-4-inbound-from-synaplan`; depends on CF2.8, CF-S4. Same Build list and tests as CF3.3 with ownCloud's APIs (file hooks, notifications app per the spike; the per-address limit as a counter in the app's cache). Acceptance: `CLOUD=owncloud cf3-copy.sh` and `cf3-inbound.sh`; L3 rows 1–6 by hand.

### CF3.5 — OpenCloud: inbound behind the proxy, impersonated write, binary save, From Synaplan

**P2.** Branch `feat/cf3-5-inbound-from-synaplan`; depends on CF2.7, CF1.5, CF-S1, CF-S3. Go: `handler/inbound.go` (unprotected proxy route; the checks of CF3.3 with Go tests that pass the shared `notify-vectors.json`, keys from `instances/self`; `SYNAPLAN_DELIVERY_URL` = `http://host.docker.internal:9106/api/synaplan/inbound` in dev), `service/delivery.go` (claim, download, write as the user through the service account), notification; web: create-menu extension "From Synaplan…" + picker. Acceptance: `CLOUD=opencloud cf3-copy.sh` and `cf3-inbound.sh`; L3 rows 1–4 and 6; Playwright spec.

### CF3.6 — Pickup protocol, `folder_fetch`, read-scope mirror, WebDAV `list()` / `get()`

| | |
| - | - |
| Repo · class · type | `synaplan` (P1) · backend-only · `feat(tasks)` |
| Branch | `feat/cf3-6-folder-fetch-pickup` |
| Depends on | CF3.1 merged (shares the notifier); schema ask `00` §3 row 29 agreed |
| Reads | `07` §3, §4, §5, §8, §9, §10, §11; C15, C16; `Capability.php`, `Execution/Runner/{UrlFetchRunner,McpFetchRunner,CodeRunRunner}.php`, `SavedTaskRunner.php`, `Message::addFile()`, `WebDavClient.php`, `ExternalIdentity.php` (`BREADSCOPE` exists since CF2.2) |
| Touches | migration `BCLOUDPICKUPS`; `Capability::FolderFetch` (read class); new `FolderFetchRunner` (channels: linked clouds with readable folders, WebDAV connections); new `Service/PlatformLink/PickupService.php` (ticket 10 min in Redis, claim `GETDEL`, the wire shapes of `07` §4: claim, listing → upload / reuse (only copies of the same instance, remote id and version — C19) / skip, files from the claiming instance only, result, partial, limits 50 / 200 MB / 100 MB, 20 per person per hour); `PUT platform-links/read-scope` (writes `BREADSCOPE`; `mode` = `watched` / `allowed` / `all`); pre-check against the mirror before a ticket; channels only for clouds whose app reports `pickups` (C21); `WebDavClient::list()` / `get()`; attachments on the run message as working copies (hidden, `BEXPIRESAT` 7 days once CF5.1 adds the column — until then the 24-hour default); sentences of `07` §10 as failure codes; `capabilities` += `pickups`, `read_scope`; OpenAPI, schemas; the reference client (pickup handler, both profiles); contract fixtures |

**Tests** — C16 suite; runner tests (list vs fetch; attached files; fail-closed when no file could be read — C15; partial result sentence; scope refusal before any ticket); reuse is instance-scoped (a held copy with the same remote id and version from another instance is answered under `upload`, not `reuse` — C19); limits; WebDAV list/get against a local WebDAV fixture; `StepInputResolver` sees the files of the step.

**Acceptance** — `cf3-pickup.sh` against the reference client (both profiles); `cf3-webdav.sh`.

**Don't** — give the runner a path input; let the instance choose the person.

### CF3.7 — Run card "Read n files", refusal sentence, read scope on the Apps card

| | |
| - | - |
| Repo · class · type | `synaplan` · ota-candidate · `feat(tasks)` |
| Branch | `feat/cf3-7-read-files-card` |
| Depends on | CF3.6 |
| Reads | `05` W9, §3; `07` §10; `frontend/src/components/multitask/{TaskCard,ComputeRunCard,TaskPlanBubble}.vue` (step cards in chat — the pattern), `frontend/src/components/config/SavedTaskCard.vue` (task runs) |
| Touches | new `frontend/src/components/multitask/FolderFetchCard.vue` beside `ComputeRunCard.vue` (progress "Reading files from … n of m", final sentence, file chips, **Open in …**), its use in `TaskPlanBubble.vue` and `SavedTaskCard.vue`, Apps card line "Synaplan may read: …", locales, generated schemas, mobile policy |

**Acceptance** — the Synaplan side of `09` §4 L3 rows 7–10 against the reference client; DoD-7. J-CF-14 is walked in CF3.8.

### CF3.8 — Nextcloud pickup handler and read setting

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` (P1) · app release · `feat(app)` |
| Branch | `feat/cf3-8-pickup-read-scope` |
| Depends on | CF3.6, CF3.7 merged; CF3.3; CF-S5 (request budget, job latency) |
| Reads | `07` §4 (incl. wire shapes), §5, §8; `05` W11; `03` §5 (own-write) |
| Touches | `InboundController` (`type=pickup`, answers `202`), new `lib/Service/PickupService.php` (claim, resolve selector in the user's view, scope check, listing, uploads — inline 25 s then a `QueuedJob`, result), read setting in personal settings (three choices, folder list, W11), files action **Let Synaplan read this folder**, admin cap, `PUT read-scope` reporting on every change, `app_capabilities` += `pickups`, `read_scope`, `l10n/` |

**Acceptance** — `CLOUD=nextcloud cf3-pickup.sh`; L3 rows 7–9 on Nextcloud.

### CF3.9 — ownCloud.online pickup handler and read setting (CF-S4)

**P3.** Branch `feat/cf3-9-pickup-read-scope`; depends on CF3.4, CF-S4. Same Build list with ownCloud's background jobs. Acceptance: `CLOUD=owncloud cf3-pickup.sh`; L3 rows 7–9 by hand.

### CF3.10 — OpenCloud pickup handler and read setting (CF-S1, CF-S3)

**P2.** Branch `feat/cf3-10-pickup-read-scope`; depends on CF3.5, CF-S1, CF-S3. Go: pickup through the service account; web: read setting in the account extension, folder action. Acceptance: `CLOUD=opencloud cf3-pickup.sh`; L3 rows 7–9.

---

## 7. CF4 — Searchable state

Wave contract: `04` §A, §E.1 (status, files, sources/events), `05` W5, `06` §6. Tests: `09` §5.

### CF4.1 — Status call, file detail, source events, FeatureModule `cloud_files`

| | |
| - | - |
| Repo · class · type | `synaplan` (P1) · backend-only · `feat(files)` |
| Branch | `feat/cf4-1-cloud-status-source-events` |
| Depends on | CF0.4; CF2.2 (identity rows); CF2.9 (files carry their instance); `00` §3 row 40 agreed |
| Reads | `04` §A.1 (state codes), §A.3 (request / answer fields), §A.5 (re-upload), §A.6, §E.1, §E.2 (`BFILES.BSOURCEDELETEDAT`, `BKEEPSYNCED`); `00` §3 rows 22 and 46; `Module/Federation/FederationModule.php`, `ModuleRegistry.php`, `backend/.env.minimal`, `docker-compose.minimal.yml`; `FileRepository::findByUserSourceIds`, the ephemeral reaper |
| Touches | new `Module/CloudFiles/CloudFilesModule.php` (decisive env `CLOUD_FILES_ENABLED`, default off; drives runtime config block `cloud_files`, gate 404, `capabilities` `cloud_status`), `.env.minimal`, `docker-compose.minimal.yml`; migration `BSOURCEDELETEDAT`, `BKEEPSYNCED`; upload field `keep_synced`; new `Controller/CloudFileController.php` (`POST /api/v1/cloud/status` — the request and answer of `04` §A.3, 1–500 items, cache ≤ 60 s keyed by user + instance + ids hash, header `X-Synaplan-Cache: hit\|miss`; `GET /api/v1/cloud/files/{id}`), `PlatformLinkController` `POST sources/events` (body and answer of `04` §E.1; matching (instance, remote id) — C19; changed → stale, and the copies kept up to date are listed in `reupload`; moved → path update; deleted → `BSOURCEDELETEDAT`, hidden, vectors removed; restored → cleared, re-vectorized); reaper: delete after 7 days; dev command `app:cloud-files:reap --now=<date>` for the test; `capabilities` += `cloud_status`, `source_events`; OpenAPI, schemas; the reference client (status calls with long ids and quoted etags in the `sso` profile; source events and re-uploads); contract fixtures. In `synaplan-charts`, a PR in the same milestone: `features.cloudFiles` → `CLOUD_FILES_ENABLED` (`11` §3.2) |

**Tests** — state matrix (each A.1 code), ids of 255 characters and quoted etags round-trip unchanged, cache, batch limit, each event type, `reupload` lists only copies with `BKEEPSYNCED = 1` and only that instance's people, reaper, gate (404 when off, no runtime block, capability absent).

**Acceptance** — `cf4-status.sh`, `cf4-status-perf.sh` (p95 < 1.5 s on 500 ids), `cf4-flag-off.sh` against the reference client.

### CF4.2 — Glyph kit and runtime-config override

| | |
| - | - |
| Repo · class · type | `synaplan` (P1) · ota-candidate · `feat(ui)` |
| Branch | `feat/cf4-2-glyph-kit` |
| Depends on | CF4.1 |
| Reads | `04` §A.2; `00` §3 row 13; `docs/FRONTEND_CONVENTIONS.md` § Iconography |
| Touches | SVG set drawn by the agent per `04` §A.2 (bird; bird with eyes; five badges — clock, arrows, dot, alert, pause; 16 and 20 px, `currentColor`) as a package the apps copy (`frontend/src/assets/cloud-glyphs/` + a `README` with the usage rules and WCAG numbers), `FileVectorPill.vue` using the same glyphs in the Library, runtime-config field `cloud_files.glyphs` (override URLs for white-label), locales for tooltips / `aria-label`, mobile policy |

**Acceptance** — the product owner approves the glyphs in the PR (screenshots at 16 and 20 px, light and dark); contrast ≥ 3:1 measured on light and dark (numbers in the PR); the Library pill matches the apps' glyph; override swaps both glyphs.

### CF4.3 — Nextcloud: row glyph, list header, sidebar tab, Make-searchable dialog, source events

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` (P1) · app release · `feat(app)` |
| Branch | `feat/cf4-3-bird-sidebar` |
| Depends on | CF4.1, CF4.2 merged; CF-S5; CF3.3 (delivery URL registered) |
| Reads | `04` §A.3 (batcher: one call per folder view, 500 ids per page, cache 60 s), §A.4, §A.5 (re-upload), §A.6, §A.7; `05` W5 |
| Touches | `src/files-init.ts` (row glyph through the batcher, list header), new sidebar tab component (five questions, **Remove from Synaplan**, **Open in Synaplan**), Make-searchable dialog with "Keep it up to date" (default on → `keep_synced=1`), node-event listeners → `QueuedJob` → `POST sources/events` (changed, moved, deleted, restored), a re-upload job for every `reupload` entry (that person's key, `overwrite=1`), app page list of searchable files, feature detection on `capabilities.cloud_status`, `app_capabilities` += `source_events`, `l10n/` |

**Acceptance** — `CLOUD=nextcloud cf4-status.sh`; L3 rows 1–8 on Nextcloud; Playwright "bird appears, sidebar answers".

### CF4.4 — ownCloud.online: same (CF-S4)

**P3.** Branch `feat/cf4-4-bird-sidebar`; depends on CF3.4, CF-S4 (FileList plugin, detail tab, hooks). Fallback per `06` §1: sidebar-only state. Acceptance: `CLOUD=owncloud cf4-status.sh`; L3 rows by hand.

### CF4.5 — OpenCloud: sidebar panel, action-label state, event-bus source events, upstream request

**P2.** Branch `feat/cf4-5-sidebar-state`; depends on CF3.5, CF-S1, CF-S3. Web: `sidebarPanel` extension with the five questions, state in the action label, searchable-files list in `views/Synaplan.vue`; Go: event-bus consumer → `sources/events` (or reconcile-only per the spike). Open an upstream issue for a row indicator; link it in STATUS. Acceptance: `CLOUD=opencloud cf4-status.sh`; L3 rows 1–8 (row 1 via the sidebar).

---

## 8. CF5 — Watched folders and automations

Wave contract: `04` §B, §C, §E; `07` §2, §6, §7; `05` W6, W7, W10; `06` §7. Tests: `09` §6.

### CF5.1 — Watch model, ledger, `file_event` trigger, upload and reconcile, lifecycle effects

| | |
| - | - |
| Repo · class · type | `synaplan` (P1) · backend-only · `feat(tasks)` |
| Branch | `feat/cf5-1-watch-model` |
| Depends on | CF4.1; CF3.1 (copy ledger for the loop guard); CF2.4 (`LifecycleEffect` hook); schema ask `00` §3 rows 16–21 and 41 agreed; CF-S3, CF-S4, CF-S5 answered (watch index at the cap) |
| Reads | `04` §B.1–§B.9, §C.2, §E.1, §E.2; `07` §2 (payload `files` + `set`); C6–C9, C14; `UrlWatch.php` + its service (shape to copy), `SavedTask.php` (`BTRIGGERTYPE`), `SavedTaskWebhookIngress.php` (payload size + trigger payload pattern), `SavedTaskTickService.php` |
| Touches | migrations `BCLOUDWATCHES`, `BCLOUDWATCHITEMS` (children-before-parent delete order in the service); entities + repositories; new `Service/CloudWatch/{CloudWatchService,WatchItemLedger,QuietPeriod,LoopGuard,WatchLimits}.php`; `SavedTask` trigger `file_event`; person API `GET/POST/PATCH/DELETE /api/v1/cloud/watches`, `GET …/options`, `POST …/{id}/files` (multipart; `(watch, source id, version)` unique → "already handled"), `POST …/{id}/reconcile`; folder and file targets (a file watch matches only new versions of that file; deletion → `target_gone`, a restore within 7 days → active — `04` §B.1, §B.2); instance API `GET platform-links/watches` for the apps' watch index (pages of ≤ 500 with `cursor`, `since` with tombstones, `ETag` / `304`, `410` for an expired cursor — `04` §B.8); `watches_changed` notify through the CF3.1 notifier; working copies (hidden, `BFILES.BEXPIRESAT` = upload + 7 days; `ReapEphemeralFilesCommand` honours `BEXPIRESAT` — D20) unless the watch keeps them searchable; the states and reason codes of `04` §B.2 verbatim (`active`; `paused` with `by_owner`, `identity_off`, `platform_user_disabled`, `permission`; `attention` with `target_gone`, `no_access`, `task_gone`, `policy_off`, `failing`; `stopped` with `by_owner`, `identity_deleted`) and the item reasons listed there; Stop per `04` §B.9 (row kept as `stopped`, preset task disabled, a bound task back to Manual); limits (100 / 500 per watch per hour, 1 000 per instance, 100 MB per file) with the waiting counts; caps of 20 active watches per person and 1 000 per instance from `BCONFIG` `CLOUD_FILES.WATCHES_PER_PERSON` / `CLOUD_FILES.WATCHES_PER_INSTANCE` (`409` with the sentences of `04` §B.7); dev-only command `app:cloud-watches:seed-test` (`APP_ENV=dev\|test`) for the index test; sweep for `running` > 30 min; `LifecycleEffect` implementation (pause / resume / stop watches); `capabilities` += `watches`; OpenAPI, schemas |

**Tests** — ledger unique key (C6); quiet period with a fake clock; loop guard three layers (C7) incl. a translate watch writing into its own folder; file targets (only new versions of that file match; deletion and restore); limits and waiting counts; caps per person and per instance; watch index (pages without duplicates, one change → one item in `since`, a stopped watch → a tombstone, nothing changed → `304`, expired cursor → `410`); reconcile answers (process / forget); lifecycle effects (C9); sweep (C8); scope `cloud:watch` on every route; flag off → 404.

**Acceptance** — `cf5-watch.sh` (Summarize preset arrives with CF5.2; until then the script uses a manual task), `cf5-quiet.sh`, `cf5-loop.sh`, `cf5-reconcile.sh`, `cf5-limits.sh`, `cf5-index.sh`, `cf5-lifecycle.sh`, `cf5-flag-off.sh` — all against the reference client (both profiles), which gains watch uploads, reconciliation and the watch index in this PR.

### CF5.2 — Presets → task templates, Keep-searchable sync, mail with links

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `feat(tasks)` |
| Branch | `feat/cf5-2-presets` |
| Depends on | CF5.1 |
| Reads | `04` §C.1, §C.3, §C.4; `07` §6; `Capability.php` (`translate`, `summarize`, `email_me`, `save_to_folder`, `document_generation`), `Graph/SavedTaskPlanFactory.php`, `Graph/StepInputResolver.php`, the mail templates of `email_me` |
| Touches | new `Service/CloudWatch/PresetFactory.php` (Translate: `translate` → `document_generation` → `save_to_folder` next-to / chosen folder with `<stem>.<lang>.<ext>`; Summarize and mail me: `summarize` (4–5 sentences) → `email_me` with **Open in …** and **See this run in Synaplan**, HTML + plain text, 20 per watch per hour; Use one of my tasks: bind an existing manual task), Keep-searchable sync (upload into the knowledge folder with `keep_synced=1` — a named folder skips Incoming by CF0.3; no task), per-file failure sentences of §C.4, mail template |

**Tests** — one test per preset (steps, inputs, outputs, failure sentence); mail render with a fixed summary text (two links, plain-text part, subject); the prompt asks for 4–5 sentences (the real-model sentence count is a walk check, `09` §6 L3 row 4); 20-per-hour wait.

**Acceptance** — `cf5-watch.sh` full; MailHog shows the mail; `cf5-limits.sh`.

### CF5.7 — Files into runs: attachments, file sets, run limits

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only · `feat(tasks)` |
| Branch | `feat/cf5-7-files-into-runs` |
| Depends on | CF5.2 |
| Reads | `07` §1, §2, §6, §8, §11; C15; `SavedTaskRunner::run()`, `Message::addFile()`, `MessagePreProcessor`, `CodeRunRunner` (mounts message attachments), the scheduler's `tasks` lane |
| Touches | `SavedTaskRunner::run()` attaches the run's files to the run message before processing; `file_event` payload exactly as `04` §C.2 (`files[]`, `set {kind, count, skipped, window_started_at}`, `watch {…, target_kind, target_id}`); "Files in this run" block in the run summary (`SavedTaskSummary.php`); file sets: `new_file` (default), `together` (window from the first file, `BWINDOWSEC` default 600, range 60 – 86 400, `BMAXFILES` default 50 — the scheduler closes windows), `whole_folder` (`BWINDOWSEC` default 3 600, range 600 – 86 400; `POST …/snapshots` listing → versions to upload, matched by (instance, remote id, version) — C19 → `…/close` → one run; at most once per window; refusal above 50 files with the sentence); run limits 50 files / 200 MB; `CodeRunRunner` mounts the run's attachments; `capabilities` += `file_sets`; OpenAPI, schemas; the reference client (snapshots); contract fixtures |

**Tests** — fail-closed without attachments (C15); windowing with a fake clock (three files → one run; the 51st opens a second run); snapshot (unchanged versions not re-sent; a copy with the same remote id and version from another instance counts as not held — C19; refusal sentence); `CodeRunRunner` sees the files by name.

**Acceptance** — `cf5-fileset.sh`.

### CF5.8 — Try it on a file, then automate; unattended file-work consent

| | |
| - | - |
| Repo · class · type | `synaplan` · backend-only + ota-candidate · `feat(tasks)` |
| Branch | `feat/cf5-8-trial-then-automate` |
| Depends on | CF5.7 |
| Reads | `07` §7, §9, §11 (`trials`, `from-message`); C17; `05` W10, §3; "Schedule this" step pinning (`SavedTaskGraphCapture.php`), `SavedTask::BALLOWUNATTENDED`, `SavedTaskComputePauseTest.php`, `COMPUTE.WORKSPACES_ENABLED` |
| Touches | `POST /api/v1/cloud/trials` (uploaded file(s) + instruction + watched folder → a chat with the files attached; returns the chat URL); `POST /api/v1/cloud/watches/from-message/{id}` (pins the turn's steps into a watch task; body carries `file_set`, `window_sec`, `output`, `allow_unattended`); pinning validator: a file-work script that contains a test file's name is rejected (422) with the sentence of `07` §10 / `05` §3 ("Rejected pinned script"); replay test helper that runs the pinned steps on a differently named file; serialization of runs for a watch that keeps a working folder (`workspace_busy` → wait, not fail); chat UI: **Do this for every new file in “<folder>”** under a turn whose files came from one cloud folder, with the file-set question and the file-work question (absent when Compute is off); Tasks trigger row for `file_event`; `capabilities` += `trials`; locales; mobile policy |

**Tests** — trial creates a chat with attachments; from-message pins exactly the turn's steps; `allow_unattended` set / unset; 422 on a test-file name; replay on `invoice-1003.pdf` after a trial on `invoice-0912.pdf`; Compute off → no file-work question in the API response.

**Acceptance** — `cf5-trial.sh`; L3 rows 5 and 9 (Synaplan side).

### CF5.3 — Tasks → Watched folders tab, trigger row, run detail, knowledge-folder badge

| | |
| - | - |
| Repo · class · type | `synaplan` · ota-candidate · `feat(tasks)` |
| Branch | `feat/cf5-3-watched-folders-tab` |
| Depends on | CF5.8 |
| Reads | `04` §B.9, §C.5; `05` W7, §3; `SavedTasksOverview.vue` (Watched pages tab is the pattern), the knowledge-folder view in `frontend/src/views/FilesView.vue` |
| Touches | `SavedTasksOverview.vue` (+ **Watched folders** tab: rows with cloud, folder or file, preset, last run, counts; Pause / Resume / Stop from the row with the kind-specific consequence sentence; the two empty states of `05` §3, naming only connected clouds whose app reports `watches` — C21), task trigger row for `file_event`, run detail with "Files in this run" and **Open in …**, knowledge-folder badge "Kept in sync with …" with **Stop syncing**, session-authenticated equivalents of the watch API under the Saved Tasks controller, locales, mobile policy, `@ci` Playwright spec |

**Acceptance** — the Synaplan side of `09` §6 L3 row 11 against the reference client; DoD-7; `make test-e2e`.

### CF5.4 — Nextcloud: watch dialog, permission card, eyes, queue, reconciliation, admin policy

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` (P1) · app release · `feat(app)` |
| Branch | `feat/cf5-4-watches` |
| Depends on | CF5.1, CF5.2, CF5.3 merged; CF4.3; CF-S5 |
| Reads | `04` §B (all), §C.1, §C.5; `02` §4.2; `05` W6; `07` §2 (file-set choice arrives in CF5.9 — leave the dialog slot) |
| Touches | files action **Watch with Synaplan…** (folders, and single files with the file variant of W6) → dialog W6 (presets, inputs from `GET …/options`, "Also the n files already here", **Start watching**, the cap sentences of `04` §B.7); permission card (`02` §4.2) → re-link with `cloud:watch` / re-mint / background key by mode; the watch index in an app table keyed by target id (`04` §B.8: synced incrementally on `watches_changed` and every 10 minutes, a full walk daily or on `410`) for the eyes and for matching node events, list header, sidebar watch section (owner, description, Pause / Stop for the owner only); node events → job list with the 60 s quiet period → `POST …/{id}/files`; hourly reconciliation job (`…/reconcile`); own-write guard; admin policy "Allow watching shared folders" and the background-jobs warning of W8; Watched folders list on the app page; `app_capabilities` += `watches`; `l10n/` |

**Acceptance** — `CLOUD=nextcloud cf5-*.sh`; L3 rows 1–4, 6–8, 10, 12–14 on Nextcloud; Playwright "watch a folder, drop a file, see the run".

### CF5.5 — ownCloud.online: same (CF-S4)

**P3.** Branch `feat/cf5-5-watches`; depends on CF4.4, CF-S4 (hooks, background jobs, cron detection). Acceptance: `CLOUD=owncloud cf5-*.sh`; L3 by hand.

### CF5.6 — OpenCloud: same with event bus and impersonation (CF-S3)

**P2.** Branch `feat/cf5-6-watches`; depends on CF4.5, CF-S1, CF-S3. Folder context action, sidebar, app-view list; Go consumer → quiet period → uploads with the person's background key; reconcile-only if the spike found no events. Acceptance: `CLOUD=opencloud cf5-*.sh`; L3 rows.

### CF5.9 — Dialog: what each run gets, try it on a file, whole-folder snapshots (one PR per app, in its phase)

| | |
| - | - |
| Repo · class · type | `synaplan-nextcloud` (CF5.9-nc, P1), `synaplan-opencloud` (CF5.9-oc, P2), `synaplan-owncloud-online` (CF5.9-oco, P3) · app releases · `feat(app)` |
| Branch | `feat/cf5-9-file-sets-trial` in each |
| Depends on | CF5.7, CF5.8 merged; the app's watch brief (CF5.4 / CF5.6 / CF5.5) |
| Reads | `07` §2, §7; `05` W6 (file-set block), W10; `00` §11 (file-set terms, trial terms) |
| Touches | dialog block **What should each run get?** (three choices with their one-sentence consequences; window and max shown for "together"; absent for a file watch); **Try it on a file / files** (upload with the person's credential to `POST /api/v1/cloud/trials`, open the chat URL); whole-folder snapshot push (`…/snapshots` listing → upload the requested versions → `…/close`) in the job list; `app_capabilities` += `trials`, `file_sets`; `l10n/` |

**Acceptance** — `09` §6 L3 rows 5 and 9 on the app's cloud; `cf5-fileset.sh`, `cf5-trial.sh` against the real app.

### CF5.10 — Contract v1 freeze (last step of P1)

| | |
| - | - |
| Repo · class · type | `synaplan` (P1) · backend-only · `feat(platform-links)`; `synaplan-nextcloud` · app release · `test(contract)` |
| Branch | `feat/cf5-10-contract-v1` (core), `test/cf5-10-vendor-contract` (Nextcloud) |
| Depends on | every other P1 brief merged; CF-S1, CF-S3, CF-S4 answered and their core consequences merged |
| Reads | `00` rows 44, 45, C22; the desktop contract pattern (`backend/tests/Fixtures/Desktop/` in the core, `tests/fixtures/desktop-contract/` with its README and checksum test in `synaplan-desktop`) |
| Touches | Core: `backend/tests/Fixtures/cloud-apps-contract/` complete — `paths.json`, one request and answer example per app-facing endpoint of `02` §8, `03` §4, `04` §E.1 and `07` §11, `capabilities.json` (both lists of `02` §8.1), `errors.json` (every code with its HTTP status), `limits.json`, `notify-vectors.json`, `README.md` (source commit, how to vendor, "change only additively"); a contract test that runs every example against the API; `instances/self.contract = 1`. Nextcloud: `tests/fixtures/synaplan-contract/` vendored byte for byte with a checksum test and the README's source commit |

**Acceptance** — the contract test green in `make ci-local`; the Nextcloud checksum test green; `09` §8.1 items 1–5; a STATUS decision "contract v1 frozen at <commit>".

---

## 9. CF6 — Beyond the apps

Briefs are written when an item is scheduled; each gets the same shape. Until
then `06` §8 is the list.

## 10. Environment briefs E-1 … E-9

Defined in [`10`](./10_local_environments.md) §4 with steps and acceptance;
they follow §0 of this file (type `chore(dev)`, branch `chore/env-<n>-<slug>`). E-9 is the reference client: its first version (the server skeleton, both profiles) comes before CF2.1, which adds registration and the probe; every later core brief extends it (§0.8, DoD-11).
