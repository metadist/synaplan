# 03 — The plugin `meeting_notes`

Reference implementation for layout and conventions: Synaform
(`metadist/synaplan-synaform`, plugin directory `synaform-plugin/`).
Everything below follows it unless it says otherwise.

---

## 1. Repository `metadist/synaplan-meetingnotes`

```text
synaplan-meetingnotes/
├── README.md                     # what it is, install, link to Synaplan docs
├── INSTALL.md                    # Synaplan side (plugin dir, restart, settings) + openDesk side (snippets)
├── CHANGELOG.md
├── LICENSE                       # same licence family as Synaform (decide in MN-2)
├── Makefile                      # lint, test, build-loader, package, dev-deploy
├── .github/workflows/ci.yml      # php -l + phpstan (against a pinned synaplan), vitest, loader build, luacheck, busted
├── meetingnotes-plugin/          # ← copied to <synaplan>/plugins/meeting_notes/
│   ├── manifest.json
│   ├── backend/
│   │   ├── Controller/
│   │   │   ├── JitsiController.php          # /api/v1/plugins/meeting_notes/jitsi/*   (bearer)
│   │   │   ├── PublicJitsiController.php    # …/public/jitsi/{loader.js,config}
│   │   │   ├── TranscriberController.php    # …/public/transcriber/*  (token)
│   │   │   ├── ProsodyCallbackController.php# …/public/prosody/events (HMAC)
│   │   │   ├── UserSessionController.php    # /api/v1/user/{userId}/plugins/meeting_notes/sessions*
│   │   │   └── AdminController.php          # /api/v1/user/{userId}/plugins/meeting_notes/admin/*
│   │   ├── Service/
│   │   │   ├── MeetingNotesSettings.php     # typed read/write of P_meeting_notes (+ secrets via EncryptionService)
│   │   │   ├── StartPolicy.php              # v1.0: signed in, not guest; v1.1: groups/users
│   │   │   ├── SessionService.php           # state machine, start/stop/finalize orchestration
│   │   │   ├── SessionWatchdog.php          # periodic task, see 02 §2
│   │   │   ├── ProsodyClient.php            # HMAC client for /synaplan-notes/v1/*
│   │   │   ├── HmacSigner.php               # shared by client + callback verification
│   │   │   ├── WindowTranscriber.php        # audio window → AiFacade::transcribe as owner → segment
│   │   │   ├── SegmentFilter.php            # no-speech / hallucination / duplicate rules (05 §5)
│   │   │   ├── TranscriptRenderer.php       # segments → Markdown (03 §8)
│   │   │   ├── TranscriptWriter.php         # Markdown → FileUploadService into the folder
│   │   │   ├── FolderCatalog.php            # list / create Files folders (group keys)
│   │   │   ├── StatusChecks.php             # the four admin status lines
│   │   │   ├── ConnectionSnippets.php       # snippets for openDesk, Keycloak, transcriber
│   │   │   ├── SpeechModelPicker.php        # SOUND2TEXT models, sovereignty filter
│   │   │   └── Copy.php                     # backend sentences in five locales (messages in API answers)
│   │   ├── Repository/
│   │   │   ├── SessionRepository.php        # plugin_data type session / session_ref / room_lock
│   │   │   └── SegmentRepository.php        # plugin_data type segment, prefix queries (DBAL, bindValue)
│   │   └── tests/                           # PHPUnit, run inside a synaplan checkout (see §10)
│   ├── frontend/
│   │   ├── index.js                         # personal page + admin page (plain ES module, like Synaform)
│   │   └── i18n/{en,de,es,fr,tr}.json
│   ├── public/
│   │   ├── jitsi-loader.js                  # built from ../jitsi (committed build output, versioned)
│   │   └── jitsi-loader.js.map
│   └── migrations/
│       └── 001_setup.sql                    # per-user enabled flag only (personal page visibility)
├── jitsi/                                    # loader sources (TypeScript, Vite library build → ../meetingnotes-plugin/public)
│   ├── src/{index.ts, auth.ts, api.ts, button.ts, dialog.ts, banner.ts, jitsi.ts, i18n.ts, styles.css}
│   ├── src/i18n/{en,de,es,fr,tr}.json
│   ├── static/synaplan-notes-silent.html    # mounted into Jitsi web /usr/share/jitsi-meet/static/
│   └── test/                                # vitest + jsdom; Playwright journeys live in tests/e2e
├── prosody/
│   ├── mod_synaplan_notes.lua
│   └── spec/                                # busted tests with a fake room / metadata component
├── deploy/
│   ├── opendesk/values-jitsi-meeting-notes.yaml.gotmpl   # openDesk customization slot for Jitsi
│   ├── synaplan-charts/values-meeting-notes.yaml          # plugin mount + transcriber
│   ├── keycloak/synaplan-meeting-notes-client.json
│   └── dev/                                              # generic dev overlay (no hostnames), see 06
└── tests/
    ├── e2e/                                 # Playwright: journeys J-MN-1…7 against a dev openDesk
    └── fixtures/                            # bridge messages, Opus windows, expected transcripts
```

The plugin directory name inside Synaplan is **`meeting_notes`** (must match
`^[a-z0-9_-]+$`); namespace `Plugin\MeetingNotes`; settings group
`P_meeting_notes`.

## 2. `manifest.json`

```json
{
  "id": "meeting_notes",
  "name": "meeting_notes",
  "namespace": "Plugin\\MeetingNotes",
  "version": "1.0.0",
  "displayName": "Meeting notes",
  "description": "Written notes of Jitsi meetings in openDesk. Speech becomes text on your organisation's servers; audio is not kept.",
  "author": "Synaplan",
  "homepage": "https://github.com/metadist/synaplan-meetingnotes",
  "license": "TBD in MN-2",
  "minSynaplanVersion": "5.2.0",
  "capabilities": ["api", "frontend", "migrations"],
  "permissions": ["network:prosody", "network:keycloak"],
  "routes": [
    {"path": "/public/jitsi/loader.js", "method": "GET", "description": "Jitsi loader bundle (public)"},
    {"path": "/public/jitsi/config", "method": "GET", "description": "Is the Jitsi button on (public)"},
    {"path": "/jitsi/me", "method": "GET", "description": "Can the signed-in person start here"},
    {"path": "/jitsi/sessions", "method": "POST", "description": "Start meeting notes"},
    {"path": "/jitsi/sessions/{id}", "method": "GET", "description": "Session state for the starter"},
    {"path": "/jitsi/sessions/{id}/stop", "method": "POST", "description": "Stop meeting notes"},
    {"path": "/public/transcriber/sessions/{ref}", "method": "GET", "description": "Bind a bridge connection (transcriber token)"},
    {"path": "/public/transcriber/sessions/{ref}/audio", "method": "POST", "description": "One speech window (transcriber token)"},
    {"path": "/public/transcriber/sessions/{ref}/events", "method": "POST", "description": "Transcriber lifecycle events"},
    {"path": "/public/prosody/events", "method": "POST", "description": "Roster and stop events from Prosody (HMAC)"},
    {"path": "/sessions", "method": "GET", "description": "My meeting notes"},
    {"path": "/sessions/{id}", "method": "GET", "description": "One of my sessions"},
    {"path": "/sessions/{id}/stop", "method": "POST", "description": "Stop one of my sessions"},
    {"path": "/sessions/{id}", "method": "DELETE", "description": "Remove a session record (the file stays)"},
    {"path": "/admin/status", "method": "GET", "description": "Status lines (admin)"},
    {"path": "/admin/settings", "method": "GET", "description": "Settings (admin)"},
    {"path": "/admin/settings", "method": "PUT", "description": "Update settings (admin)"},
    {"path": "/admin/connection", "method": "POST", "description": "Create or rotate the openDesk connection secrets (admin)"},
    {"path": "/admin/sessions", "method": "GET", "description": "Session metadata, no content (admin)"},
    {"path": "/admin/stop-all", "method": "POST", "description": "Stop every running session (admin)"}
  ],
  "migrations": ["001_setup.sql"],
  "config": {
    "group": "P_meeting_notes",
    "settings": [
      {"key": "enabled", "type": "boolean", "default": false, "description": "Show the Meeting notes button in Jitsi"}
    ]
  }
}
```

Paths in `routes` are documentation (as in Synaform); the controllers carry
the real `#[Route]` attributes with full paths:

| Prefix | Auth |
|--------|------|
| `/api/v1/plugins/meeting_notes/public/…` | public prefix (core `MN-1a`), each controller verifies token/HMAC |
| `/api/v1/plugins/meeting_notes/jitsi/…` | normal `^/api` auth → Keycloak bearer |
| `/api/v1/user/{userId}/plugins/meeting_notes/…` | normal auth + `#[CurrentUser]` = `{userId}`; `admin/*` also `$user->isAdmin()` |

## 3. Install model

| Who | What | How |
|-----|------|-----|
| Operator | Put the plugin into `/plugins/meeting_notes` for **web, worker and scheduler** pods, restart. | Chart values ([06 §3](./06_dev_environment.md#3-the-plugin-into-synaplan)). |
| Admin | Install the plugin for their own account to get the admin page. | `php bin/console app:plugin:install <adminId> meeting_notes` (doc step) — or automatic: the plugin installs itself for every admin on the first `GET admin/status` call made through `/plugins/meeting_notes`. Decide in `MN-2`; default: CLI step in INSTALL.md. |
| Person in Jitsi | Nothing. Starting meeting notes does **not** require the plugin to be installed for them. | The Jitsi endpoints check the global `enabled` + policy only. |
| Person afterwards | Gets the personal **Meeting notes** page in Plugins as soon as a session exists, including one that failed to save. | `SessionService` calls `PluginManager::installPlugin($ownerId, 'meeting_notes')` once (idempotent) when `start()` has created the session (`starting`), not when it reaches `saved`. A first session whose file write fails can still be opened and saved again. |

`001_setup.sql` only seeds the per-user `enabled = 1` row (`INSERT IGNORE`),
because the personal page checks it like Synaform. Global settings are not
in migrations (they are owner `0`).

## 4. Backend classes (responsibilities and key methods)

| Class | Key methods | Notes |
|-------|-------------|-------|
| `MeetingNotesSettings` | `isEnabled()`, `defaultLanguage()`, `offeredLanguages()`, `defaultFolder()`, `speechModelId()`, `allowCloudStt()`, `captions()`, `profile()`, `prosodyUrl()`, `prosodySecret()`, `transcriberUrl()`, `transcriberToken()`, `noticeExtra()`, `update(array)` | Reads `ConfigRepository::getValue(0, 'P_meeting_notes', …)`; secrets through `EncryptionService`. Validates every value; unknown keys are rejected with a sentence. |
| `StartPolicy` | `decide(User, JitsiContext): PolicyDecision` | v1.0: allow if user exists and is not a guest. v1.1: `access_mode = selected` ⇒ member of `allowed_groups` (core IAM groups, including directory groups) or in `allowed_users`. |
| `SessionService` | `start(User, StartRequest)`, `stop(User|System, sessionId, reason)`, `onTranscriberEvent(ref, Event)`, `onProsodyEvent(Event)`, `finalize(sessionId)` | Owns the state machine (02 §2). Writes audit. All transitions in one place; each one is a method with a unit test. |
| `SessionWatchdog` | `__invoke()` | Symfony Scheduler periodic task (60 s) registered by attribute; runs in the existing scheduler pod. |
| `ProsodyClient` | `health()`, `start(…)`, `stop(…)`, `room(…)` | 3 s connect / 5 s total timeout; HMAC headers; maps errors to `ProsodyError` with a reason code. |
| `WindowTranscriber` | `transcribe(Session, AudioWindow): WindowResult` | Validates size/duration/content type, writes a temp file, calls `AiFacade::transcribe($path, $owner->getId(), ['provider' => $row->getService(), 'model' => $providerFacingName, 'model_id' => $row->getId(), 'language' => …, 'prompt' => …])`. `$row` is the plugin's `speechModelId` or the owner's SOUND2TEXT default; the provider-facing name is the `model` string that default resolver already returns. A `User` is not a legal second argument. No row ⇒ gap `speech_unavailable`, no call. Deletes the file, applies `SegmentFilter`, stores the segment, returns the caption text. Rate-limited per session and per owner (`RateLimitService`). |
| `TranscriptRenderer` | `markdown(Session, Segment[]): string` | §8. Pure function, snapshot-tested. |
| `TranscriptWriter` | `write(Session, string $markdown): File` | `FileUploadService::uploadBatch([UploadedFile], $owner, $groupKey, 'vectorize', new UploadOptions(source: 'meeting', sourceId: 'jitsi:'.$meetingId.':'.$sessionId, originalName: $name))`. Fallback source `generated` until `MN-1b` is on `main`. |
| `FolderCatalog` | `list(User)`, `resolve(User, FolderChoice)` | Reads `GET /api/v1/files/groups` data through the same repository the controller uses; creating a folder = choosing a new group key label. |
| `StatusChecks` | `all(): StatusLine[]` | Jitsi connection (Prosody `health`), Transcriber (`/health` of the transcriber), Speech model (model exists, active, local or allowed cloud; one 1-second test clip in `MN-8`), Sign-in for the button (Keycloak discovery reachable, client id set). Each line: `ok | warn | error`, sentence, fix link. |
| `ConnectionSnippets` | `forOpendesk()`, `forTranscriber()`, `forKeycloak()` | Fills the templates in `deploy/` with this installation's URLs and secrets. Plain secrets only in the response to `POST admin/connection`. |
| `SpeechModelPicker` | `options(bool allowCloud)` | `ModelRepository` SOUND2TEXT rows, marked local/cloud; never hardcoded model names. |
| `Copy` | `t(key, locale, vars)` | Backend sentences used in `message` fields; same keys as the frontend files. |

PHP rules from `AGENTS.md` apply: `final readonly` services, strict types,
DBAL `bindValue()` then `executeQuery()`, explicit request accessors, full
OpenAPI annotations on every route (the plugin's routes appear in
`/api/doc`).

## 5. Settings (BCONFIG owner 0, group `P_meeting_notes`)

| Key | Type | Default | Admin label (en) | Notes |
|-----|------|---------|------------------|-------|
| `enabled` | bool | `0` | Show the Meeting notes button in Jitsi | Off ⇒ loader exits, routes answer 404 except admin. |
| `access_mode` | enum | `everyone` | Who can start meeting notes | v1.0 fixed to `everyone` (signed-in, not guests). v1.1 adds `selected`. |
| `allowed_groups` | json list | `[]` | Groups that can start | v1.1 |
| `allowed_users` | json list | `[]` | People who can start | v1.1 |
| `default_language` | enum | `de` | Meeting language | `auto`, `de`, `en`, `es`, `fr`, `tr` (+ any the model supports, later). |
| `offered_languages` | json list | `["de","en"]` | Languages to offer | The dialog shows these plus "Detect automatically" only if `auto` is in the list. |
| `default_folder` | string | `Meetings` | Default folder in Files | Label of the group key; created on first use. |
| `speech_model_id` | int\|null | null | Speech model | null ⇒ the starter's SOUND2TEXT default. |
| `allow_cloud_stt` | bool | `0` | Allow speech recognition outside your organisation | Off ⇒ only self-hosted models are offered and used. |
| `captions` | bool | `1` | Show live captions in Jitsi | Off ⇒ transcript only. |
| `profile` | enum | `balanced` | Notes quality | `fast` / `balanced` / `accurate` ⇒ transcriber window rules ([05 §4](./05_stt_quality.md#4-windowing)). |
| `notice_extra` | string ≤ 300 | `` | Extra sentence for the notice (e.g. privacy link) | Shown in the dialog and the chat notice. |
| `prosody_url` | url | `` | Jitsi connection address | e.g. `http://jitsi-prosody:5280/synaplan-notes/v1`. |
| `prosody_secret` | secret | — | — | Encrypted; created by **Connect openDesk**. |
| `transcriber_url` | url | `` | Transcriber address | For the status line only. |
| `transcriber_token` | secret | — | — | Encrypted; created by **Connect openDesk**. |
| `keycloak_issuer` | url | from `OIDC_DISCOVERY_URL` | Sign-in provider | Read-only display; overridable. |
| `keycloak_client_id` | string | `synaplan-meeting-notes` | Sign-in client for the button | |
| `jitsi_hosts` | json list | `[]` | Jitsi addresses allowed to use the button | `config` answers `enabled:false` for hosts not in the list. |

## 6. Admin page

Opened at **Plugins › Meeting notes** (admin view of the same page). One
column, `surface-card` blocks, house buttons and form controls.

**UI rules for a plain-JS plugin page.** The page is rendered by
`frontend/index.js` into Synaplan's DOM, so Synaplan's global classes apply
and are **mandatory**: buttons `btn-primary|btn-secondary|btn-danger px-4
py-2.5 rounded-lg text-sm font-medium`; inputs, selects and textareas carry
the full chain `w-full px-3 py-2 rounded-lg surface-card border
border-light-border/30 dark:border-dark-border/20 txt-primary text-sm
focus:outline-none focus:ring-2 focus:ring-[var(--brand)]`; text uses
`txt-primary` / `txt-secondary`; errors `text-sm text-red-600
dark:text-red-400`. Icons are the Heroicons outline 24 SVG paths of the
mapped glyphs, inlined (a plain-JS module cannot import the Vue
components). Confirmations use the host's dialog if the plugin context
exposes one; otherwise a small in-page confirm card, never `window.confirm`.
Check light, dark, V2 (`.design-v2`) and 320 px.

```text
┌ Meeting notes ─────────────────────────────────────────────────────────────┐
│ Written notes of Jitsi meetings. Speech becomes text on your servers;       │
│ audio is not kept.                                         [ On  ◯━━ ]     │
├ Status ────────────────────────────────────────────────────────────────────┤
│ ● Jitsi connection     Connected to Jitsi (module 1.0.0).                   │
│ ● Transcriber          Running. Last meeting today 10:02.                   │
│ ▲ Speech model         Whisper large-v3-turbo (your servers). Slow: 4.1 s   │
│                        for 10 s of speech. Notes will lag.  [Choose model]  │
│ ✕ Sign-in for button   The sign-in client "synaplan-meeting-notes" was not  │
│                        found. Create it with the Keycloak snippet below.    │
├ Settings ──────────────────────────────────────────────────────────────────┤
│ Who can start     ( • Everyone with an account )  (Selected people — v1.1)  │
│ Meeting language  [ Deutsch ▾ ]   Offer  [x] Deutsch [x] English [ ] …      │
│ Default folder    [ Meetings      ]                                         │
│ Speech model      [ Whisper large-v3-turbo (your servers) ▾ ]               │
│                   [ ] Allow speech recognition outside your organisation    │
│ Live captions     [x] Show captions in Jitsi                                │
│ Notes quality     ( ) Fast  (•) Balanced  ( ) Most accurate                 │
│ Notice            [ Our privacy notice: https://…                       ]   │
│                                                         [ Save settings ]   │
├ Connect openDesk ──────────────────────────────────────────────────────────┤
│ Paste these into your openDesk configuration. Secrets are shown once.       │
│ [ Create connection ]   (after: tabs Jitsi · Prosody · Jicofo · Keycloak ·  │
│                          Transcriber, each with [Copy])   [ Rotate secrets ]│
├ Meetings ──────────────────────────────────────────────────────────────────┤
│ 3 running · 41 saved this month · 1 failed (transcriber not reachable)      │
│ Today 10:02  standup   Anna Beispiel   running 12 min                       │
│ …                                                        [ Stop all ]       │
└────────────────────────────────────────────────────────────────────────────┘
```

- Turning **On** with a red status line is allowed but shows "The button will
  appear, but starting will fail until *Sign-in for button* is fixed."
- **Rotate secrets** consequence: "Running meeting notes stop. Paste the new
  values into openDesk before the next meeting."
- **Stop all** consequence: "Every running meeting notes session stops now.
  The notes so far are saved."
- The Meetings list never shows text or file links of other people.

## 7. Personal page

Opened at **Plugins › Meeting notes** for everyone who started notes once.

| Element | Content |
|---------|---------|
| Empty state | "Start meeting notes from the button in a Jitsi meeting. Your notes will be listed here and saved in Files." + **Open Files** (U5). |
| Row | Room, date/time, duration, state sentence, speakers count, **View file** (`EyeIcon`, the icon map's preview/view glyph; opens the file in Files), **Stop** while running (`StopCircleIcon` — not in the icon map yet; add the row "Stop a running job → `StopCircleIcon`" to `docs/FRONTEND_CONVENTIONS.md` in the same PR), **Remove** (`TrashIcon`, house delete styling) with consequence "Removes this entry. The file in Files stays." |
| Five questions on the row | Owner (you), who else (shared with … — from the file's shares), touches ("one file in Files › Meetings"), stop (Stop / Remove), where from ("Jitsi meeting *standup*"). |
| Failed row | The failure sentence + **Save again** when segments exist. |

## 8. Storage: the transcript file

File name: `YYYY-MM-DD HHmm <room>.md` (room sanitised, ≤ 60 chars); on a
collision append ` (2)`. Folder: the dialog's choice. Owner: the starter.

```markdown
# Meeting notes — standup

From Jitsi meeting **standup** on 4 October 2026, 10:02–10:41 (Europe/Berlin).
Started by Anna Beispiel. Language: German. Audio was not kept.

People heard: Anna Beispiel, Ben Muster, Guest 1

---

**10:02:12 · Anna Beispiel** — Guten Morgen zusammen, wir fangen mit dem Status an.

**10:02:21 · Ben Muster** — Die Migration ist fertig, offen ist nur das Monitoring.

> No notes from 10:14:05 to 10:16:40: speech recognition was not available.

**10:16:41 · Anna Beispiel** — …

---

Notes stopped at 10:41 by Anna Beispiel. 214 lines from 3 people.
```

- Consecutive segments of the same speaker within 3 s are merged into one
  paragraph; timestamps are local to the owner's time zone (fallback
  Europe/Berlin), ISO in a hidden HTML comment for machines:
  `<!-- t0=2026-10-04T08:02:12.340Z -->`.
- The file text is extracted and vectorized like an upload (process level
  `vectorize`), so it is an AI source immediately (J-MN-8).
- Provenance: `BSOURCE = meeting` (`MN-1b`), `BSOURCEID = jitsi:<meetingId>:<sessionId>`;
  Files shows the label **From a meeting**.

## 9. i18n

Two catalogues with the same keys: `meetingnotes-plugin/frontend/i18n/*.json`
(Synaplan pages, loaded like Synaform from `localStorage.language`) and
`jitsi/src/i18n/*.json` (loader, language from Jitsi's
`APP.store.getState()['features/base/i18n'].language`, then
`navigator.language`, then `en`). Backend `Copy` reads the frontend file.

Key groups: `admin.*`, `status.*`, `settings.*`, `connect.*`,
`sessions.*`, `states.*`, `errors.*`, `jitsi.button.*`, `jitsi.dialog.*`,
`jitsi.banner.*`, `jitsi.chat.*`, `file.*`. All five locales ship in the same
PR as the first paint (U1). Placeholder names identical across locales; a
parity test runs in the plugin CI (same idea as Synaplan's
`localeParity.spec.ts`).

## 10. Tests and CI

| Layer | Tool | Must cover |
|-------|------|------------|
| PHP unit | PHPUnit inside a pinned Synaplan checkout (CI clones `metadist/synaplan@<tag>`, copies the plugin to `plugins/meeting_notes`, runs the plugin's tests with the Synaplan test kernel) | Every state transition; policy; HMAC (good, wrong, expired, replay); window validation; renderer snapshots; writer calls `FileUploadService` with the right options; secrets never in responses except `POST admin/connection`. |
| PHP static | PHPStan level of Synaplan, over `meetingnotes-plugin/backend` | — |
| Loader | vitest + jsdom | State rendering for every session state, i18n parity, auth flow with a fake Keycloak, no `innerHTML` with server strings. |
| Prosody | busted with stubs for `room`, `module`, metadata component | start/stop/deny/allow, roster debounce, HMAC verification. |
| Contract | recorded bridge messages from [01 §3](./01_findings.md#3-spike-2026-10-04-bridge-transcription-on-the-dev-cluster) | transcriber parses `start` / `media` / `ping` / `session-end` exactly as `stable-11031` sends them. |
| E2E | Playwright, synthetic voices ([06 §5](./06_dev_environment.md#5-synthetic-meetings)) | J-MN-1 … J-MN-7. |
