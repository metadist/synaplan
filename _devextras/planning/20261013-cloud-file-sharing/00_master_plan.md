# Cloud file sharing — Nextcloud, ownCloud.online and OpenCloud on one level

| | |
| - | - |
| **Status** | Plan, 2026-10-13; second review the same day (agent briefs, functional tests, local environments — `08`–`10`); the six Copilot findings on [#2467](https://github.com/metadist/synaplan/pull/2467) folded in the same day; third review 2026-10-14 for the Nextcloud-first order (STATUS review log). **All 46 decision rows of §3 agreed 2026-10-14** (row 41 with lower caps). Verified by code reading against `synaplan` `main` `5487e2678` (5.4.0), `synaplan-nextcloud` `400ec02` (1.6.0), `synaplan-owncloud-online` `3b916f6` (1.0.0), `synaplan-opencloud` `5cc1351` (v1.0.0). No live walk yet — the first walk is step CF0.7. |
| **Delivery order** | Three phases, one cloud at a time (row 42): **P1** Synaplan core + Nextcloud through CF0–CF5, ending with the core API frozen as contract v1; **P2** OpenCloud; **P3** ownCloud.online. §6. P1 ships in four milestone releases and ends with the Nextcloud release, then a **break** until a dated "P2 go" (row 48, [`11`](./11_nextcloud_release.md), finalized 2026-10-19). |
| **How it is built** | One brief per step for a coding agent ([`08`](./08_agent_briefs.md)), three test layers per sprint, run per phase ([`09`](./09_functional_tests.md)), pinned local stacks ([`10`](./10_local_environments.md)), a reference client that plays the app side of every protocol (row 45). Environment briefs E-1…E-4 and E-6 run before CF0.7, E-9 before CF2.1. |
| **Repos** | `synaplan` (core API, Library, Tasks, Apps), `synaplan-nextcloud`, `synaplan-owncloud-online`, `synaplan-opencloud`, `synaplan-docs` |
| **User-facing names** | **Searchable by AI** (bird) · **Watch with Synaplan** / **Watched folders** (bird with eyes) · **Copy to cloud** · **Organisation login** · **Connect your Synaplan account**. Full table in §11. |
| **Binding contracts** | UX rules U1–U12 ([`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md)), AGENTS.md "Perfect UX & Stability", Wave 6 decision §0 #5 "do not invent a fourth auth scheme" ([`../20260917-backend-integrations/00_master_plan.md`](../20260917-backend-integrations/00_master_plan.md)), Galera migration rules, API-key scope enforcement (`backend/src/Security/ApiKeyScope.php`). |
| **Flags** | Existing `PLATFORM_LINKS.ENABLED` for identity and copies; new FeatureModule `cloud_files` for file state and watches (default off until the CF5 walks pass). The cloud apps feature-detect the core through `instances/self` capabilities. |
| **Class** | Core PHP: `backend-only`. Core Library / Apps / Tasks UI: `ota-candidate`. The three cloud apps ship through their own channels (Nextcloud App Store, ownCloud.online package, container image). Nothing is `store-required`. |

| File | Content |
| ---- | ------- |
| `00_master_plan.md` (this) | Pitch, what "one level" means, decisions, architecture, parity target, waves, invariants, rollout |
| [`01_baseline_and_gaps.md`](./01_baseline_and_gaps.md) | Verified state of the three apps and the core, 20 defects, gap matrix, platform constraints |
| [`02_identity.md`](./02_identity.md) | Goal 2 — four connection modes, one identity row, credentials, lifecycle |
| [`03_send_to_cloud.md`](./03_send_to_cloud.md) | Goal 1 — copy Synaplan files into a linked cloud; add from Synaplan inside the cloud |
| [`04_file_state_and_watches.md`](./04_file_state_and_watches.md) | Goal 3 — the bird, the sidebar, watched folders, automations |
| [`05_journeys_and_wireframes.md`](./05_journeys_and_wireframes.md) | Journeys J-CF-1…15, wireframes, copy in five locales |
| [`06_sprints.md`](./06_sprints.md) | Phases P0–P3, spikes CF-S1…S5 and sprints CF0–CF6 |
| [`07_files_into_runs.md`](./07_files_into_runs.md) | Files, not paths: what a run receives, fetching more files (`folder_fetch` + pickup), what Synaplan may read, file work in the sandbox, try it on a file |
| [`08_agent_briefs.md`](./08_agent_briefs.md) | The work orders: rules for an agent, definition of done, one brief per spike and step (repo, branch, files, build list, tests, acceptance) |
| [`09_functional_tests.md`](./09_functional_tests.md) | Three test layers per sprint: unit and contract, integration scripts, browser walks with expected results; fixtures, accounts, regression set |
| [`10_local_environments.md`](./10_local_environments.md) | Local stacks for Synaplan, Keycloak, Nextcloud, OpenCloud, ownCloud.online: pins, requirements, inventory of what was found, cleanup, update briefs E-1…E-9, `env-check.sh` |
| [`11_nextcloud_release.md`](./11_nextcloud_release.md) | P1 entry point: milestones M1–M4, engineering standard, test stacks, regression guards, release checklist, the break |
| [`STATUS.md`](./STATUS.md) | Step ledger, decision log, review log |

---

## 0. The pitch

> Ada works in Nextcloud. She right-clicks the folder *Incoming-DE*, picks
> **Watch with Synaplan → Translate to English**, reads one sentence and
> confirms. The folder now shows a small bird with eyes. A colleague drops a
> contract in; two minutes later `contract.en.docx` is in *Incoming-EN*, and in
> Synaplan under **Tasks → Watched folders** the row says "Last: 09:12 · 1
> translated". The specs she made searchable show a bird; when she edits one,
> the bird says "Changed — updating" and catches up on its own. A chart
> Synaplan drew for her is one click away from her Nextcloud: **Copy to cloud →
> Nextcloud**. She never typed a key — her Nextcloud and Synaplan share the
> organisation login. Her colleague on ownCloud.online and her partner on
> OpenCloud see the same screens, words and icons.

**The concept in three sentences.** Your cloud and Synaplan become one
workplace: files the AI can search carry a bird, folders Synaplan watches
carry a bird with eyes, and whatever Synaplan makes for you is one click away
from your cloud folder. You sign in once — with your organisation login or by
connecting your Synaplan account. Every watch, copy and result can be seen and
stopped from both sides.

---

## 1. What was asked, and what "one level" means

Asked on 2026-10-13:

1. Send files from Synaplan's generated files into the cloud space.
2. Consolidated OIDC login and streamlined user handling ("complicated, but
   seems to work fine in Nextcloud").
3. Synchronized file handling for AI use: show that a file is RAG-indexed
   (little bird), watch files and directories (bird with eyes), and run
   automations on watched entities — RAG it, translate it, summarize it in 4–5
   sentences and mail me, call a specific prompt with the path. Where to manage
   the automations was left open; §3 row 17 answers it.

Clarified in review the same day: a path inside a prompt means nothing on its
own. A run must receive **files or file lists** from the cloud, fetch more
files when it needs them, and process them on Synaplan automatically — also as
file work in the sandbox with its tools. "Call a specific prompt with the
path" therefore reads as **run an instruction on the files**; the path is a
label or a selector, never the input ([`07`](./07_files_into_runs.md), §3 rows
27–32).

"One level" in this plan:

- **Same features, words, icons and failure copy** in all three apps. A
  platform may lack a feature only with a named technical reason in
  [`01`](./01_baseline_and_gaps.md) §5.
- **Nextcloud 1.6 is the floor.** It is the most complete app, but it also has
  no file-list state, no watching, no organisation login, no CI and one
  locale. The three goals lift all three apps above it.
- **Synaplan owns the state.** File state, watches, runs and copies live in the
  core; the apps show them where the files are. One runs ledger, one task
  editor, one approvals inbox (U12).

---

## 2. Verified baseline in one table

Detail and evidence: [`01_baseline_and_gaps.md`](./01_baseline_and_gaps.md).

| Area | Nextcloud 1.6.0 | ownCloud.online 1.0.0 | OpenCloud 1.0.0 | Synaplan core |
| ---- | --------------- | --------------------- | --------------- | ------------- |
| Summarize / translate | yes | yes | yes | `/api/v1/summary/generate` |
| Make searchable (add, update, remove, changed) | yes, inside a dialog | yes — but the status never matches (D2) | yes, inside a dialog | `source_id` / `source_etag` / `check-stale` / `mark-stale` / `overwrite` |
| Chat about a file, research chat, media | yes | yes | **no** | `/v1/chat/completions`, `/api/v1/media/generate` |
| Save generated files into the cloud | media → `Synaplan/<Kind>/` | media → `Synaplan/<Kind>/` | **text only, 2 MiB** | `POST /api/v1/files/{id}/send` with a WebDAV connection |
| Login modes | shared · provision · link | shared · provision | shared key · organisation login (token exchange) | API key, platform links, OIDC bearer |
| Personal settings / consent everywhere | yes / yes | no / chat only | no / no | Apps cards for Nextcloud + ownCloud only |
| File-list state, sidebar, watching, automations | **no** | **no** | **no** | Tasks (schedule, mail, webhook), no file trigger |
| Locales / CI / tests | de only / none / PHPUnit | none / none / none | none / full CI / Go + Vitest + Playwright | five locales, full CI |

Defects that block the floor today (detail in `01` §4):

- **D1** — per-user Nextcloud and ownCloud.online keys (`chat files rag`) are not
  mapped onto `/api/v1/summary/generate`, `/v1/chat/completions`,
  `/api/v1/media/generate`, `/api/v1/config/memory-service/check`. By code
  reading, summarize, translate, research chat and media answer `403
  insufficient_scope` in the provision and link modes.
- **D2** — `owncloud` is not in `File::SOURCES`; uploads are stored as
  `web_upload`, so ownCloud.online's knowledge status always reads "not
  searchable".
- **D3** — every `nextcloud` / `opencloud` upload lands in Incoming, even when
  the user picked a knowledge folder; a synced folder would flood Incoming.

---

## 3. Decision checklist — a brief starts when every row it cites is agreed

All 46 rows agreed on 2026-10-14 by the product owner: rows 1–40 and 43–46
as proposed, row 41 with lower caps (20 active watches per person, 1 000 per
instance), row 42 (delivery order), and the adjustments row 42 made to rows
33–35. A brief starts once the briefs it depends on are merged. Rows 47–49
were added and agreed by the product owner on 2026-10-19.

| # | Decision | Proposed default | Agree? |
| - | -------- | ---------------- | ------ |
| 1 | **Scope** | The three goals plus a parity floor (Nextcloud 1.6 features in all three apps). Same words, icons, failure copy. A platform gap only with a reason in `01` §5. | yes |
| 2 | **Source of truth** | Synaplan owns file state, watches, runs and copies. The apps keep caches and work queues, never a second copy of the rules. | yes |
| 3 | **Connection modes** | Four modes with the same names in all three admin screens: **Organisation login**, **Connect your Synaplan account**, **Accounts created by the administrator**, **One shared account**. Nextcloud's stored `shared` / `provision` / `link` values stay. | yes |
| 4 | **One identity row** | Every per-person mode ends in `BEXTERNALIDENTITIES (client, instance, platform user id)`. Organisation-login users get theirs through a **bind** call on first use, checked against the instance's registered OIDC client (`azp`). | yes |
| 5 | **Credentials** | Interactive: the person's own (organisation login token, or the scoped key from linking / provisioning). Unattended (watch uploads, reconciliation): the person's scoped key — organisation-login users get a **background key** when they turn on their first watch. The instance credential only for instance operations (claim a copy, fulfil a pickup Synaplan asked for, report source changes and read scope, read watch descriptions, bind, adopt a provisioned person with that person's key, report lifecycle). No fourth scheme (Wave 6 §0 #5). | yes |
| 6 | **Instance registration** | All three clouds register as platform instances, OpenCloud too. `BPLATFORMINSTANCES` gains delivery URL, OIDC client id, capabilities and the cloud's own server id. **Schema ask recorded** (Galera-safe `ADD COLUMN IF NOT EXISTS`). | yes |
| 7 | **Nextcloud organisation login** | Through `user_oidc` token exchange, once spike CF-S2 confirms the API and version floor. People without an OIDC session (local admin) see the Connect card. | yes |
| 8 | **ownCloud.online** | Connect, accounts-by-administrator and shared now. Organisation login only when the hoster enables OIDC for it (v2). | yes |
| 9 | **OpenCloud** | Consent card, bind and background key now. Connect-your-account for clouds with their own identity provider (key store per CF-S3). Accounts-by-administrator is a cut line. | yes |
| 10 | **Consent** | One first-use card in all three apps, same sentence (`05` §3) — organisation-login users included. No Synaplan account is created silently by a file action. | yes |
| 11 | **Copy protocol** | **Notify, then fetch.** Synaplan POSTs a one-time copy id to the registered delivery URL, signed with its notify key (row 37); the app verifies it, claims it with its instance credential, writes the file and answers in the same request (≤ 30 s); a result callback covers slow writes. A cloud Synaplan cannot reach gets an honest failure; pull mode is v2. An instance a Synaplan admin **approved** may sit on a private address (approval = allowlist, said in the approval dialog); all other targets keep the `SsrfGuard` policy. The WebDAV folder app stays the path without an installed app. | yes |
| 12 | **Where copies land** | `Synaplan/<Documents\|Images\|Audio\|Video\|Calendar>` in the person's home — the folders the apps already use for "Save to Files" — or the folder the person picked in the cloud's own settings with the native folder picker. Task outputs may say "next to the original". Never a typed path in Synaplan. | yes |
| 13 | **Indicator glyphs** | Bird = searchable by AI, with a small state badge (updating · changed · could not). Bird with eyes = watched. Icon + tooltip + `aria-label`, never colour alone. Same set in all three apps; a white-labelled instance may replace both through runtime config. | yes |
| 14 | **Indicator data** | One batched status call per folder view (`POST /api/v1/cloud/status`, built on `check-stale`, adding vector state, knowledge folder and watch coverage), cached ≤ 60 s. No local mirror table in v1. | yes |
| 15 | **Follow the source** | A searchable file follows its cloud file: change → "Changed", re-added automatically when "Keep it up to date" is on (default on); delete → out of answers and the Library at once, the copy kept 7 days so a restore brings it back, then deleted. The app reports these as instance-level source events. | yes |
| 16 | **Watch actions** | A watch looks at one folder or one file; a file watch reacts to new versions of that file only (J-CF-15). Two kinds: **Keep searchable** (sync into a knowledge folder, no task) and **Run a task** (one task per watch). Presets: Keep searchable · Translate · Summarize and mail me · Run my instruction (on the files, tried first — row 32) · Use one of my tasks. | yes |
| 17 | **Where automations live** | Created where the files are (right-click → **Watch with Synaplan…**). Managed, edited and audited in Synaplan: **Tasks → Watched folders** (beside Watched pages), the task's trigger row, the knowledge-folder badge. No automation editor inside the clouds, no Nextcloud Flow in v1. | yes |
| 18 | **Event model** | App events (Nextcloud node events, ownCloud hooks, OpenCloud event bus) with a 60 s quiet period, plus reconciliation (hourly default) for missed events. A new watch offers "also the N files already there" — on for Keep searchable, off for task presets. Nextcloud / ownCloud watches need system cron; the admin screen says so. | yes |
| 19 | **Idempotency and loops** | One run per (watch, file id, version). A watch never fires on a file Synaplan wrote. Every run ends in a terminal state with one sentence. | yes |
| 20 | **Task inputs** | Files a task watch reads are working copies: hidden from the Library, deleted after the run, kept 7 days for retries — unless the watch also keeps them searchable. Keep-searchable files skip Incoming and go straight into the chosen knowledge folder. | yes |
| 21 | **Shared folders** | A watch runs as its owner. Everyone with access sees the eyes and the one-sentence description. Only people who may edit the folder can create one; admins can turn watches on shared folders off. Absent in One-shared-account mode. | yes |
| 22 | **Flags** | Identity and copies reuse `PLATFORM_LINKS.ENABLED`. File state and watches are the FeatureModule `cloud_files` — default off until the CF5 walks pass, then on for new installs plus an activation migration (2026-09-25 decision 3 pattern). *Timing replaced by row 46 on 2026-10-14: on after the P1 exit.* | yes |
| 23 | **OpenCloud chat parity** | OpenCloud gets chat about a file, research chat and media like Nextcloud (CF1.5). Cuttable to after CF5. | yes |
| 24 | **Locales** | All three apps ship en, de, es, fr, tr with the core's words and a parity check in CI. | yes |
| 25 | **CI** | Nextcloud and ownCloud.online get GitHub CI with an `All Checks Passed` gate and an `AGENTS.md`, like OpenCloud and the desktop repo. | yes |
| 26 | **ownCloud data fix** | A one-off migration relabels old ownCloud.online uploads as `owncloud`: rows with `BSOURCE = 'web_upload'` and a `BSOURCEID` whose owner has an `owncloud` identity row — after a dry-run count in the PR. Alternative: the app re-uploads on the next Update. | yes |
| 27 | **Files, not paths** | Every run works on Synaplan files attached to its message (`Message::addFile()`), pushed by the watch or picked up from the cloud. A path is a label (provenance, links, output names) or a selector that a pickup turns into files first — never the input of a processing step. The `{file.path}` placeholders of the first draft are withdrawn. | yes |
| 28 | **What each run gets** | Task watches choose: **The new file** (default) · **New files together** (window 10 min from the first file, max 50) · **The whole folder** (at most once per window, listing first, unchanged files never re-sent). All three are pushed by the app, so watches keep working when Synaplan cannot reach the cloud. Windowing lives in the core. | yes |
| 29 | **Fetching during a run** | New read-class capability **`folder_fetch`** (list or fetch by folder, pattern, changed-since) for tasks and chat. Linked clouds answer through a **pickup**: notify-then-fetch in reverse — a single-use ticket bound to the person, the selector and the checked read scope; the app uploads into the ticket with its instance credential; listing first; inline ≤ 25 s, then background; the step waits ≤ 10 min and reports partial results honestly. WebDAV folders are read directly. | yes |
| 30 | **What Synaplan may read** | Set by the person **in the cloud**: only folders I watch (default) · also folders I allow (right-click → **Let Synaplan read this folder**) · all my files. The app enforces it and reports it to Synaplan, which pre-checks, tells the planner and shows it on the Apps card. Admin cap per app (default: folders people allow). | yes |
| 31 | **File work on cloud files** | Watch tasks and chats may use file work (`code_run`) on the attached files where Compute is on: Python / Node images with their libraries, no network, Compute quotas. Unattended file work asks once at creation and sets the task's existing "allow unattended" flag; otherwise each run waits in Approvals. Pinned scripts process whatever is mounted, never a test file's name. | yes |
| 32 | **Try it, then automate** | "Run my instruction" is tried on one real file first: the app uploads it with the person's credential, Synaplan opens a trial chat, the person checks the result; **Do this for every new file** pins exactly that turn's steps into the watch task. The same button appears under any chat whose files came from one cloud folder. | yes |
| 33 | **ownCloud.online priority** | ownCloud.online stays a full target at parity with Nextcloud, delivered in P3 (row 42). CF-S4 runs in P1, before the contract freeze, on the host install (BW-Tech checkout at 11.0.11 + 39 commits) and asks the hoster which version it runs and for a test tenant. E-5, the first brief of P3, pins `OCO_TAG` to that version (default `v11.0.10`, the version the app README names) and becomes the reference; without a tenant the STATUS entry says so. *Amended 2026-10-14 by row 42.* | yes |
| 34 | **OpenCloud chat timing** | CF1.5 (chat about a file, research chat, media, binary save) is the first step of P2. It needs OIDC bearer tokens on `/v1/chat/completions` (CF2.2) and adds nothing to the contract, so it may run during P1 when a second agent is free. *Amended 2026-10-14 by row 42.* | yes |
| 35 | **If capacity is short in CF2** | In P1, Nextcloud organisation login (CF2.6) is the only conditional step; it follows CF-S2. In P2, OpenCloud's registration, consent, bind and lifecycle are not cut; its Connect-your-account mode (CF2.7's cut line) may move to CF6. *Amended 2026-10-14 by row 42.* | yes |
| 36 | **Dev-mode test hooks in the apps** | Three admin-CLI-only switches per app (`dev_hold_inbound`, `dev_mute_events`, `dev_allow_self_write`) and two `APP_ENV=dev\|test`-only core values (`CLOUD_DELIVERY_TTL_SEC`, `CLOUD_DELIVERY_UNCONFIRMED_SEC`) so the integration scripts can test the pending, reconcile and loop-guard paths (`08` §0.8). Never visible in a UI. | yes |
| 37 | **Notification proof** | Every notification to an app (`delivery`, `pickup`, `watches_changed`, `identity_revoked`) is signed with an installation-wide Ed25519 notify key: the existing `FederationSigner`, the key pair encrypted in `BCONFIG` like `FederationIdentityStore`. The app gets the public key with its instance credential (`instances/self`) and checks signature, instance, time (± 5 min) and a ten-minute list of seen ids before it calls Synaplan; anything else gets `401`, no call back, and a per-address rate limit (`03` §2). Not chosen: rate limits alone (each forged request would still cost a call to Synaplan) and an HMAC per instance (Synaplan would have to keep every instance secret readable). | yes (2026-10-14) |
| 38 | **Deleting a person in one cloud** | Revokes that identity and the keys bound to it and stops its watches. The Synaplan account goes with it only while that cloud alone manages it: provisioned for this identity, no other active identity, no password of its own, not an administrator (`02` §7). For an account the rule keeps, the generic admin delete answers `409 account_in_use` when an API key calls it — the call today's app releases make. | yes (2026-10-14) |
| 39 | **Provisioned identities** | Provisioning writes the canonical row when the call carries the instance credential: client and instance from the credential, the bare platform user id, the minted key bound to the row. Rows written the old way are adopted by a one-time job in each app — instance credential **and** the person's key on each call (`02` §3.1). A row no app adopts keeps today's behaviour. | yes (2026-10-14) |
| 40 | **Files remember their cloud** | `BFILES.BSOURCEINSTANCE` names the platform instance a cloud file came from; upload, overwrite, status, source events and watches match (instance, remote id) (C19). The instance comes from the credential, never from the request. A row written before (no instance) is matched — and given the instance — only when its owner has no other identity for that client; the migration does this up front, the PR shows the counts, and rows of owners with two instances of one client stay unscoped. **Schema ask recorded.** | yes (2026-10-14) |
| 41 | **Watch index** | Each app keeps a local index of the active watches on its instance (eyes for everyone, event matching), filled from a paginated, incremental instance call (≤ 500 per page, a `since` cursor with tombstones, `ETag`). Caps bound it: **20 active watches per person, 1 000 per instance** (installation-wide `BCONFIG` `CLOUD_FILES.WATCHES_PER_PERSON` and `CLOUD_FILES.WATCHES_PER_INSTANCE`; a Synaplan admin may change them). CF-S3, CF-S4 and CF-S5 measure sync and matching at the cap (`04` §B.7, §B.8). *Caps lowered from the proposed 50 / 5 000 by the product owner, 2026-10-14.* | yes (2026-10-14, lower caps) |
| 42 | **Delivery order** | Three phases, one cloud at a time. **P1:** the Synaplan core and the Nextcloud app through CF0–CF5, tested end to end. **P2:** OpenCloud. **P3:** ownCloud.online. The core API the apps use is completed in P1 and frozen at its exit (row 44); P2 and P3 build on it. All five spikes answer before the freeze, because their answers can change the core. The end state of rows 1–36 does not change. | yes (product owner, 2026-10-14) |
| 43 | **What each app supports** | Each app reports its features with its instance credential (`PATCH instances/self` → `app_capabilities`: `deliveries`, `pickups`, `read_scope`, `source_events`, `watches`, `trials`, `file_sets`). Every Synaplan surface that offers a cloud — Copy to cloud, folder channels for `folder_fetch`, the Watched-folders empty state, the Apps card's feature line — offers only clouds whose app reports the feature. A cloud on an older app release never shows a dead control while another cloud already has the feature. | yes (2026-10-14) |
| 44 | **Cloud-apps contract v1** | At the P1 exit the app-facing API is frozen as contract v1: every path, body, status and error code, capability name, notification format and limit, written as fixtures in `backend/tests/Fixtures/cloud-apps-contract/` (README with the source commit, notify test vectors) and vendored byte for byte into each app repo with a checksum test — the pattern of the desktop contract. `instances/self` answers `contract: 1`. Afterwards only optional fields and new capabilities may be added; anything else is a contract v2 decision. | yes (2026-10-14) |
| 45 | **Reference client** | A small test app in `_devextras/testing/cloud-apps/fixture-app/` (PHP built-in server like `messages-gateway/fixture-upstream.php`, container `cloud-fixture` on the Synaplan network) plays the app side of every protocol in two profiles: `keyed` (per-user keys, numeric file ids, bare etags — like Nextcloud) and `sso` (OIDC bearer through Keycloak plus background key, OpenCloud-style ids `storage$space!node` of about 110 characters, quoted etags). Every core brief from CF2.1 on is accepted against it; journeys are walked in the app briefs. It proves in P1 the paths only OpenCloud and ownCloud.online use later. | yes (2026-10-14) |
| 46 | **Switching on per phase** | Replaces the timing of row 22: `cloud_files` goes on for new installs, on web.synaplan.com and through the activation migration after the P1 exit — the core is complete then, surfaces follow each app's features (row 43), and older app releases are untouched (C1). P2 and P3 publish only their app releases. | yes (2026-10-14) |
| 47 | **Nextcloud range of the P1 releases** | `min-version="33" max-version="35"`: the majors that got maintenance releases on 2026-10-15 (32's last was 32.0.15 on 2026-09-10). CI and walks run on 33 and 35. Installations on 30–32 keep 1.5.0, which the App Store still offers them; 1.6.0 was never published there (corrected 2026-10-19; D21, CF1.3a). | yes (product owner, 2026-10-19) |
| 48 | **Break after P1** | P1 ends with the Nextcloud release — milestone M4 of [`11`](./11_nextcloud_release.md) §2. Then the track pauses: Nextcloud fixes, Renovate updates and the compatibility check continue; OpenCloud and ownCloud.online work waits for a dated "P2 go" in STATUS. | yes (product owner, 2026-10-19) |
| 49 | **Engineering and deployment standard** | Code follows `synaplan-opencloud`: Makefile entry points, one CI workflow with an `All Checks Passed` gate, Synaplan pinned by git ref and image digest, the contract checked against the pinned OpenAPI spec, E2E on the Synaplan test stack, Renovate, a short `AGENTS.md`, releases built by CI from a tag. Deployment follows `synaplan-charts`: pinned versions, environments as code, rendered and deployed in CI, generated docs, derived secrets. `11` §3. | yes (product owner, 2026-10-19) |

---

## 4. Target architecture

```text
 Cloud (Nextcloud · ownCloud.online · OpenCloud)               Synaplan core
 ───────────────────────────────────────────────               ─────────────────────────────────────────
 Files UI: bird · eyes · sidebar "Synaplan" ·
           Watch with Synaplan… · New → From Synaplan…
 App server part (PHP app / Go service)
   interactive calls ──── person-held credential ────────────► /api/v1/files · /summary · /v1/chat/completions
                          (OIDC token or scoped key)            /api/v1/cloud/status · /api/v1/cloud/watches
   unattended work ────── person's scoped key ───────────────► /api/v1/cloud/watches/{id}/files · …/snapshots · …/reconcile
                          (SSO: background key)
   instance operations ── instance credential ───────────────► /api/v1/platform-links/…  claim copy · fulfil pickup ·
                          (platform links, existing)             source events · read scope · watches (read) · sso/bind · lifecycle
   inbound endpoint ◄──── notify {type, id}, signed ─────────── delivery · pickup · watches_changed · identity_revoked
                          → the app verifies, then fetches with its own credential

                                                               BEXTERNALIDENTITIES   one row per person per cloud
                                                               BPLATFORMINSTANCES    + delivery URL, OIDC client, capabilities
                                                               BFILES                source · instance · source_id · etag · vector state
                                                               BCLOUDWATCHES         what is watched, what happens
                                                               BCLOUDWATCHITEMS      seen-state ledger (watch, file, version)
                                                               BCLOUDDELIVERIES      copy ledger
                                                               BCLOUDPICKUPS         pickup ledger
                                                               BSAVEDTASKS           trigger `file_event` → run message with the
                                                                                     files attached → text steps · file work
                                                                                     (sandbox) · folder_fetch · tools → approvals
                                                                                     → email_me · save next to the original
```

Four new core pieces; everything else is wiring of what exists:

1. **Identity consolidation** — canonical rows for provisioned people, bind
   and attribution for organisation-login users, a background key, lifecycle
   calls that never delete an account still in use, files that remember their
   cloud, Apps cards for all three clouds ([`02`](./02_identity.md)).
2. **Copies to linked clouds** — the `platform` destination beside `webdav`,
   the notify-then-fetch protocol and a copy ledger
   ([`03`](./03_send_to_cloud.md)).
3. **File state and watches** — a batched status call, source events, the watch
   model with its ledger, the Keep-searchable sync, the `file_event` task
   trigger and four task presets ([`04`](./04_file_state_and_watches.md)).
4. **Files into runs** — the run's files attached to its message, three file
   sets per watch, `folder_fetch` with the pickup protocol, the read scope,
   consent for unattended file work, and "try it on a file, then automate"
   ([`07`](./07_files_into_runs.md)).

Reused as they are: `source_id` / `source_etag` / stale lifecycle (CORE-4),
Saved Task runs with terminal states and approvals, step inputs `{from:
trigger, field: …}` (`StepInputResolver`), `translate` / `summarize` /
`email_me` / `save_to_folder` capabilities, message attachments
(`Message::addFile()`) with their extraction (`MessagePreProcessor`), file work
that mounts a run's attachments into the sandbox (`CodeRunRunner`), the task's
"allow unattended" flag (`BALLOWUNATTENDED`), "Schedule this" step pinning,
platform-links handshake, `OidcBearerAuthenticator`, the WebDAV destination for
clouds without the app, the URL-watch shape (one row per target, seen-state,
Tasks tab).

---

## 5. Parity target

| Feature | Nextcloud | ownCloud.online | OpenCloud | Wave |
| ------- | --------- | --------------- | --------- | ---- |
| Summarize, translate | ✓ | ✓ | ✓ | — |
| Make searchable: add, update, remove, changed | ✓ dialog | fix D2 | ✓ dialog | CF0 |
| Words match the core ("Searchable by AI") | rename | rename | rename | CF1 |
| Chat about a file, research chat, media | ✓ | ✓ | add | CF1 (cuttable) |
| Save any generated kind into the cloud | add kinds | add kinds | add binary | CF1 / CF3 |
| Consent card on every AI surface | ✓ | add | add | CF1 |
| Personal settings | ✓ | add | add (account page) | CF1 / CF2 |
| Five locales, CI gate, unit tests | add | add | add locales | CF1 |
| Per-user keys encrypted at rest | add | add | n/a | CF1 |
| Organisation login | add (CF-S2) | v2 | ✓ + bind | CF2 |
| Connect your Synaplan account | ✓ | add | add (CF-S3) | CF1 / CF2 |
| Accounts created by the administrator | ✓ | ✓ | cut line | — / CF2 |
| One shared account | ✓ | ✓ | ✓ | — |
| Synaplan Apps card with real status | link only | link only | add | CF2 |
| Copy to cloud from the Synaplan Library | add | add | add | CF3 |
| New → From Synaplan inside the cloud | add | add | add (CF-S1) | CF3 |
| Bird in the file list | add | add (CF-S4) | add (CF-S1) | CF4 |
| Sidebar "Synaplan" (five questions) | add | add | add | CF4 |
| Follow the source (change, delete) | add | add | add (CF-S3) | CF4 |
| Watched folders + presets | add | add | add | CF5 |
| What each run gets: the new file, new files together, the whole folder | add | add | add | CF5 |
| What Synaplan may read (set in the cloud) | add | add | add (CF-S1) | CF3 |
| Fetch more files during a run (`folder_fetch` through a pickup) | add | add | add (CF-S3) | CF3 |
| File work in the sandbox on cloud files | core, where Compute is on | core | core | CF5 |
| Try it on a file, then automate | add | add | add | CF5 |
| Watching folders without the app (WebDAV) | — | — | — | CF6 |

Delivered per phase (row 42): every core piece and the Nextcloud column in
P1, the OpenCloud column in P2, the ownCloud.online column in P3. The table
is the state at the end of P3.

---

## 6. Phases and waves

Detail, steps and exits: [`06_sprints.md`](./06_sprints.md); dependency map
and parallel work: `08` §1; tests per phase: `09` §8.

### 6.1 Phases (row 42)

| Phase | Builds | Starts when | Exit |
| ----- | ------ | ----------- | ---- |
| **P0 — Ready** | Local stacks E-1…E-4 and E-6 (E-5 is P3) | The rows the first briefs cite are agreed | `env-check.sh --cloud nextcloud --oidc` green |
| **P1 — Core + Nextcloud** | Every `synaplan` step of CF0–CF5 except CF2.11, and every `synaplan-nextcloud` step; the reference client E-9 before CF2.1; spikes CF-S2 and CF-S5 at the start, CF-S1, CF-S3 and CF-S4 answered before CF3.1 and CF5.1 merge. Released in four milestones ([`11`](./11_nextcloud_release.md) §2) | P0 exit | Nextcloud journeys walked; reference client green in both profiles; the current OpenCloud and ownCloud.online releases still work against the new core; contract v1 frozen (CF5.10); then the Nextcloud release and the **break** (row 48) |
| **P2 — OpenCloud** | Every `synaplan-opencloud` step and the OpenCloud Apps card (CF2.11) | P1 exit and a dated "P2 go" after the break (row 48); CF1.5 may start earlier (row 34) | OpenCloud journeys walked against contract v1 |
| **P3 — ownCloud.online** | Every `synaplan-owncloud-online` step, on the E-5 stack | P2 exit | ownCloud.online journeys walked; §5 has no open cell |

One cloud at a time. A core change in P2 or P3 is additive (row 44), needs a
dated STATUS decision, and runs with the reference-client suite and the
Nextcloud regression set.

### 6.2 Waves inside a phase

| Wave | Content | Exit (on the phase's cloud) | Cut line |
| ---- | ------- | --------------------------- | -------- |
| **CF-S** | Five spikes: Nextcloud organisation login (CF-S2) and Nextcloud Files APIs at scale (CF-S5) at the start of P1; OpenCloud web extension points (CF-S1), OpenCloud unattended access (CF-S3) and ownCloud 11 Files APIs (CF-S4) during P1, before the freeze | Each answer is a dated STATUS entry | — |
| **CF0 — Fix and align** | D1–D3 in the core, docs truth, STATUS hygiene, first walks of J-NC-1…3 | Per-user modes work end to end in Nextcloud; the current OpenCloud and ownCloud.online releases are unchanged or better | Never cut |
| **CF1 — Parity floor** | Nextcloud CI, key encryption, E2E, five locales (P1); OpenCloud chat, media, binary save, locales (P2); ownCloud.online link mode, consent, tests, CI, locales (P3) | No unexplained gap below Nextcloud 1.6 for the phase's cloud (`01` §5.2) | OpenCloud chat may move after CF5 of P2 |
| **CF2 — One identity** | Core: instance columns, app capabilities, canonical identity rows, bind and attribution, OIDC bearer on chat, background key, lifecycle, files that remember their cloud, Apps cards. Apps: Nextcloud identity basics and organisation login (P1), OpenCloud registration and bind (P2), ownCloud.online lifecycle (P3) | J-CF-1 (where the cloud has organisation login), J-CF-2 / J-NC-1, identity half of J-CF-11 | Nextcloud organisation login follows CF-S2; OpenCloud link mode may move to CF6 |
| **CF3 — Files both ways** | Out: `platform` destination, signed notify-then-fetch, copy ledger, Library menu, New → From Synaplan. In: pickup protocol, `folder_fetch`, read scope set in the cloud, WebDAV `list()` / `get()` | J-CF-3, J-CF-4, J-CF-14 | — |
| **CF4 — Searchable state** | Status call, source events with re-upload, bird, sidebar, glyph kit | J-CF-5 | OpenCloud row bird waits for an extension point (sidebar ships) |
| **CF5 — Watched folders and automations** | Watch model, ledger, `file_event` trigger with files attached, three file sets, presets, try it on a file, unattended file-work consent, event sources, reconciliation, Tasks → Watched folders; in P1 also the contract freeze (CF5.10) | J-CF-6…13 and J-CF-15 | "Use one of my tasks" and "The whole folder" may follow |
| **CF6 — Beyond the apps** | WebDAV feeder (roadmap "folder watch"), pull mode for copies and pickups, ownCloud.online organisation login, Nextcloud Flow / Assistant provider | Per item | Optional, after P3 |

Order inside a phase: CF0 → CF1 → CF2 → CF3 and CF4 (their core steps in
parallel) → CF5. An app step starts when the core steps it depends on are
merged to `main`; an app **release** is published only after a Synaplan
release answers the capability (C1). Every step has a brief in
[`08`](./08_agent_briefs.md) and every sprint a test plan in
[`09`](./09_functional_tests.md). Spikes are timeboxed to two working days
each and end in a STATUS entry, never in merged code.

---

## 7. Compatibility invariants

| # | Invariant | Proof |
| - | --------- | ----- |
| C1 | Flags off, or an older core: every app behaves exactly like its current release; new core routes 404 (U11 across repos) | App tests against a core without capabilities (J-CF-12) |
| C2 | Existing Nextcloud modes and keys keep working; nobody is forced to re-link; the Outlook flow (Synamail `docs/AUTH_FLOW.md`) is unchanged | Existing PHPUnit + Synamail steps |
| C3 | Scope additions are exact method + path branches; a platform key never gains `/v1/*` as a whole | `ApiKeyScopeTest` + a contract fixture listing every path each app calls |
| C4 | A copy id is single-use, expires after 5 minutes, is claimable only by its instance and writes only into the storage of the identity it names | Handshake-style tests (replay, expiry, wrong instance, wrong user) |
| C5 | Instance calls never read file contents, chats or memories | Scope tests on the instance endpoints |
| C6 | One run per (watch, file id, version); replays and reconciliation never run twice | Ledger unique key + tests |
| C7 | A watch never fires on a file Synaplan itself wrote | Copy ledger lookup + app own-write guard, tested with a translate watch writing into its own folder |
| C8 | Every run, copy and sync item ends in a terminal state with one sentence; nothing stays "running" | Scheduler sweep + tests |
| C9 | Disconnect on either side, turning a connection off or revoking the instance stops processing at once; watches pause, not vanish; reconnect offers to resume. Deleting the person in the cloud stops their watches (decision 38) | Lifecycle tests + J-CF-11 |
| C10 | Incoming keeps its meaning for unsolicited pushes; a cloud upload that names a knowledge folder other than the default skips it (CF0.3) | Upload tests |
| C11 | Galera: raw idempotent `addSql`, no Schema API, children before parents | Migration review |
| C12 | Mobile: core PHP `backend-only`, core UI `ota-candidate`; new frontend paths classified in `.github/mobile-impact-policy.json` | `node scripts/mobile-impact.mjs` |
| C13 | The public repos carry no hostnames, IPs or secrets of real installations | Review + secret scans |
| C14 | Cost is visible: each file a task watch handles is one task run on the owner's quota; caps per watch and per instance, and on active watches per person and per instance | Quota and cap tests + watch dialog copy |
| C15 | No processing step receives only a path: a run's inputs are Synaplan files attached to its message; a run whose files could not be read ends with a sentence that names them | Runner tests: a payload without attachments fails closed |
| C16 | A pickup ticket is single-use, expires after 10 minutes, belongs to one instance, one person and one selector checked against the read scope; the app re-checks the scope and reads as that person | Ticket tests (replay, expiry, wrong instance, out-of-scope selector) |
| C17 | Unattended file work runs only on a task whose owner allowed it; the sandbox keeps egress off; a pinned script never depends on the test file's name | Compute pause tests (`SavedTaskComputePauseTest` family) + replay on a differently named file |
| C18 | Removing a person in one cloud never deletes a Synaplan account that another connection or the person's own sign-in still uses; only that identity and its keys go | Lifecycle tests: a sole provisioned account is deleted; the same account with a second identity, a password or the admin role is kept and only the identity revoked; the generic admin delete called with an API key answers 409 for a kept account |
| C19 | A cloud file is identified by (instance, remote id): no upload, overwrite, status answer, source event, watch, pickup or whole-folder snapshot from one cloud touches or reuses a copy from another | Repository tests with two instances sharing remote id `5678`; `cf2-file-instance.sh`; the reuse checks in `cf3-pickup.sh` and `cf5-fileset.sh` |
| C20 | An app calls Synaplan for a notification only after verifying it: signature with a published notify key, its own instance id, a timestamp within five minutes, an id not seen in the last ten minutes | App tests with a mocked HTTP client (zero calls for forged, replayed, stale and misaddressed notifications); `cf3-inbound.sh` |
| C21 | Synaplan offers a cloud for a feature only when its app reports the feature (`app_capabilities`, row 43); an app offers a feature only when the core reports it (`capabilities`) | Surface tests with an instance that reports nothing: no copy target, no folder channel, no watch hint; app tests against a core without capabilities (J-CF-12) |
| C22 | After the P1 exit the app-facing API changes only additively; the contract fixtures vendored into each app repo match the core byte for byte | Checksum test in each app repo; contract tests in the core (row 44) |

---

## 8. Rollout

1. **CF0 ships as fixes** (no flag): it makes already-shipped modes work for
   the current releases of all three apps.
2. Core steps merge behind flags during P1. An app release that uses a new
   core feature is published only after a Synaplan release answers that
   capability in `instances/self`; apps hide what the core lacks, and
   Synaplan hides clouds whose app lacks a feature (row 43, C21).
3. **After the P1 exit:** docs first, then the Nextcloud app release, then
   `cloud_files` on for new installs and on web.synaplan.com with the
   activation migration for existing installs (row 46). The walks run on the
   stacks pinned in [`10`](./10_local_environments.md) with the `09` §7
   regression set green. Then the track pauses until a dated "P2 go" (row 48,
   `11` §1).
4. **P2 and P3** each start with a dated go and end with their app release
   and the docs page for that cloud; no core switch.
5. **Rollback:** flag off → capabilities disappear → apps hide watch, bird and
   copy surfaces. Copies already made, files already searchable and keys stay
   valid.

---

## 9. Out of scope (v1)

- Pull mode for copies and pickups for clouds Synaplan cannot reach (CF6
  candidate). Meanwhile "New → From Synaplan" covers copies, and watch runs
  are pushed by the app, so they keep working.
- LibreOffice in file work (Compute v2); v1 file work has the Python and Node
  images.
- Nextcloud Flow operation, Nextcloud Assistant / TaskProcessing provider,
  Talk bot.
- Layout-preserving translation. v1 writes a new document with simplified
  layout and says so.
- A two-way mirror of the Synaplan Library into the cloud.
- "Searchable for my group" on the bird (v1 shows *my* state; the sidebar may
  name a shared knowledge folder later).
- Account merge, SCIM, ownCloud.online organisation login.
- OpenCloud project-space watches beyond what spike CF-S3 proves; otherwise
  reconciliation-only for spaces.

---

## 10. Success criteria

The ten criteria hold for all three clouds at the end of P3. Each phase exit
checks them for its own cloud (`09` §8); the P1 exit also needs contract v1
frozen, the reference client green in both profiles, and the current
OpenCloud and ownCloud.online releases still working against the new core.

1. A Nextcloud, an ownCloud.online and an OpenCloud user each connect (or sign
   in with the organisation login), make a file searchable, see the bird
   within ten seconds and get an answer from it in Synaplan.
2. Library → Generated → **Copy to cloud → Nextcloud** puts the file in
   `Synaplan/Documents`; **Open in Nextcloud** opens it. Same for the other two.
3. A translate watch turns a new `.docx` into `<name>.en.docx` within two
   minutes of the quiet period; the Synaplan row says "Done"; saving the same
   version again starts no second run.
4. A summarize-and-mail watch sends 4–5 sentences; the run is found under
   **Tasks → Watched folders** in ten seconds.
5. Disconnect in Synaplan → the cloud shows Connect again and watches pause;
   reconnect → "Resume 2 watched folders?".
6. A new app against an older core shows no bird, no watch menu and no errors.
7. Five locales, host light and dark themes, WCAG AA on the glyphs.
8. All gates green in four repos; the journeys are Playwright tests for
   Nextcloud and OpenCloud and walked by hand on ownCloud.
9. A watch with **New files together** and a tried instruction that uses file
   work turns three new CSV exports into one Excel with a chart, saved next to
   them; the run names its three input files and its output.
10. In a Synaplan chat, "take the PDFs from my Nextcloud folder Invoices/2026
    and make one table" reads the files (the card names them) and produces the
    table; a folder Synaplan may not read gets the sentence with the way out.

---

## 11. Words

One canonical term per concept, in all five locales and all three apps. Core
terms are copied from `frontend/src/i18n/locales/*/files.json` and
`config.json`; new ones are drafts for translator review in the first PR.

| Concept | en | de | es | fr | tr |
| ------- | -- | -- | -- | -- | -- |
| State (bird) | Searchable by AI | KI-durchsuchbar | Consultable por IA | Recherchable par l’IA | Yapay zekâ ile aranabilir |
| Action | Make searchable by AI | Für den Assistenten durchsuchbar machen | Hacer consultable por IA | Rendre recherchable par l’IA | Yapay zekâ ile aranabilir yap |
| Knowledge group | knowledge folder | Wissensordner | carpeta de conocimiento | dossier de connaissances | bilgi klasörü |
| Copy out | Copy to cloud ¹ | In die Cloud kopieren | Copiar a la nube | Copier vers le cloud | Buluta kopyala |
| Watch (verb) | Watch with Synaplan | Mit Synaplan beobachten | Vigilar con Synaplan | Surveiller avec Synaplan | Synaplan ile izle |
| Watch list | Watched folders | Beobachtete Ordner | Carpetas vigiladas | Dossiers surveillés | İzlenen klasörler |
| Watch kind | Keep searchable | Durchsuchbar halten | Mantener consultable | Garder recherchable | Aranabilir tut |
| Login mode | Organisation login | Organisations-Login | Inicio de sesión de la organización | Connexion de l’organisation | Kurum girişi |
| Link mode | Connect your Synaplan account | Synaplan-Konto verbinden | Conectar tu cuenta de Synaplan | Connecter votre compte Synaplan | Synaplan hesabını bağla |
| Saved task | Task | Aufgabe | Tarea | Tâche | Görev |
| Outside product | App | App | App | App | Uygulama |
| Sandbox runs (core) | File work | Dateiarbeit | Trabajo con archivos | Travail sur fichiers | Dosya çalışması |
| Read permission | Let Synaplan read this folder | Synaplan diesen Ordner lesen lassen | Permitir que Synaplan lea esta carpeta | Autoriser Synaplan à lire ce dossier | Synaplan'ın bu klasörü okumasına izin ver |
| File set | The new file · New files together · The whole folder | Die neue Datei · Neue Dateien gemeinsam · Den ganzen Ordner | El archivo nuevo · Archivos nuevos juntos · La carpeta entera | Le nouveau fichier · Les nouveaux fichiers ensemble · Le dossier entier | Yeni dosya · Yeni dosyalar birlikte · Klasörün tamamı |
| Trial | Try it on a file | An einer Datei ausprobieren | Probarlo con un archivo | L’essayer sur un fichier | Bir dosyada dene |
| Automate a tried turn | Do this for every new file | Das für jede neue Datei tun | Hacer esto con cada archivo nuevo | Faire ceci pour chaque nouveau fichier | Bunu her yeni dosya için yap |

¹ The core says "Send to cloud" in `en` and "copy" in the other four locales.
"Copy" says what happens (the file stays in Synaplan); CF3.2 aligns `en`.

**Banned in primary copy** (apps and core surfaces of this track), on top of
the UX-contract list: RAG, vector, embedding, chunk, index (as a verb), token
exchange, OIDC, webhook, PROPFIND, NATS, CS3, reva, etag, `source_id`, instance
secret, cron, provisioning, path, selector, pickup, ticket. Admin screens may
name "OpenID Connect" and "background jobs" under **Advanced**.

---

## 12. Relation to other plans

| Plan | Relation |
| ---- | -------- |
| [`20260903_more_nextcloud/`](../20260903_more_nextcloud/STATUS.md) | S1 on `main` (#1745), S2 merged in `synaplan-nextcloud` #34. **S3 is absorbed here**: ownCloud.online parity → CF1.1, docs correction → CF0.5. The pairing-code fallback stays in S3 (not needed by these goals). STATUS is corrected once §3 is ticked (CF0.6). |
| [`ROADMAP.md`](../ROADMAP.md) (folder watch in the use-case Q-list; Nextcloud S3 in row 13; the 2026-09-25 text is frozen at [`2026-archive/20260925_roadmap/`](../2026-archive/20260925_roadmap/20260925_roadmap.md) row 5) | "Folder watch (scheduled scan, seen-state per file)" is implemented by CF5 (with the apps) and CF6.1 (plain WebDAV). "Nextcloud S3" → this track. |
| [`20260913-use-case-research/`](../20260913-use-case-research/README.md) | Case 1 ("translate every new document in a cloud folder") is J-CF-7; the intake half of Case 6 reuses CF5. |
| [`2026-archive/20260908_url_watch/`](../2026-archive/20260908_url_watch/00_sprint.md) | Shape reused: one row per target, seen-state, a Tasks tab. |
| [`20261001-single-scheduler/`](../20261001-single-scheduler/00_master_plan.md) | Reconciliation sweeps, copy timeouts and the WebDAV feeder run in its lanes (`tasks` / `hourly`). |
| [`2026-archive/20261009-ux-overhaul/`](../2026-archive/20261009-ux-overhaul/00_master_plan.md) (on `main`, #2431) | Surfaces used: Library tabs, Apps directory and cards, Assistants → Tasks. Glossary used: Task, App. |
| [`20260917-backend-integrations/`](../20260917-backend-integrations/00_master_plan.md) | §0 #1 (apps call Synaplan over HTTP) and #5 (no fourth auth scheme) bind this plan. |
| [`20260902-collabora-integration/05_epic_4_partner_platforms.md`](../20260902-collabora-integration/05_epic_4_partner_platforms.md) | Its assumption "the platform app holds a Synaplan key per user" is met for all three clouds after CF2. |
| [metadist/synaScriber](https://github.com/metadist/synaScriber) OD-A3 | "Nextcloud folder as storage space via the platform link" is a CF3 copy. |
