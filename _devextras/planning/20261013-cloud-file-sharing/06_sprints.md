# Spikes and sprints

Plan of record: [`00_master_plan.md`](./00_master_plan.md). No step starts
before §3 there is ticked. Every sprint with an `ota-candidate` step names its
journeys and carries the five exit bullets of the UX contract §6.

## 0. Order and gates

```text
 CF-S1…S5 ─┐
 CF0 ──────┴─► CF1 ─► CF2 ─┬─► CF3 ─┐
                           └─► CF4 ─┴─► CF5 ─► CF6 (optional)
```

A wave's app steps start once its core step is merged and released, so an app
release never depends on unreleased core behaviour (rollout §8, C1).

| Repo | Before every commit | Before every push / PR |
| ---- | ------------------- | ---------------------- |
| `synaplan` | `make ci-local` | `make test-e2e`; `make -C frontend test-e2e-layout` for nav or layout; after OpenAPI changes `make -C frontend generate-schemas` + `vue-tsc`; `node scripts/mobile-impact.mjs --base main --head HEAD` |
| `synaplan-nextcloud` | `make lint && make test` | CI `All Checks Passed` (from CF1.3); Playwright against `make dev-up` (from CF1.3) |
| `synaplan-owncloud-online` | `make lint && make check-types`; `make test` from CF1.2 | CI `All Checks Passed` (from CF1.2) |
| `synaplan-opencloud` | `make frontend-lint frontend-typecheck frontend-test-unit backend-test backend-lint` | `make frontend-test-e2e-dev` (dev server `:9201`); CI `All Checks Passed` |
| `synaplan-docs` | Render the changed page locally | — |

Classes for `synaplan` steps follow `.github/mobile-impact-policy.json`; new
frontend paths are added to it in the same PR (C12). App repo steps ship
through their own channel and are marked **app release**.

---

## 1. Spikes (parallel to CF0)

Each spike ends with a dated STATUS entry that answers its questions with
evidence (version, code pointer, a two-minute recording or a test). A "no"
answer moves the dependent step to its fallback, recorded in the same entry.

| Spike | Questions | Blocks | Fallback if "no" |
| ----- | --------- | ------ | ---------------- |
| **CF-S1 — OpenCloud web extension points** (8.1 SDK) | Row indicator or custom component in the resource list? Create-menu extension for "From Synaplan"? Context action on folders? `sidebarPanel` for files and folders? `accountExtension` for a settings page? User notifications from an extension? Private-link format `/f/<id>`? | CF3.5, CF4.5, CF5.6, CF2.7 | Sidebar + action label + Synaplan app view; file an upstream issue |
| **CF-S2 — Nextcloud organisation login** | Can an app get the login token or an exchanged token from `user_oidc` (events, version floor, `store_login_token`)? Does the exchanged token carry `azp`? Refresh behaviour? Works with Keycloak and with openDesk's Nubus? | CF2.6 | Nextcloud stays on Connect / Accounts by administrator; organisation login moves to CF6 |
| **CF-S3 — OpenCloud unattended access** | Service account or impersonation to read and write a user's files without a request? Event bus consumer for upload finished, move, trash, restore, user deleted — config and delivery guarantees? Unprotected proxy route for `/api/synaplan/inbound`? Persistent store for per-user keys (encryption)? Project spaces: events and writes? | CF2.7, CF3.5, CF4.5, CF5.6 | Copies only through "From Synaplan"; watches on OpenCloud reconcile-only; Connect mode to CF6 |
| **CF-S4 — ownCloud 11 Files APIs** | FileList plugin to decorate rows? Detail tab? New-file menu plugin? File hooks or dispatcher events (create, write, rename, delete, restore)? Notifications app? Background jobs and cron mode detection? Private link? Does a local `owncloud/server` image behave like BW-Tech's 11.0.10 — can the hoster give us a test tenant? | CF1.2, CF3.4, CF4.4, CF5.5 | Sidebar-only state; reconcile-only watches |
| **CF-S5 — Nextcloud Files APIs at scale** (30–34) | Inline row rendering with one batched call on a 5 000-file folder (virtual scroll)? Sidebar tab API across 30–34? List header for the current folder? New-file menu entry? Node events for group folders, external storage, trash and restore? Notifications? Private link `/f/<fileid>`? | CF3.3, CF4.3, CF5.4 | Glyph only on folders + sidebar; reconcile-only for external storage |

---

## 2. CF0 — Fix and align

**Goal:** the modes that are already shipped work end to end, and the docs say
what the code does.

**User-flow:** J-NC-1…3 (first walks, roadmap §3.1), J-CF-2 baseline on
ownCloud.online (provision mode).

| Step | Repo | Content | Class |
| ---- | ---- | ------- | ----- |
| CF0.1 | `synaplan` | **D1.** Exact scope branches: `chat` → `POST /api/v1/summary/generate`, `POST /v1/chat/completions`, `GET /v1/models`, `POST /api/v1/media/generate` and the media download path; `memories` → `GET /api/v1/config/memory-service/check`. Contract fixture of every path each app calls (`01` §2); allow tests for the fixture, deny tests for neighbours (`/v1/messages`, `/v1/images/generations`, `/api/v1/admin/*`). | backend-only |
| CF0.2 | `synaplan` | **D2.** `owncloud` in `File::SOURCES` and `INCOMING_SOURCES` (same as `nextcloud`); source label "ownCloud.online" and filter in the Library, five locales; data fix per decision 26 (dry-run count in the PR). | backend-only + ota-candidate |
| CF0.3 | `synaplan` | **D3.** Upload option `intake=direct`: with a `group_key`, the file skips Incoming. Default behaviour unchanged (C10). | backend-only |
| CF0.4 | `synaplan` | `check-stale` returns `vector_state` per item (additive). | backend-only |
| CF0.5 | `synaplan-docs`, `synaplan-nextcloud` | **D10, D11.** OpenCloud section of `docs/plugins.md` and the `docs/intro.md` bullet rewritten to the code (shared identity provider, personal space, manual updates). Nextcloud `docs/HOSTING_GUIDE.md` roadmap refreshed (stale handling shipped, scopes enforced). | docs |
| CF0.6 | `synaplan` | **D14.** More-Nextcloud STATUS: S2 merged, S3 absorbed here except the pairing-code fallback; roadmap row 5 "Folder watch" and "Nextcloud S3" point here. | docs |
| CF0.7 | all | First walks on local stacks: J-NC-1…3 (Nextcloud, link mode); ownCloud.online provision mode summarize / translate / chat / knowledge status after CF0.1 + CF0.2; OpenCloud summarize and knowledge with organisation login. Dated STATUS entries. | walk |

**Exit:** in provision and link mode a Nextcloud user summarizes, translates,
chats and generates an image without `insufficient_scope`; the ownCloud.online
knowledge dialog shows the real state; the Library labels ownCloud.online
files; walks recorded. Never cut.

---

## 3. CF1 — Parity floor

**Goal:** nothing in the Nextcloud 1.6 feature set is missing from
ownCloud.online or OpenCloud without a reason in `01` §5.2; all three apps
speak the core's words in five locales and have a CI gate.

**User-flow:** J-CF-2.

| Step | Repo | Content | Class |
| ---- | ---- | ------- | ----- |
| CF1.1 | `synaplan-owncloud-online` | Mode enum (`shared` / `provision` / `link`); link mode ported from Nextcloud (`PlatformLinkService`, `LinkController`, routes, personal settings page, admin **Register this instance**); consent gate on every AI surface (D5); MIME filters on the four actions (D6); per-user keys encrypted (D4); chat launcher on every page like Nextcloud. | app release |
| CF1.2 | `synaplan-owncloud-online` | PHPUnit suite mirroring Nextcloud's; `.github/workflows/ci.yml` with `All Checks Passed`; `AGENTS.md`; local ownCloud stack (Docker, fixed port, joins the Synaplan network) for walks and E2E (CF-S4). | app repo |
| CF1.3 | `synaplan-nextcloud` | CI workflow with `All Checks Passed`; `AGENTS.md`; per-user keys encrypted with transparent migration (D4); Playwright against `make dev-up` for J-NC-1 and summarize; user disabled / re-enabled listener (prepares CF2.4). | app release |
| CF1.4 | all three | Five locales (en, de, es, fr, tr) with the words of `00` §11 (D12); "Add to AI Knowledge" / "Add to knowledge…" → **Make searchable by AI** (D13); locale parity check in each CI. | app releases |
| CF1.5 | `synaplan-opencloud` | Chat about a file and research chat (SSE through the Go service to `/v1/chat/completions` with the exchanged token), media generation, binary **Save to Files** (D7). **Cut line** — may move after CF5. | app release |

**Exit (five bullets, app surfaces):**

1. J-CF-2 walked on ownCloud.online; J-NC-1 re-walked on Nextcloud.
2. "Connected as …" visible in each app's personal settings in ten seconds.
3. Consent and connect copy in five locales; kind-specific per app.
4. Empty, error and flag-off states: no Connect button when the instance is
   not registered; a revoked key returns the person to Connect.
5. Host light and dark themes, narrow window.

---

## 4. CF2 — One identity

**Goal:** every per-person mode ends in one identity row; Synaplan shows and
stops every cloud connection; Nextcloud gains organisation login.

**User-flow:** J-CF-1, J-CF-2 (re-walk), J-CF-11 (identity half).

| Step | Repo | Content | Class |
| ---- | ---- | ------- | ----- |
| CF2.1 | `synaplan` | Schema (`02` §9); `instances/self` adds `capabilities`; `PATCH instances/self` (delivery URL, OIDC client id, app version); OpenCloud instances in Admin → People → Platform instances; private-address approval dialog with stored addresses. | backend-only + ota-candidate |
| CF2.2 | `synaplan` | `sso/status`, `sso/bind`; bearer attribution in `OidcBearerAuthenticator` (`azp` → instance → identity row, `BLASTSEEN`, `403 platform_disconnected`); audit rows. | backend-only |
| CF2.3 | `synaplan` | `sso/background-key`; scope `cloud:watch`; scope map for `/api/v1/cloud/*` (`02` §4.1). | backend-only |
| CF2.4 | `synaplan` | `lifecycle` endpoint with the effects of `02` §7 (identities and keys now; watch effects arrive with CF5.1). | backend-only |
| CF2.5 | `synaplan` | Apps: `opencloud` card; mode-aware status for the three cloud cards from identity rows (D9); Turn off / Turn back on / Disconnect with consequence sentences; `GET /api/v1/me/platform-links` extended; `POST …/{id}/resume`. | ota-candidate + backend-only |
| CF2.6 | `synaplan-nextcloud` | Organisation login (after CF-S2): token through `user_oidc`, cache, **Check organisation login**, Connect card as fallback, bind on consent, lifecycle calls, four-mode admin screen (W8). | app release |
| CF2.7 | `synaplan-opencloud` | Register as an instance (admin key or pending approval); consent card + bind (W1); lifecycle from user-deleted events (CF-S3); account extension "Synaplan" (CF-S1). Connect-your-account mode with the key store (CF-S3) — **cut line**, may move to CF6. | app release |
| CF2.8 | `synaplan-owncloud-online` | Lifecycle calls (delete, disable, enable); four-mode admin copy with organisation login shown as "not available on ownCloud.online". | app release |

**Exit (five bullets):**

1. J-CF-1 walked on OpenCloud (and Nextcloud when CF-S2 is green); J-CF-2
   re-walked; identity half of J-CF-11 walked.
2. A connected cloud is found in ten seconds under Apps → Connected, with its
   mode and last use.
3. Turn off, Disconnect and Managed-by sentences in five locales, per mode.
4. Empty Apps state names where to start; flag off ⇒ no cloud cards, new
   routes 404; an app against an older core hides bind and consent changes
   (J-CF-12, identity part).
5. Dark + V2 + 320 px for the Synaplan cards; host themes for the apps.

---

## 5. CF3 — Copy to cloud

**Goal:** any Synaplan file reaches the person's cloud in one click from
Synaplan, or from inside the cloud.

**User-flow:** J-CF-3, J-CF-4.

| Step | Repo | Content | Class |
| ---- | ---- | ------- | ----- |
| CF3.1 | `synaplan` | `BCLOUDDELIVERIES`; `PlatformDestinationProvider`; `DestinationResult::pending()`; notify client (registered URL, host match, https, `SsrfGuard` unless approved); claim and result endpoints; sweep for `unconfirmed` in the scheduler; `save_to_folder` accepts platform targets incl. `next_to`. | backend-only |
| CF3.2 | `synaplan` | `FilePushMenu.vue` lists linked clouds with their landing folder; toast with **Open in …**; **Sent to** on the file detail; `en` "Send to cloud" → "Copy to cloud". | ota-candidate |
| CF3.3 | `synaplan-nextcloud` | Inbound endpoint; claim and write; notification; personal "Save files from Synaplan to"; **New → From Synaplan…**; Save to Files for every generated kind; own-write guard. | app release |
| CF3.4 | `synaplan-owncloud-online` | Same (CF-S4). | app release |
| CF3.5 | `synaplan-opencloud` | Inbound endpoint behind an unprotected proxy route; claim and write through impersonation (CF-S3); binary save; From Synaplan (CF-S1); notification. | app release |

**Exit (five bullets):**

1. J-CF-3 and J-CF-4 walked on all three clouds.
2. The copy is found in ten seconds: toast link, fixed folder, cloud
   notification, **Sent to**.
3. Every failure code of `03` §6 has its sentence in five locales.
4. Empty picker, unreachable cloud and flag-off (no linked clouds in the menu)
   states shown.
5. Dark + V2 + 320 px for the menu and toast; host themes for the picker.

---

## 6. CF4 — Searchable state

**Goal:** the bird shows what the AI knows, in the file list where the
platform allows it and in the sidebar everywhere; searchable files follow
their source.

**User-flow:** J-CF-5.

| Step | Repo | Content | Class |
| ---- | ---- | ------- | ----- |
| CF4.1 | `synaplan` | `POST /api/v1/cloud/status`, `GET /api/v1/cloud/files/{id}`, `POST /api/v1/platform-links/sources/events` with follow-the-source effects; FeatureModule `cloud_files` (default off). | backend-only |
| CF4.2 | `synaplan` + design | Glyph kit (`04` §A.2) and the runtime-config override field. | ota-candidate |
| CF4.3 | `synaplan-nextcloud` | Row glyph with the batcher; list header; sidebar tab; Make-searchable dialog with "Keep it up to date"; source events from node events through the job list. | app release |
| CF4.4 | `synaplan-owncloud-online` | Same (CF-S4). | app release |
| CF4.5 | `synaplan-opencloud` | Sidebar panel and action-label state; source events from the event bus (CF-S3); searchable-files list in the Synaplan app view; upstream request for a row indicator (issue link in STATUS). | app release |

**Exit (five bullets):**

1. J-CF-5 walked on all three (OpenCloud through the sidebar unless CF-S1
   found a row extension point).
2. A person sees the state of a file in ten seconds without opening Synaplan.
3. Every state sentence of `04` §A.1 in five locales.
4. Status call failure shows one quiet line; flag off or older core ⇒ no
   glyphs, no sidebar tab.
5. Glyphs meet WCAG AA in the host's light and dark themes.

---

## 7. CF5 — Watched folders and automations

**Goal:** a person watches a folder from the cloud, picks what happens, finds
every run in Synaplan, and can pause or stop from both sides.

**User-flow:** J-CF-6, J-CF-7, J-CF-8, J-CF-9, J-CF-10, J-CF-11 (full),
J-CF-12.

| Step | Repo | Content | Class |
| ---- | ---- | ------- | ----- |
| CF5.1 | `synaplan` | Schema `BCLOUDWATCHES`, `BCLOUDWATCHITEMS`; watch API (person) and watch read (instance); ledger idempotency; `file_event` trigger; upload and reconcile endpoints; loop guard; limits; working copies (hidden, 7-day retention); states and reasons; `watches_changed` notify; lifecycle effects on watches; scheduler sweeps for stuck items. | backend-only |
| CF5.2 | `synaplan` | Presets → task templates: Translate, Summarize and mail me, Run my instruction, Use one of my tasks; Keep-searchable sync; placeholders `{file.name}`, `{file.path}`, `{file.link}`; mail with links back. | backend-only |
| CF5.3 | `synaplan` | Tasks → **Watched folders** tab (W7); task trigger row for `file_event`; run detail with the file and **Open in …**; knowledge-folder badge with **Stop syncing**; empty states. | ota-candidate |
| CF5.4 | `synaplan-nextcloud` | Watch dialog (W6) and the watch-permission card (`02` §4.2); eyes from the watch cache; list header; sidebar watch section; job-list queue with quiet period; reconciliation job; admin policy (shared folders) and cron warning; Watched folders list on the app page. | app release |
| CF5.5 | `synaplan-owncloud-online` | Same (CF-S4). | app release |
| CF5.6 | `synaplan-opencloud` | Same with the event bus and impersonation (CF-S3); folder context action; sidebar; app view list. | app release |

**Exit (five bullets):**

1. J-CF-6 … J-CF-12 walked on all three clouds (OpenCloud reconcile-only if
   CF-S3 found no events).
2. Every watch and every run is found in ten seconds: eyes + sidebar in the
   cloud, Tasks → Watched folders in Synaplan, the app page list.
3. Preset consequence sentences, reasons and failure sentences in five
   locales.
4. Empty Watched folders state, failing file, paused and flag-off states
   shown; an older core shows no Watch menu (J-CF-12).
5. Dark + V2 + 320 px for the Synaplan tab; host themes for the dialog and
   sidebar.

After the walks: `cloud_files` on for new installs and an activation migration
for existing ones (decision 22).

---

## 8. CF6 — Beyond the apps (optional)

| Step | Repo | Content |
| ---- | ---- | ------- |
| CF6.1 | `synaplan` | WebDAV feeder (`04` §D): `WebDavClient::list()` / `get()`, scheduled scan, same watch model and presets — closes the roadmap's "Folder watch" for clouds without the app |
| CF6.2 | `synaplan` + apps | Pull-mode copies for clouds Synaplan cannot reach (`03` §9) |
| CF6.3 | `synaplan-nextcloud` | Nextcloud Flow operation "Run a Synaplan task"; Assistant / TaskProcessing provider for summarize and translate |
| CF6.4 | `synaplan-owncloud-online` | Organisation login, if the hoster enables OIDC |
| CF6.5 | `synaplan` | "Searchable for my group" in the sidebar; layout-preserving translation |
| CF6.6 | `synaplan-opencloud` | Connect-your-account and accounts-by-administrator modes, if cut from CF2 |
