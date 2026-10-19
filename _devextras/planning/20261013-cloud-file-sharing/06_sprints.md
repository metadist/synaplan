# Spikes and sprints

Plan of record: [`00_master_plan.md`](./00_master_plan.md). A step starts when
every §3 row it cites is agreed there. Every sprint with an `ota-candidate`
step names its journeys and carries the five exit bullets of the UX contract
§6.

Each step below has a work order in [`08_agent_briefs.md`](./08_agent_briefs.md)
(same numbering) and each sprint a test plan in
[`09_functional_tests.md`](./09_functional_tests.md); the stacks are pinned in
[`10_local_environments.md`](./10_local_environments.md). A sprint exits when
its `09` section is green for the phase's cloud and its walks are in STATUS.

## 0. Phases, order and gates

### 0.1 Three phases (`00` row 42)

```text
 P0 Ready ──► P1 Core + Nextcloud ────────────────────────────────► break ──► P2 OpenCloud ──► P3 ownCloud.online ──► CF6 (optional)
              CF0 → CF1 → CF2 → CF3 ‖ CF4 → CF5 → CF5.10 freeze    (P2 go)    CF1 → … → CF5    CF1 → … → CF5
              released as M1 · M2 · M3 · M4 = the Nextcloud release (11 §2)
 spikes:      CF-S2, CF-S5 at the start · CF-S1, CF-S3, CF-S4 before CF3.1 and CF5.1 merge
```

P1 is released in four milestones and ends with the Nextcloud release; then
the track pauses until a dated "P2 go" in STATUS (`00` row 48,
[`11`](./11_nextcloud_release.md) §1).

A step's phase follows its repository: `synaplan` and `synaplan-nextcloud`
steps are **P1**, `synaplan-opencloud` steps **P2**, `synaplan-owncloud-online`
steps **P3**. Two exceptions: CF2.11 (`synaplan`, the OpenCloud Apps card) is
P2, and the OpenCloud half of CF0.5 (docs of today's code) is P1. Steps that
name "all three apps" (CF1.4, CF5.9) run once per phase for that phase's app.

| Phase | Steps | Gate before it starts | Exit |
| ----- | ----- | --------------------- | ---- |
| **P0** | E-1, E-2, E-3, E-6 (`10` §4); E-4 for the spikes and the compatibility check | The §3 rows the first briefs cite are agreed | `env-check.sh --cloud nextcloud --oidc` green |
| **P1** | CF-S2, CF-S5 at the start; CF-S1, CF-S3, CF-S4 before CF3.1 and CF5.1 merge; E-9 before CF2.1; CF0.0–CF0.7; CF1.3a–d, CF1.4 (Nextcloud); CF2.1–CF2.6, CF2.9, CF2.10; CF3.1–CF3.3, CF3.6–CF3.8; CF4.1–CF4.3; CF5.1–CF5.4, CF5.7, CF5.8, CF5.9 (Nextcloud), CF5.10 — in milestones M1–M4 (`11` §2) | P0 exit | `09` §8.1, then the release (`11` §6) and the break |
| **P2** | CF1.4 (OpenCloud), CF1.5, CF2.7, CF2.11, CF3.5, CF3.10, CF4.5, CF5.6, CF5.9 (OpenCloud) | P1 exit; the dated "P2 go" (row 48); E-4 green; CF-S1 and CF-S3 answered | `09` §8.2 |
| **P3** | E-5; CF1.1, CF1.2, CF1.4 (ownCloud.online), CF2.8, CF3.4, CF3.9, CF4.4, CF5.5, CF5.9 (ownCloud.online) | P2 exit; CF-S4 answered | `09` §8.3 |

Rules:

- **Start on merge, release on release.** An app step starts when the core
  steps it depends on are merged to `main` (local stacks run `main`). An app
  release is published only after a Synaplan release answers the capability
  in `instances/self` (C1).
- **Core first, then the app, inside each wave.** In P1 the Nextcloud brief
  of a wave walks the journeys of the core briefs before them. A flaw the walk
  finds in the core is a core `fix/…` PR, merged before the milestone's
  Synaplan release.
- **After each Synaplan release in P1**, the compatibility check of `09` §7
  runs against the current OpenCloud (v1.0.0) and ownCloud.online (1.0.0)
  releases: they must behave as before or better (C1).
- **The freeze.** CF5.10 publishes contract v1 at the end of P1. In P2 and P3
  a core change is additive only, needs a dated STATUS decision and runs with
  the reference-client suite and the Nextcloud regression set (C22).

### 0.2 Gates per repository

| Repo | Before every commit | Before every push / PR |
| ---- | ------------------- | ---------------------- |
| `synaplan` | `make ci-local` | `make test-e2e`; `make -C frontend test-e2e-layout` for nav or layout; after OpenAPI changes `make -C frontend generate-schemas` + `vue-tsc`; `node scripts/mobile-impact.mjs --base main --head HEAD` |
| `synaplan-nextcloud` | `make ci-local` (from CF1.3b; before that `make lint && make test`) | CI `All Checks Passed` with e2e on `nextcloud:33-apache` and `:35-apache` (from CF1.3b); `make test-e2e` against `make dev-up` |
| `synaplan-owncloud-online` | `make lint && make check-types`; `make test` from CF1.2 | CI `All Checks Passed` (from CF1.2) |
| `synaplan-opencloud` | `make frontend-lint frontend-typecheck frontend-test-unit backend-test backend-lint` | `make frontend-test-e2e-dev` (dev server `:9201`); CI `All Checks Passed` |
| `synaplan-docs` | Render the changed page locally | — |

Classes for `synaplan` steps follow `.github/mobile-impact-policy.json`; new
frontend paths are added to it in the same PR (C12). App repo steps ship
through their own channel and are marked **app release**.

---

## 1. Spikes (before the freeze)

Each spike is **timeboxed to two working days** on a `spike/…` branch that is
never merged. It ends with a dated STATUS entry that answers every question
with **yes / no / partly**, the version tested and a code pointer or test. A
"no" answer moves the dependent step to its fallback, recorded in the same
entry together with the briefs whose text changes. An overrun is itself an
answer ("not within two days") and moves the dependent step to its fallback.
Briefs: `08` §2.

Timing (`00` row 42): CF-S2 and CF-S5 run at the start of P1, because P1
builds on them. CF-S1, CF-S3 and CF-S4 run during P1 — a second agent can do
them in parallel — and must be answered before CF3.1 and CF5.1 merge, because
their answers (unprotected inbound route, inline time budget, watch index at
the cap) can change the core contract.

| Spike | Questions | Blocks | Fallback if "no" |
| ----- | --------- | ------ | ---------------- |
| **CF-S1 — OpenCloud web extension points** (8.1 SDK) | Row indicator or custom component in the resource list? Create-menu extension for "From Synaplan"? Context action on folders ("Watch with Synaplan", "Let Synaplan read this folder")? `sidebarPanel` for files and folders? `accountExtension` for a settings page? User notifications from an extension? Private-link format `/f/<id>`? | CF2.7, CF3.5, CF3.10, CF4.5, CF5.6 | Sidebar + action label + Synaplan app view; file an upstream issue |
| **CF-S2 — Nextcloud organisation login** | Can an app get the login token or an exchanged token from `user_oidc` (events, version floor, `store_login_token`)? Does the exchanged token carry `azp`? Refresh behaviour? Works with Keycloak and with openDesk's Nubus? | CF2.6 | Nextcloud stays on Connect / Accounts by administrator; organisation login moves to CF6 |
| **CF-S3 — OpenCloud unattended access** | Service account or impersonation to list folders and read and write a user's files without a request (copies, pickups, whole-folder snapshots)? Event bus consumer for upload finished, move, trash, restore, user deleted — config and delivery guarantees? Unprotected proxy route for `/api/synaplan/inbound`? Persistent store for per-user keys (encryption)? Project spaces: events and writes? Memory and rebuild time of the in-memory watch index at the cap (1 000 watches, `04` §B.8)? | CF2.7, CF3.5, CF3.10, CF4.5, CF5.6 | Copies only through "From Synaplan"; no pickups from OpenCloud (attach in the chat instead); watches on OpenCloud reconcile-only; Connect mode to CF6; a lower instance cap for OpenCloud |
| **CF-S4 — ownCloud 11 Files APIs** | FileList plugin to decorate rows? Detail tab? New-file menu plugin? File hooks or dispatcher events (create, write, rename, delete, restore)? Notifications app? Background jobs and cron mode detection? How long may a request run (inline pickup budget)? Private link? User disabled / re-enabled events? Which version does the hoster run (the app README says 11.0.10; the BW-Tech repository has tags up to `v11.0.13`; the spike runs on the host install at 11.0.11 + 39), does the checkout behave like the hoster's installation, and can the hoster give us a test tenant? Watch index at the cap (1 000 targets in an app table): first sync, incremental sync, matching cost per file event? | CF1.2, CF2.8, CF3.4, CF3.9, CF4.4, CF5.5 | Sidebar-only state; reconcile-only watches; pickups fully in background jobs; a lower instance cap |
| **CF-S5 — Nextcloud Files APIs at scale** (33–35) | Inline row rendering with one batched call on a 5 000-file folder (virtual scroll)? Sidebar tab API across 33–35? List header for the current folder? New-file menu entry? Node events for group folders, external storage, trash and restore? Notifications? Private link `/f/<fileid>`? Inline pickup budget inside a request and background-job latency with system cron? Watch index at the cap (1 000 targets): sync time, table size, matching cost per node event on both matrix images? | CF3.3, CF3.8, CF4.3, CF5.4 | Glyph only on folders + sidebar; reconcile-only for external storage; pickups fully in background jobs; a lower instance cap |

---

## 2. CF0 — Fix and align

**Goal:** the modes that are already shipped work end to end, and the docs say
what the code does.

**User-flow:** J-NC-1…3 (first walks, roadmap §3.1; defined in
[`../20260903_more_nextcloud/00_master_plan.md`](../20260903_more_nextcloud/00_master_plan.md)
§5) and the compatibility check of the current OpenCloud and ownCloud.online
releases. **Briefs:** `08` §3 · **Tests:** `09` §1.

| Step | Repo | Content | Class |
| ---- | ---- | ------- | ----- |
| CF0.0 | workstation, `synaplan`, app repos | **Local stacks ready for P1** (`10` §4): E-1 (cleanup, scheduler running), E-3 (Keycloak clients and users), E-2 (Nextcloud on MariaDB with cron, `user_oidc`, `ada` / `bob` / `carol`), E-6 (`env-check.sh`, fixtures, helpers), E-4 (OpenCloud 8.1.0, for the spikes and the compatibility check). The ownCloud.online compatibility check uses the host install on `:8088` (user `carol`, `carol.oco@example.org`) until E-5 (P3). The reference client (E-9) is not needed for CF0. Nothing in CF0.7 is walked on a stack that is not green here. | env |
| CF0.1 | `synaplan` | **D1.** Exact scope branches: `chat` → `POST /api/v1/summary/generate`, `POST /v1/chat/completions`, `GET /v1/models`, `POST /api/v1/media/generate` and the media download path; `memories` → `GET /api/v1/config/memory-service/check`. Contract fixture of every path each app calls (`01` §2); allow tests for the fixture, deny tests for neighbours (`/v1/messages`, `/v1/images/generations`, `/api/v1/admin/*`). | backend-only |
| CF0.2 | `synaplan` | **D2.** `owncloud` in `File::SOURCES` and `INCOMING_SOURCES` (same as `nextcloud`); source label "ownCloud.online" and filter in the Library, five locales; data fix per decision 26 (dry-run count in the PR). | backend-only + ota-candidate |
| CF0.3 | `synaplan` | **D3.** An upload from `nextcloud`, `opencloud` or `owncloud` that names a knowledge folder other than `DEFAULT` skips Incoming; without a folder, or with `DEFAULT`, it still lands in Incoming (C10). The apps already send the folder the person picked, so released app versions benefit without a new release. Outlook is unchanged. | backend-only |
| CF0.4 | `synaplan` | `check-stale` returns `vector_state` per item (additive). | backend-only |
| CF0.5 | `synaplan-docs`, `synaplan-nextcloud` | **D10, D11.** OpenCloud section of `docs/plugins.md` and the `docs/intro.md` bullet rewritten to the code (shared identity provider, personal space, manual updates). Nextcloud `docs/HOSTING_GUIDE.md` roadmap refreshed (stale handling shipped, scopes enforced). | docs |
| CF0.6 | `synaplan` | **D14.** More-Nextcloud STATUS: S2 merged, S3 absorbed here except the pairing-code fallback; roadmap row 5 "Folder watch" and "Nextcloud S3" point here. | docs |
| CF0.7 | all (walk) | First walks on local stacks after CF0.1–CF0.4 are merged: J-NC-1…3 and the provision mode on Nextcloud; the compatibility check of the current releases — ownCloud.online provision mode (summarize, translate, chat, knowledge status) and OpenCloud with organisation login (summarize, knowledge). Dated STATUS entries. | walk |

**Exit:** in provision and link mode a Nextcloud user summarizes, translates,
chats and generates an image without `insufficient_scope`; a file added with a
named knowledge folder skips Incoming; the ownCloud.online knowledge dialog
shows the real state and the Library labels its files; OpenCloud works as
before; walks recorded. Never cut.

---

## 3. CF1 — Parity floor

**Goal:** nothing in the Nextcloud 1.6 feature set is missing from
ownCloud.online or OpenCloud without a reason in `01` §5.2; all three apps
speak the core's words in five locales and have a CI gate. Per phase: P1
Nextcloud (CF1.3a–d, CF1.4), P2 OpenCloud (CF1.5, CF1.4), P3 ownCloud.online
(CF1.1, CF1.2, CF1.4).

**User-flow:** J-NC-1 (P1), J-CF-2 (P3). **Briefs:** `08` §4 · **Tests:** `09` §2.

| Step | Repo | Content | Class |
| ---- | ---- | ------- | ----- |
| CF1.1 | `synaplan-owncloud-online` | Mode enum (`shared` / `provision` / `link`); link mode ported from Nextcloud (`PlatformLinkService`, `LinkController`, routes, personal settings page, admin **Register this instance**); consent gate on every AI surface (D5); MIME filters on the four actions (D6); per-user keys encrypted (D4); chat launcher on every page like Nextcloud. | app release |
| CF1.2 | `synaplan-owncloud-online` | PHPUnit suite mirroring Nextcloud's; `.github/workflows/ci.yml` with `All Checks Passed`; `AGENTS.md`; consumes the E-5 Docker stack (`10` §3.4: BW-Tech checkout at the hoster's tag in `php:8.4-apache`, MariaDB 10.11, cron sidecar, port `8083`, Synaplan network) for walks and E2E (CF-S4). | app repo |
| CF1.3a | `synaplan-nextcloud` | **D21.** Nextcloud 33–35 (row 47); controller annotations → attributes, pinned by `ControllerAccessTest`; `nextcloud/ocp` off `dev-master`. Fast lane, ships alone as 1.6.1. | app release |
| CF1.3b | `synaplan-nextcloud` | Engineering baseline of `11` §3.1: CI with `All Checks Passed`, Synaplan pinned by ref and digest, contract test against the pinned OpenAPI spec, PHPStan, vue-tsc, Vitest, Playwright journeys of today's behaviour on 33 and 35, Renovate, `AGENTS.md`. No behaviour change. | app repo |
| CF1.3c | `synaplan-nextcloud` | **D4.** Per-user keys encrypted with transparent migration. The disabled / re-enabled listener moved to CF2.10, where it calls `lifecycle`. | app release |
| CF1.3d | `synaplan-nextcloud` | Release workflow: a tag on a green `main` commit builds, signs and publishes to the App Store. | app repo |
| CF1.4 | each app, in its phase | Five locales (en, de, es, fr, tr) with the words of `00` §11 (D12); "Add to AI Knowledge" / "Add to knowledge…" → **Make searchable by AI** (D13); locale parity check in the app's CI. One PR per app: Nextcloud in P1, OpenCloud in P2, ownCloud.online in P3. | app releases |
| CF1.5 | `synaplan-opencloud` | Chat about a file and research chat (SSE through the Go service to `/v1/chat/completions` with the exchanged token — needs CF2.2), media generation, binary **Save to Files** (D7). First step of P2; may run during P1 (row 34). | app release |

**Exit (five bullets, on the phase's app):**

1. P1: J-NC-1 re-walked on Nextcloud. P3: J-CF-2 walked on ownCloud.online.
   P2: chat, media and binary save walked on OpenCloud.
2. "Connected as …" visible in each app's personal settings in ten seconds.
3. Consent and connect copy in five locales; kind-specific per app.
4. Empty, error and flag-off states: no Connect button when the instance is
   not registered; a revoked key returns the person to Connect.
5. Host light and dark themes, narrow window.

---

## 4. CF2 — One identity

**Goal:** every per-person mode ends in one identity row; Synaplan shows and
stops every cloud connection; Nextcloud gains organisation login.

**User-flow:** J-CF-1 (P1 on Nextcloud if CF-S2 says yes, else first in P2
on OpenCloud), J-NC-1…3 and J-CF-2 (re-walks), J-CF-11 (identity half).
**Briefs:** `08` §5 · **Tests:** `09` §3.

| Step | Repo | Content | Class |
| ---- | ---- | ------- | ----- |
| CF2.1 | `synaplan` | `BPLATFORMINSTANCES` columns (`02` §9); `instances/self` adds `capabilities` (`contract` follows with CF5.10); `PATCH instances/self` (delivery URL, OIDC client id, app version, server id, `app_capabilities` — row 43); delivery-origin rule with the shared dev host list and the reachability probe (`03` §2); OpenCloud instances in Admin → People → Platform instances; private-address approval by hostname. | backend-only + ota-candidate |
| CF2.2 | `synaplan` | **D15, D8, D18.** Canonical identity rows (`02` §3.1): provisioning with the instance credential writes `(client, instance, uid)` and binds the key; `identities/adopt` for existing rows; `BEXTERNALIDENTITIES` columns of `02` §9 (all four) and `BMODE` on existing rows. `sso/status`, `sso/bind`; bearer attribution in `OidcBearerAuthenticator` (`azp` → instance → identity row, `BLASTSEEN`, `403 platform_disconnected`); OIDC bearer tokens accepted on `/v1/chat/completions` and `/v1/models`; audit rows. | backend-only |
| CF2.3 | `synaplan` | `sso/background-key`; scope `cloud:watch`; scope map for `/api/v1/cloud/*` (`02` §4.1). | backend-only |
| CF2.4 | `synaplan` | **D16.** `lifecycle` endpoint with the effects of `02` §7 (identities and keys now; watch effects arrive with CF5.1): the account goes only while that cloud alone manages it, and the generic admin delete answers `409 account_in_use` to API keys for an account the rule keeps (C18). | backend-only |
| CF2.5 | `synaplan` | **D9, D19.** Apps: mode-aware status for the Nextcloud and ownCloud.online cards from identity rows; feature line from `app_capabilities`; Turn off / Turn back on for organisation-login connections; Disconnect keeps the identity row as `revoked` (today it deletes it) so a reconnect can resume watches; `GET /api/v1/me/platform-links` extended; `POST …/{id}/turn-off`, `…/resume`. | ota-candidate + backend-only |
| CF2.6 | `synaplan-nextcloud` | **Only if CF-S2 says yes.** Organisation login: token through `user_oidc`, cache, **Check organisation login**, Connect card as fallback, bind on consent, the organisation-login row of the four-mode admin screen (W8). | app release |
| CF2.10 | `synaplan-nextcloud` | **Whatever CF-S2 answers.** Nextcloud identity basics: registration also in provision mode; `PATCH instances/self` (server id, `app_capabilities`; the delivery URL follows with the inbound endpoint in CF3.3); provisioning with the instance credential and the one-time adoption job (D15); user deletion calls `lifecycle` instead of the admin delete, disable / enable call `lifecycle` (D16); four-mode admin screen (W8). | app release |
| CF2.7 | `synaplan-opencloud` | Register as an instance (admin key or pending approval); consent card + bind (W1); lifecycle from user-deleted events (CF-S3); account extension "Synaplan" (CF-S1). Connect-your-account mode with the key store (CF-S3) — **cut line**, may move to CF6. | app release |
| CF2.8 | `synaplan-owncloud-online` | Lifecycle calls (delete, disable, enable) — `preDelete` stops calling the admin delete (D16); provisioning with the instance credential and the one-time adoption job (D15); four-mode admin copy with organisation login shown as "not available on ownCloud.online". | app release |
| CF2.9 | `synaplan` | **D17.** `BFILES.BSOURCEINSTANCE` from the credential; upload, overwrite and `check-stale` match (instance, remote id) (C19); older rows are scoped only where the owner has one identity for that client — up front by the migration (dry-run counts in the PR), later on first match. | backend-only |
| CF2.11 | `synaplan` (P2) | Apps: the `opencloud` card with the same mode-aware status, feature line and Turn off; built in P2 so the card never shows "not connected" to people who already use OpenCloud through their organisation login before CF2.7 binds them. | ota-candidate |

**Exit (five bullets, on the phase's cloud):**

1. P1: J-NC-1…3 re-walked on Nextcloud, the identity half of J-CF-11 walked,
   and J-CF-1 on Nextcloud if CF-S2 said yes; the reference client's `sso`
   profile binds, mints a background key and is turned off and on. P2: J-CF-1
   walked on OpenCloud. P3: J-CF-2 re-walked on ownCloud.online.
2. A connected cloud is found in ten seconds under Apps → Connected, with its
   mode and last use.
3. Turn off, Disconnect and Managed-by sentences in five locales, per mode.
4. Empty Apps state names where to start; flag off ⇒ no cloud cards, new
   routes 404; an app against an older core hides bind and consent changes
   (J-CF-12, identity part); a cloud whose app reports no features shows no
   feature line.
5. Dark + V2 + 320 px for the Synaplan cards; host themes for the apps.

---

## 5. CF3 — Files both ways — copy out, pick up

**Goal:** any Synaplan file reaches the person's cloud in one click, and any
run — chat or task — can pick up files or file lists from the cloud inside
what the person lets Synaplan read.

**User-flow:** J-CF-3, J-CF-4, J-CF-14. **Briefs:** `08` §6 · **Tests:** `09` §4.

| Step | Repo | Content | Class |
| ---- | ---- | ------- | ----- |
| CF3.1 | `synaplan` | `BCLOUDDELIVERIES`; `PlatformDestinationProvider`; `DestinationResult::pending()`; notify key (`03` §2: Ed25519 through `FederationSigner`, key pair encrypted in `BCONFIG`, `notify_keys` in `instances/self`, rotation command, shared test vectors); notify client (signed, delivery-origin rule of `03` §2); `identity_revoked` sent on disconnect, turn-off, `lifecycle(deleted)` and instance revocation; claim and result endpoints with the JSON of `03` §4; sweep that fails rows still `queued` after 10 minutes with code `unconfirmed`; `save_to_folder` accepts platform targets incl. `next_to`. | backend-only |
| CF3.2 | `synaplan` | `FilePushMenu.vue` lists linked clouds whose app reports `deliveries` (C21), with their landing folder; toast with **Open in …**; **Sent to** on the file detail; `en` "Send to cloud" → "Copy to cloud". | ota-candidate |
| CF3.3 | `synaplan-nextcloud` | Inbound endpoint that verifies every notification before it calls back (C20) and handles `delivery` and `identity_revoked`; delivery URL in `PATCH instances/self` with the admin field "Address Synaplan uses to reach this Nextcloud"; claim and write; notification; personal "Save files from Synaplan to"; **New → From Synaplan…**; Save to Files for every generated kind; own-write guard; reports `deliveries`. | app release |
| CF3.4 | `synaplan-owncloud-online` | Same (CF-S4). | app release |
| CF3.5 | `synaplan-opencloud` | Inbound endpoint behind an unprotected proxy route, with the same checks; claim and write through impersonation (CF-S3); binary save; From Synaplan (CF-S1); notification. | app release |
| CF3.6 | `synaplan` | **Pickup protocol** (`07` §4): `BCLOUDPICKUPS`, ticket in Redis (single use, 10 min), claim / listing / files / result endpoints, inline-then-background, step wait ≤ 10 min, partial results. **`folder_fetch`** capability and runner: list or fetch, files attached to the run message, linked clouds as folder channels with their readable folders. Read-scope mirror (`PUT /api/v1/platform-links/read-scope`, `BREADSCOPE`) and pre-check. `WebDavClient::list()` / `get()` for WebDAV channels (moved forward from CF6.1). | backend-only |
| CF3.7 | `synaplan` | Run card "Read 12 files from Nextcloud › Invoices/2026" (W9) in chat and task runs; refusal sentence with **Open in …**; "Synaplan may read: …" on the cloud's Apps card. | ota-candidate |
| CF3.8 | `synaplan-nextcloud` | Pickup handler: claim, resolve the selector in the person's view, scope check, listing, uploads, result (inline 25 s, rest in a background job). Read setting in personal settings (W11), right-click **Let Synaplan read this folder**, admin cap, scope report; reports `pickups` and `read_scope`. | app release |
| CF3.9 | `synaplan-owncloud-online` | Same (CF-S4). | app release |
| CF3.10 | `synaplan-opencloud` | Same through impersonation (CF-S3); read setting in the account extension, folder action (CF-S1). | app release |

**Exit (five bullets, on the phase's cloud):**

1. J-CF-3, J-CF-4 and J-CF-14 walked on the phase's cloud; in P1 the
   reference client also passes copies and pickups in the `sso` profile.
2. The copy is found in ten seconds (toast link, fixed folder, cloud
   notification, **Sent to**); the files a run read are named on its card.
3. Every failure code of `03` §6 and `07` §10 has its sentence in five
   locales.
4. Empty picker, unreachable cloud, outside-the-read-scope and flag-off (no
   linked clouds in the menu, no `folder_fetch` in the planner) states shown;
   a cloud whose app does not report `deliveries` or `pickups` is not offered
   (C21, J-CF-12).
5. Dark + V2 + 320 px for the menu, toast and run card; host themes for the
   picker and the read setting.

---

## 6. CF4 — Searchable state

**Goal:** the bird shows what the AI knows, in the file list where the
platform allows it and in the sidebar everywhere; searchable files follow
their source.

**User-flow:** J-CF-5. **Briefs:** `08` §7 · **Tests:** `09` §5.

| Step | Repo | Content | Class |
| ---- | ---- | ------- | ----- |
| CF4.1 | `synaplan` | `POST /api/v1/cloud/status` with the JSON of `04` §A.3, `GET /api/v1/cloud/files/{id}`, `POST /api/v1/platform-links/sources/events` with follow-the-source effects and the `reupload` answer (`04` §A.5); `BFILES.BKEEPSYNCED` and the upload field `keep_synced`; FeatureModule `cloud_files` (default off). | backend-only |
| CF4.2 | `synaplan` | Glyph kit (`04` §A.2: two base glyphs, five badges) drafted by the agent, approved by the product owner in review; the runtime-config override field. | ota-candidate |
| CF4.3 | `synaplan-nextcloud` | Row glyph with the batcher; list header; sidebar tab; Make-searchable dialog with "Keep it up to date"; source events from node events through the job list; re-uploads Synaplan asks for; reports `source_events`. | app release |
| CF4.4 | `synaplan-owncloud-online` | Same (CF-S4). | app release |
| CF4.5 | `synaplan-opencloud` | Sidebar panel and action-label state; source events from the event bus (CF-S3); searchable-files list in the Synaplan app view; upstream request for a row indicator (issue link in STATUS). | app release |

**Exit (five bullets, on the phase's cloud):**

1. J-CF-5 walked on the phase's cloud (OpenCloud through the sidebar unless
   CF-S1 found a row extension point); in P1 the reference client's `sso`
   profile also passes the status call with long ids and quoted etags.
2. A person sees the state of a file in ten seconds without opening Synaplan.
3. Every state sentence of `04` §A.1 in five locales.
4. Status call failure shows one quiet line; flag off or older core ⇒ no
   glyphs, no sidebar tab.
5. Glyphs meet WCAG AA in the host's light and dark themes.

---

## 7. CF5 — Watched folders and automations

**Goal:** a person watches a folder or a single file from the cloud, picks
what happens, finds every run in Synaplan, and can pause or stop from both
sides. In P1 the wave ends with the contract freeze (CF5.10).

**User-flow:** J-CF-6, J-CF-7, J-CF-8, J-CF-9, J-CF-10, J-CF-11 (full),
J-CF-12, J-CF-13, J-CF-15 (one file). **Briefs:** `08` §8 (build order CF5.1 → 5.2 → 5.7 → 5.8
→ 5.3 → apps) · **Tests:** `09` §6.

| Step | Repo | Content | Class |
| ---- | ---- | ------- | ----- |
| CF5.1 | `synaplan` | Schema `BCLOUDWATCHES`, `BCLOUDWATCHITEMS`; watch API (person) and the paginated, incremental watch read for the apps' index (instance); caps of 20 active watches per person and 1 000 per instance; folder and file targets; ledger idempotency; `file_event` trigger; upload and reconcile endpoints; loop guard; limits; working copies (hidden, `BFILES.BEXPIRESAT` 7 days, honoured by the ephemeral reaper — D20); the states and reason codes of `04` §B.2 verbatim, including `stopped`; `watches_changed` notify; lifecycle effects on watches; scheduler sweeps for stuck items. | backend-only |
| CF5.2 | `synaplan` | Presets → task templates: Translate, Summarize and mail me, Use one of my tasks; Keep-searchable sync; mail with links back. "Run my instruction" has no template — it pins a tried turn (CF5.8). | backend-only |
| CF5.3 | `synaplan` | Tasks → **Watched folders** tab (W7); task trigger row for `file_event`; run detail with its files and **Open in …**; knowledge-folder badge with **Stop syncing**; empty states. | ota-candidate |
| CF5.4 | `synaplan-nextcloud` | Watch dialog (W6, with its single-file variant) and the watch-permission card (`02` §4.2); the watch index (app table, incremental sync) for eyes and event matching; list header; sidebar watch section; job-list queue with quiet period; reconciliation job; admin policy (shared folders) and the background-jobs warning; Watched folders list on the app page; reports `watches`. | app release |
| CF5.5 | `synaplan-owncloud-online` | Same (CF-S4). | app release |
| CF5.6 | `synaplan-opencloud` | Same with the event bus and impersonation (CF-S3); folder context action; sidebar; app view list. | app release |
| CF5.7 | `synaplan` | **Files into runs** (`07` §2, §6): `SavedTaskRunner::run()` attaches the run's files to the run message; payload `files` + `set`; "Files in this run" block; file sets — core windowing for **New files together** (scheduler `tasks` lane), snapshots for **The whole folder**; run limits (50 files, 200 MB). Runner test: a payload without attachments fails closed (C15). | backend-only |
| CF5.8 | `synaplan` | **Try it, then automate** (`07` §7): `POST /api/v1/cloud/trials`, **Do this for every new file** (`from-message`) with the file-set question and the unattended file-work question (sets `BALLOWUNATTENDED`); pinning rejects a file-work script that names the test file; replay test on a differently named file (C17); runs of a watch that keeps a working folder are serialized. | backend-only + ota-candidate |
| CF5.9 | each app, in its phase | Dialog: **What should each run get?**, **Try it on a file / files** (upload with the person's credential, open the trial chat); whole-folder snapshot push; reports `trials` and `file_sets`. One PR per app: Nextcloud in P1, OpenCloud in P2, ownCloud.online in P3. | app releases |
| CF5.10 | `synaplan`, `synaplan-nextcloud` (P1, last) | **Contract v1 freeze** (`00` row 44): the fixtures in `backend/tests/Fixtures/cloud-apps-contract/` complete for every app-facing path, body, code, capability, notification and limit, with a README naming the source commit; contract tests in the core; `instances/self.contract = 1`; the fixtures vendored into `synaplan-nextcloud` with a checksum test. P2 and P3 vendor the same files in their first step. | backend-only + app release |

**Exit (five bullets, on the phase's cloud):**

1. J-CF-6 … J-CF-13 and J-CF-15 walked on the phase's cloud (OpenCloud
   reconcile-only if CF-S3 found no events; J-CF-13 where Compute is on); in
   P1 the reference client passes watches, file sets and trials in both
   profiles and CF5.10 is merged.
2. Every watch — on a folder or on a single file — and every run is found in
   ten seconds: eyes + sidebar in the cloud, Tasks → Watched folders in
   Synaplan, the app page list.
3. Preset consequence sentences (plural and singular), reasons and failure
   sentences in five locales.
4. Empty Watched folders state, failing file, paused, deleted target, cap
   reached and flag-off states shown; an older core shows no Watch menu
   (J-CF-12); with Compute off the dialog and the trial never mention file
   work.
5. Dark + V2 + 320 px for the Synaplan tab; host themes for the dialog and
   sidebar.

After the P1 exit: `cloud_files` on for new installs and an activation
migration for existing ones (row 46).
P2 and P3 switch nothing in the core.

---

## 8. CF6 — Beyond the apps (optional)

After P3. No briefs or test plans yet; an item gets both (in the shape of
`08` and `09`) when it is scheduled, never before.

| Step | Repo | Content |
| ---- | ---- | ------- |
| CF6.1 | `synaplan` | WebDAV feeder (`04` §D): scheduled scan on the `list()` / `get()` methods CF3.6 adds, same watch model and presets — closes the roadmap's "Folder watch" for clouds without the app |
| CF6.2 | `synaplan` + apps | Pull mode for copies and pickups for clouds Synaplan cannot reach (`03` §9, `07` §4): the app polls for queued tickets from a background job |
| CF6.3 | `synaplan-nextcloud` | Nextcloud Flow operation "Run a Synaplan task"; Assistant / TaskProcessing provider for summarize and translate |
| CF6.4 | `synaplan-owncloud-online` | Organisation login, if the hoster enables OIDC |
| CF6.5 | `synaplan` | "Searchable for my group" in the sidebar; layout-preserving translation |
| CF6.6 | `synaplan-opencloud` | Connect-your-account and accounts-by-administrator modes, if cut from CF2 |
