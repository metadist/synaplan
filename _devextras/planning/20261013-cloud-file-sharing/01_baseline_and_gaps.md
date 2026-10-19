# Baseline and gaps — verified 2026-10-13

Plan of record: [`00_master_plan.md`](./00_master_plan.md).

## 1. Method

Code reading of four repositories at these commits. No running stack was
walked; every "by code reading" claim is re-checked in the first walk (CF0.7).

| Repo | Commit | Version |
| ---- | ------ | ------- |
| `synaplan` | `5487e2678` (`main`) | 5.4.0 |
| `synaplan-nextcloud` | `400ec02` (`main`) | 1.6.0 — app id `synaplan_integration`, Nextcloud 30–34, PHP ≥ 8.2 |
| `synaplan-owncloud-online` | `3b916f6` (`main`) | 1.0.0 — app id `oco_synaplan`, ownCloud 11, PHP 8.4 |
| `synaplan-opencloud` | `5cc1351` (`main`) | v1.0.0 — Go backend + OpenCloud web extension, OpenCloud 8.1.0; README: "Early development — not ready for production use" |

**Re-checked 2026-10-19** at `synaplan` `297d0cc67` (225 commits later,
after 5.5.0) and `synaplan-nextcloud` `a80e2d8`: D1–D3 and D18–D20 hold
unchanged; the classes this plan names are where it says (the service moves
since 5.4.0 did not touch them). New: D21, and D22 in `synaplan-charts`.

---

## 2. The three apps today

### 2.1 Nextcloud (reference)

| Feature | Evidence |
| ------- | -------- |
| Modes `shared` / `provision` / `link` | `lib/Service/SynaplanConfig.php` (`MODE_*`), `lib/Service/UserAccountService.php`, `lib/Service/PlatformLinkService.php`, `lib/Controller/LinkController.php` |
| Link handshake (state, callback, exchange with instance secret, disconnect) | `LinkController` routes `link/start`, `link/callback`, `link/disconnect`; `PlatformLinkService::registerInstance()` stores the secret with `ICrypto` |
| Personal and admin settings | `lib/Settings/SynaplanPersonal.php`, `src/components/PersonalSettings.vue`, `src/components/AdminSettings.vue` (mode selector, register instance) |
| Consent gate on every AI surface | `src/components/AiConsentGate.vue`, used by the research chat and all file modals |
| File actions | `src/files-init.ts` + `src/files_actions/*`: Summarize, Translate, Add to AI Knowledge, Chat about this file (text / JSON / XML only) |
| Knowledge lifecycle | `lib/Controller/ApiController.php`: upload with `source=nextcloud`, `source_id` = file id, `source_etag`, `overwrite=1`; status through `check-stale`; remove. Stale shown only inside `src/components/KnowledgeModal.vue` |
| Research chat + floating launcher, memories, models, `/pic` `/vid` | `src/components/ResearchChat.vue`, `lib/Controller/ChatController.php` + `lib/Http/SseProxyResponse.php` → `POST /v1/chat/completions` (SSE) |
| Save generated media into Nextcloud | `lib/Controller/MediaController.php` → `IRootFolder`, `Synaplan/{Documents,Audio,Calendar,Images,Video}/` (`KIND_FOLDERS`); chat surfaces images and video only (CHANGELOG 1.3.0 follow-up) |
| User deletion | `lib/Listener/UserDeletedListener.php` on `BeforeUserDeletedEvent`: revoke a linked key, delete a provisioned account through the generic admin delete (D16). No disable / enable listener |
| Keys at rest | Admin / shared key in app config (plain); per-user key in user preference `synaplan_user_api_key` (plain); instance secret encrypted |
| Not present | File-list state, sidebar tab, file event listeners, background jobs, Flow, OIDC, Nextcloud AI provider registration, `.github/` CI, E2E |
| Locales | `l10n/de.json` filled, `l10n/en.json` empty |
| Tests | PHPUnit `tests/Unit/**` (controllers, services, listener) |
| Docs | `docs/HOSTING_GUIDE.md` roadmap lists "bulk folder sync (filesystem events + nightly reconciliation)" as planned; it also says stale handling is not implemented (it is, since 1.5.0) and that scopes are not enforced (they are, see D1) |

### 2.2 ownCloud.online

| Feature | Evidence |
| ------- | -------- |
| Modes | Boolean `per_user_accounts` only (`lib/Service/SynaplanConfig.php`): shared or provision. No link mode, no `Exception/`, no `Listener/` |
| Provisioning | `lib/Service/UserAccountService.php`: `POST /api/v1/admin/users` with `source=owncloud`, `external_id=<instanceid>:<uid>`; key scopes `chat files rag` |
| Settings | Admin only (`lib/Settings/Admin.php`, `src/components/AdminSettings.vue`); no personal settings |
| Consent | Only in `src/components/ChatPanel.vue`; Summarize / Translate / Knowledge modals call with an empty key when the person never consented |
| File actions | `src/files-init.ts` registers four actions for `mime: 'all'` with `OC.PERMISSION_READ` — actions appear on files the server then refuses |
| Knowledge lifecycle | Same pattern as Nextcloud (`lib/Controller/ApiController.php`, `src/components/KnowledgeModal.vue`), `SynaplanClient::SOURCE = 'owncloud'` — see D2 |
| Chat, media save | `src/components/ChatPanel.vue`, `lib/Controller/MediaController.php` (`Synaplan/<Kind>/`); scripts load on the Files app only (`appinfo/app.php`, `OCA\Files::loadAdditionalScripts`) |
| User deletion | `appinfo/app.php`: `IUserManager::listen('\OC\User', 'preDelete', …)` deletes the provisioned account through the generic admin delete (D16) |
| Not present | Link mode, personal settings, file-list state, file hooks, background jobs, tests (`tests/` is empty), CI, locales (`l10n/` holds only `.gitkeep`) |
| Git | Four commits: initial release + rebranding / PHP 8.4 |

### 2.3 OpenCloud

| Feature | Evidence |
| ------- | -------- |
| Architecture | Go BFF `backend/` (`pkg/command/server.go` routes under `/api/synaplan/*`), OpenCloud proxy route to it (`dev/docker/opencloud.proxy.config.yaml`), web extension `frontend/src/index.ts` |
| Organisation login (mode A) | `backend/internal/tokenexchange/`: RFC 8693 exchange of the user's OpenCloud OIDC token at Keycloak (`synaplan-opencloud` client, audience `SYNAPLAN_OIDC_TARGET_AUDIENCE`), cached per user until expiry − 30 s; Synaplan called with `Authorization: Bearer` (`backend/internal/synaplanauth/auth.go`) |
| Shared key (mode B) | `SYNAPLAN_API_KEY` (`sk_…`), one Synaplan account for everyone; exactly one mode allowed (`pkg/config/parser`) |
| File access | CS3 gateway (`backend/internal/cs3reader/`): Stat, download, upload; no Graph, no WebDAV client, no event bus |
| Actions | Context submenu "Synaplan" (`src/extensions/useSynaplanActionsExtension.ts`): Translate…, Summarize…, Add to knowledge… (`useTranslationExtension.ts`, `useSummarizeExtension.ts`, `useKnowledgeExtension.ts`) |
| Knowledge lifecycle | `backend/internal/handler/knowledge.go`, `knowledge_lifecycle.go`: upload with `source=opencloud`, status via `check-stale`, remove |
| Save to space | `backend/internal/handler/files.go`: **text only**, `maxSavedTextBytes = 2 << 20`; personal space `Synaplan/<Kind>/` (`backend/internal/cs3reader/write.go`) |
| Brand icon | `src/composables/useSynaplanBird.ts` → `/api/synaplan/assets/brand-icon?theme=` (Synaplan runtime `resolvedIconUrl`) — branding in dialogs, not file state |
| Not present | Chat, media, file-list state, sidebar panel, event consumers, consent card, personal settings, link / provision modes, per-user settings store |
| Locales | `frontend/l10n/translations.json` is `{}` |
| Quality | Full CI with `All Checks Passed` (`.github/workflows/ci.yml`), Go tests, Vitest, Playwright (`:9200` bundled / `:9201` dev server) |

---

## 3. What the core already provides

| Goal | Building block | Evidence |
| ---- | -------------- | -------- |
| 1 | `POST /api/v1/files/{id}/send` with destinations `email`, `share_link`, `webdav`, `dropbox`, `caldav`, `m365_calendar` | `backend/src/Controller/FileSendController.php` |
| 1 | WebDAV destination: connection-owned, default folder `Synaplan`, `on_conflict` rename / overwrite, 100 MB cap, MKCOL per segment | `backend/src/Service/Destination/WebDavDestinationProvider.php` |
| 1 | Destination contract is synchronous: `DestinationResult::success()` / `failure(DestinationFailureCode)` | `backend/src/Service/Destination/DestinationProvider.php`, `DestinationResult.php` |
| 1 | "Send to cloud" menu on Files and Workspace; OpenCloud preset for the WebDAV folder (app token) | `frontend/src/components/files/FilePushMenu.vue`, `frontend/src/i18n/locales/en/config.json` (`appPasswordHintOpencloud`) |
| 1 | Generated files are rows in `BFILES` with `BSOURCE = 'generated'` and `BORIGINKIND` | `backend/src/Entity/File.php`, `backend/src/Service/Media/GeneratedFileRegistrar.php` |
| 2 | OIDC login + bearer validation; users from claims; identity row `oidc:<issuer>` — no record of the calling partner client | `backend/src/Security/OidcBearerAuthenticator.php`, `backend/src/Service/OidcUserService.php` (`oidcSource()`) |
| 2 | Platform links: instances (`BPLATFORMINSTANCES`, secret stored as hash), link codes, exchange, my links, admin approval | `backend/src/Entity/PlatformInstance.php`, `backend/src/Service/PlatformLink/*`, `frontend/src/views/PlatformConnectView.vue` |
| 2 | `BEXTERNALIDENTITIES (source, instance, external_id) → user, api key` with badges Nextcloud / ownCloud / OpenCloud / OIDC | `backend/src/Entity/ExternalIdentity.php` |
| 2 | Scope enforcement for restricted keys, default deny | `backend/src/Security/ApiKeyScope.php`, `ApiKeyScopeSubscriber.php` |
| 2 | Apps directory with Nextcloud, ownCloud, Outlook cards; "connected" = a platform link exists | `frontend/src/apps/catalog.ts`, `frontend/src/apps/status.ts` |
| 2 | OIDC auto-redirect on the login page | `frontend/src/views/LoginView.vue` (`auto_redirect`) |
| 3 | Provenance and sync fields: `BSOURCE`, `BSOURCEID`, `BSOURCEETAG`, `BSTALE`, `BVECTORSTATE`, `BGROUPKEY`, `BINCOMING` | `backend/src/Entity/File.php` |
| 3 | `POST /api/v1/files/check-stale` (current / stale / missing per `source_id`), `POST /api/v1/files/{id}/mark-stale`, upload `overwrite` | `backend/src/Controller/FileController.php` |
| 3 | Saved Tasks: triggers `manual`, `chat`, `schedule`, `inbound_email`, `webhook`; runs with terminal states and `waiting_approval` | `backend/src/Entity/SavedTask.php`, `SavedTaskRun.php` |
| 3 | Webhook trigger: 64 KB JSON body, optional HMAC, queued run with `trigger_payload` | `backend/src/Service/SavedTask/SavedTaskWebhookIngress.php` |
| 3 | Step inputs from the trigger payload: `{ "from": "trigger", "field": "body.title" }` | `backend/src/Service/SavedTask/Graph/StepInputResolver.php` |
| 3 | Capabilities `summarize`, `translate`, `email_me`, `save_to_folder`, `rag_query`, `chat`, `ask_user`, … | `backend/src/Service/Multitask/Plan/Capability.php` |
| 3 | Approvals inbox and in-chat cards | `backend/src/Controller/ApprovalController.php` |
| 3 | URL watch: `BURLWATCHES` one row per owner + URL, "Watched pages" tab | `backend/src/Entity/UrlWatch.php`, `frontend/src/components/config/SavedTasksOverview.vue` |
| 3 | WebDAV client: `exists`, `mkcol`, `put`, `report` — no `list`, no `get` | `backend/src/Service/Dav/WebDavClient.php` |

---

## 4. Defects found

| # | Defect | Evidence | Fix |
| - | ------ | -------- | --- |
| **D1** | Platform keys (`chat files rag [memories]`) are restricted keys. The scope map has no branch for `/api/v1/summary/*`, `/api/v1/media/*` or `/api/v1/config/memory-service/*`, and `/v1/*` requires `desktop:messages`. The Nextcloud and ownCloud.online apps call exactly those paths for summarize, translate, chat about a file, research chat and media. By code reading: `403 insufficient_scope` in the provision and link modes. #1936 mapped `/api/v1/messages`, `/chats`, `/files`, `/rag`, `/config/models` — not the paths the apps use. | `ApiKeyScope::requiredScopesForPath()`; `ApiKeyScopeTest::platformLinkProductPaths()`; app clients `lib/Service/SynaplanClient.php`, `lib/Controller/ChatController.php` | CF0.1 |
| **D2** | `File::SOURCES` lacks `owncloud`. `FileController::upload` rewrites unknown sources to `web_upload`; `check-stale` filters by source → every ownCloud.online status is "missing"; the Library shows these files as web uploads. | `backend/src/Entity/File.php` (`SOURCES`), `FileController.php` (source whitelist, `findByUserSourceIds`) | CF0.2 |
| **D3** | Every `nextcloud` / `opencloud` / `outlook` upload sets `BINCOMING = 1`, also when the caller named a knowledge folder. A synced folder would put hundreds of files into Incoming. | `backend/src/Service/File/FileUploadService.php` (`setIncoming(in_array($options->source, File::INCOMING_SOURCES))`) | CF0.3 |
| **D4** | Nextcloud and ownCloud.online keep per-user keys in plain user preferences, and Nextcloud its admin and shared keys (`api_key`, `api_key_local`) in plain app config; only the instance secret is encrypted. Watches make these keys work while the person is away. | `UserAccountService.php` (both apps), `PlatformLinkService.php` (NC) | CF1.3c / CF1.1 |
| **D5** | ownCloud.online asks for consent only in the chat panel. | `src/components/ChatPanel.vue`; modals without a gate | CF1.1 |
| **D6** | ownCloud.online registers its actions for every MIME type. | `src/files-init.ts` (`mime: 'all'`) | CF1.1 |
| **D7** | OpenCloud saves only text (2 MiB) into the space; generated images, audio and Office files cannot be saved. | `backend/internal/handler/files.go` | CF1.5 / CF3.5 |
| **D8** | OIDC bearer users are recorded as `oidc:<issuer>` without the calling client. Synaplan cannot show "OpenCloud — last used", cannot offer a stop, cannot copy into the right OpenCloud account. | `OidcUserService::oidcSource()` | CF2.2 |
| **D9** | The Apps directory has no OpenCloud card; the Nextcloud and ownCloud cards count only platform links, so provisioned or shared-key users look disconnected. | `frontend/src/apps/catalog.ts`, `status.ts` | CF2.5 |
| **D10** | The docs promise OpenCloud "WebDAV and Graph API", "Spaces-aware indexing", "Automatic sync and re-indexing" and an app-token setup; the code uses CS3, the personal space, manual updates and a shared Keycloak. | `synaplan-docs/docs/plugins.md` (OpenCloud section), `docs/intro.md` | CF0.5 |
| **D11** | Nextcloud and ownCloud.online have no CI in the repository; ownCloud.online has no tests; the Nextcloud hosting guide's roadmap is out of date. | missing `.github/`; empty `tests/`; `docs/HOSTING_GUIDE.md` | CF1.2 / CF1.3b / CF0.5 |
| **D12** | Locales: Nextcloud `de` only, ownCloud.online none, OpenCloud none; the core has five. | `l10n/` in each app | CF1.4 |
| **D13** | Words drift: "Add to AI Knowledge" (Nextcloud), "Add to knowledge…" (OpenCloud) against the core's "Make searchable by AI" / "Searchable by AI". | app sources; `frontend/src/i18n/locales/en/files.json` | CF1.4 |
| **D14** | More-Nextcloud STATUS still lists S2 as planned; it is merged in `synaplan-nextcloud` #34 (the roadmap already notes this). | `../20260903_more_nextcloud/STATUS.md` | CF0.6 |
| **D15** | Provisioning writes the identity row in another shape than linking. Linking stores `(client, Synaplan platform instance id, platform uid)` with the minted key; provisioning stores `(client, '', '<cloud instanceid>:<uid>')` — both apps prefix the cloud's own `instanceid` — and the key it mints next is never bound to the row. A lookup by `(instance, uid)` — status, attribution, lifecycle — cannot find provisioned people. | `AdminUserProvisioningService::upsertIdentity()` (no instance, no key) against `PlatformLinkExchangeService::exchange()`; `UserAccountService::provisionAndMint()` in both apps | CF2.2 (core) / CF2.10, CF2.8 (apps) |
| **D16** | Deleting a cloud user deletes the provisioned Synaplan account through the generic admin delete, whatever else the account is used for — another cloud, a password of its own. Harmless while a provisioned account serves one cloud; wrong once one person uses one account from several clouds (Goal 2). | Nextcloud `UserDeletedListener` → `UserAccountService::deleteRemoteAccount()`; ownCloud.online `appinfo/app.php` (`preDelete`) → `deleteRemoteAccount()`; both call `DELETE /api/v1/admin/users/{id}` (`AdminController::deleteUser()`) | CF2.4 (core rule) / CF2.10, CF2.8 (apps call `lifecycle`) |
| **D17** | `BFILES` does not record which cloud instance a file came from. Upload with `overwrite` and `check-stale` match `(user, source, source_id)`, but a remote file id is unique only inside one cloud: for a person connected to two Nextclouds that both have file `5678`, one cloud overwrites or marks stale the other's copy — and source events (CF4) would delete it. | `FileRepository::findForOverwrite()`, `findByUserSourceIds()`; index `idx_file_user_source_sid` | CF2.9 |
| **D18** | OIDC bearer tokens are refused on `/v1/*`: the `openai_compat` firewall (`^/v1`) runs only `ApiKeyAuthenticator`. Chat about a file over organisation login (OpenCloud CF1.5, Nextcloud CF2.6) cannot work. Found in the third review. | `backend/config/packages/security.yaml` (`openai_compat`); `OidcBearerAuthenticator::supports()` | CF2.2 |
| **D19** | Disconnect deletes the identity row (`PlatformLinkExchangeService::disconnect()` calls `remove($identity)`), so nothing is left to resume: watches would lose their owner link, and "Resume 2 watched folders?" (C9) could not be offered. Found in the third review. | `backend/src/Service/PlatformLink/PlatformLinkExchangeService.php` | CF2.5 |
| **D20** | The ephemeral reaper deletes hidden files after 24 hours (`ReapEphemeralFilesCommand::DEFAULT_TTL_HOURS`), but working copies of watch runs must stay 7 days for retries (decision 20). Found in the third review. | `backend/src/Command/ReapEphemeralFilesCommand.php` | CF5.1 |
| **D21** | The Nextcloud app declares Nextcloud 30–34. Nextcloud 35.0.0 shipped on 2026-09-15 and is `nextcloud:latest`, so the App Store does not offer the app there; 30–32 get no maintenance releases any more (32.0.15 on 2026-09-10 was the last). Six controllers rely only on docblock annotations, which Nextcloud deprecated in favour of attributes (`LinkController` carries both), and `nextcloud/ocp` is required as `dev-master`. Found in the fourth review. | `appinfo/info.xml` (`<nextcloud min-version="30" max-version="34"/>`), `lib/Controller/*.php`, `composer.json` | CF1.3a |
| **D22** | Chart installs do not run the migrations the way the image does. `synaplan-charts`' web init runs `doctrine:schema:update --force` (marked FIXME) before the image's `doctrine:migrations:migrate`; on a fresh install the migrations bootstrap then marks every migration as applied without running it, on an upgrade the new migrations run after the schema update. It also contradicts the house rule never to run `schema:update` against a shared database. Found in the fourth review. | `synaplan-charts`: `charts/synaplan/templates/init-scripts-configmap.yaml` (`51-init-models.sh`); `_docker/backend/lib/migrations-bootstrap.sh` ("Fresh schema detected — marking all migrations as applied") | `11` §3.2 item 4; the chart fix is a `synaplan-charts` PR |
| **D23** | The Nextcloud app adds its chat launcher and Files actions to every page, so the floating "Chat with Synaplan AI" button sits on the Nextcloud login page, where nobody is signed in and it cannot work. Found in the CF0.1 walk (1.6.0). | `synaplan-nextcloud`: `lib/AppInfo/Application.php` (`boot()`, `Util::addScript` without a session check) | CF1.3e |
| **D24** | Summarize without a connected Synaplan account answers HTTP 500 and shows the raw Guzzle error. Eleven failure paths answer 500 with the exception message — no recovery, and the message can carry internal URLs (U8). The streaming chat passes an upstream error into the event stream as a raw JSON body, so the research chat shows nothing at all. Found in the CF0.1 walk and the AI walk (1.6.0). | `synaplan-nextcloud`: `lib/Controller/{ApiController,ChatController,MediaController}.php` (`catch (\Exception $e)` → `STATUS_INTERNAL_SERVER_ERROR` with `$e->getMessage()`) | CF1.3e |
| **D25** | **Translate with Synaplan** does not translate. The app calls the summary endpoint (`abstractive`, `long`, target language), so it returns a restructured essay in the target language that adds judgements the document never makes (a real run called a missed restore time "einen erheblichen Vertragsverstoß" and appended an overall assessment). Synaplan has no translation mode to call instead. Found in the AI walk (1.6.0). | `synaplan-nextcloud`: `lib/Controller/ApiController.php` (`translate()` → `summarize(…, 'abstractive', 'long', …)`); `synaplan`: `Service/Summary/SummaryOptions.php` (`summaryType` has no translation) | proposed: CF0.8 (core translation mode) + CF1.3f |
| **D26** | **Chat about this file** answers with a summary, not the question. `ChatModal` posts to `/api/v1/chat`, whose `SynaplanClient::ask()` sends question and file to the summary endpoint (`abstractive`, `long`); asked "by how much did the restore time exceed the contract?", it returned an essay about the review. The streaming research chat (`/v1/chat/completions`) answers correctly. Found in the AI walk (1.6.0). | `synaplan-nextcloud`: `lib/Service/SynaplanClient.php` (`ask()`), `src/components/ChatModal.vue` | proposed: CF1.3f |
| **D27** | The chat's "Open in Synaplan" link is the API base URL (`getBaseUrl()`), so in Docker and split setups it points at an internal host (`http://backend`) instead of the address people open (`synaplan_public_url`). Found in the AI walk (1.6.0). | `synaplan-nextcloud`: `lib/Controller/ChatController.php` (`deepLink`), `SynaplanConfig::getPublicBaseUrl()` | proposed: CF1.3f |
| **D28** | The connect page (`/connect/platform`) names the Nextcloud user but not the Synaplan account that is signed in, so a session left open in the browser (in the walk: the administrator) is linked without anyone noticing. Answer "Who owns this?" on the page (U7): "Signed in as …". Found in the AI walk. | `synaplan`: `frontend/src/views/` connect platform view | proposed: CF0.9 |

---

## 5. Gap matrix and platform constraints

### 5.1 What each platform offers for the new work

"Verified" means seen in this workspace's code; "spike" means it is believed to
exist and is confirmed in the named spike before a sprint depends on it.

| Need | Nextcloud 33–35 | ownCloud 11 (ownCloud.online) | OpenCloud 8.x |
| ---- | --------------- | ----------------------------- | ------------- |
| Bird in the file list | Files action with inline rendering in `@nextcloud/files` 4 (spike CF-S5: batched async render at 5 000 files) | Files `FileList` plugin row hook (spike CF-S4) | No known row-indicator extension point; `sidebarPanel`, `action`, `folderView`, `customComponent`, `accountExtension` exist in the SDK types (spike CF-S1) |
| Sidebar tab | Files sidebar tab API (spike CF-S5: which API across 33–35) | Detail tab view (spike CF-S4) | `sidebarPanel` (verified in SDK types) |
| "New → From Synaplan" | New-file menu entry in `@nextcloud/files` (spike CF-S5) | New-file menu plugin (spike CF-S4) | Create-menu extension (spike CF-S1) |
| File events | Node created / written / renamed / deleted events (spike CF-S5: group folders, external storage, trash restore) | Files hooks / dispatcher events (spike CF-S4) | Event bus consumer for upload, move, trash, restore (spike CF-S3) |
| Write as a user without a session | `IRootFolder::getUserFolder()` (verified in `MediaController`) | Same (verified in `MediaController`) | Service account / impersonation (spike CF-S3); today only the request user's token |
| Background work | Background jobs; needs system cron | Background jobs; needs system cron | Goroutine in the Go service |
| Notifications | Notifications app (spike CF-S5) | Notifications app (spike CF-S4) | User log / notifications service (spike CF-S1) |
| Private link to a file | `/f/<fileid>` (spike CF-S5) | `/f/<fileid>` (spike CF-S4) | `/f/<resource id>` (spike CF-S1) |
| Organisation login token for the app | `user_oidc` token events (spike CF-S2) | `openidconnect` app — not pursued in v1 | Verified (token exchange in the Go service) |
| Per-user key storage | User preferences (verified); sensitive `IUserConfig` values, which Nextcloud 33–35 encrypt (CF1.3c) | User preferences (verified), encrypt | None today; persistent store (spike CF-S3) |

### 5.2 Gaps a platform may keep

| Gap | Platform | Reason | Mitigation |
| --- | -------- | ------ | ---------- |
| Organisation login | ownCloud.online | Managed by the hoster; its identity provider is not ours to configure | Connect your Synaplan account; v2 on hoster request |
| Bird in the file list | OpenCloud, until an extension point exists | Web extension SDK offers no row indicator (to confirm in CF-S1) | Sidebar panel + state in the action label + Synaplan app page list; upstream request filed in CF4.5 |
| Accounts created by the administrator | OpenCloud (cut line) | Organisation login already creates the account on first use; hosters without a shared identity provider use Connect | Revisit on hoster request |
| Project-space watches | OpenCloud | Depends on CF-S3 (events + impersonation in spaces) | Reconciliation-only if events are missing |

---

## 6. State of related plans

| Plan | State on 2026-10-13 |
| ---- | ------------------- |
| More Nextcloud S1 | On `main` (#1745). Walks J-NC-1…3 missing (roadmap §3.1) |
| More Nextcloud S2 | Merged in `synaplan-nextcloud` #34 (1.6.0, 2026-09-15) |
| More Nextcloud S3 | Planned — absorbed here except the pairing-code fallback |
| Roadmap row 5 "Folder watch" | Open — CF5 + CF6.1 |
| Use case 1 "Translate every new document" | "Conditional — no file-created trigger anywhere" — J-CF-7 |
| Single scheduler | P2 partly done; lanes are where CF5 sweeps and CF6.1 scans run |
| UX overhaul | On `main` (#2431): Apps directory, Library tabs, Assistants → Tasks |
