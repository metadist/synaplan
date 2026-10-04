# 04 — Jitsi and openDesk side

What changes inside openDesk, and what people see in a meeting. Nothing
here forks Jitsi or edits openDesk's own files: we add one script include,
one static page, a `config.js` block, one Prosody module, one Jicofo block
and one Keycloak client.

---

## 1. The loader

One ES module, `jitsi-loader.js`, built from `jitsi/src` (TypeScript, Vite
library mode, no runtime dependencies, target ≤ 40 kB gzip including five
locales). Served by the plugin at
`https://synaplan.<domain>/api/v1/plugins/meeting_notes/public/jitsi/loader.js`.

### 1.1 Boot sequence

1. Included at the end of Jitsi's page (see §7). Runs once per page.
2. `GET …/public/jitsi/config?host=<location.host>` (timeout 3 s). On error,
   non-200, or `enabled:false` ⇒ **stop. No DOM, no further requests.**
   (J-MN-6, U11.)
3. Wait for `APP.conference.isJoined()` (event-driven: listen to the
   conference `JOINED` event via `APP.conference._room` once it exists; a
   one-second feature probe loop for at most 120 s is acceptable only to find
   `APP.conference`, as openDesk's own `body.html` does).
4. Read context: `roomName`, `getMeetingUniqueId()`, local endpoint id,
   `features/base/jwt.user` (null ⇒ guest), `features/e2ee.enabled`,
   breakout state, local role, UI language.
5. Render the button (§2) and subscribe to `METADATA_UPDATED` to render the
   banner (§2.3) from `metadata.synaplanNotes`.

### 1.2 Isolation

- Everything lives in one host element `<synaplan-meeting-notes>` with a
  closed Shadow DOM. Styles are inside the shadow root; no global CSS.
- Text is set with `textContent`; server strings never go through
  `innerHTML`.
- The loader touches Jitsi only through: `APP.conference` (read),
  `APP.store.getState()` (read), `getMetadataHandler()` (read; write only
  `recording.isTranscribingEnabled=false` for a moderator stop), and
  `APP.store.dispatch({type:'TOGGLE_REQUESTING_SUBTITLES'})` once to switch
  captions on for the starter if they are off (verify action name in `MN-0`).
- All of these are wrapped in `jitsi.ts` with feature detection. If any is
  missing, the button is not shown and one `console.info` line explains why.
  A contract test runs the loader against the pinned Jitsi version on every
  openDesk bump.

## 2. What people see

### 2.1 The button

```text
 ┌───────────────────────────────────────────────────────────── Jitsi ─┐
 │ openDesk bar                                                        │
 │                                                                     │
 │                         (video)                                     │
 │                                                                     │
 │ ┌───────────┐                                                       │
 │ │ ▤ Notes   │  ← bottom-left, 16 px from left, above the toolbox    │
 │ └───────────┘                                                       │
 │            [ mic ][ cam ][ share ] … Jitsi toolbox … [ hang up ]    │
 └─────────────────────────────────────────────────────────────────────┘
```

| State | Look | Click |
|-------|------|-------|
| idle, can start | Small pill with `DocumentTextIcon` + "Meeting notes"; appears with Jitsi's toolbox (mouse move / tap) and hides with it. | Opens the dialog. |
| idle, cannot start (guest, E2EE, breakout, not allowed) | Hidden for guests and not-allowed (no dead control, U11). E2EE / breakout: pill shown, dimmed, `aria-disabled`. | E2EE / breakout: a small popover with the one-sentence reason. |
| starting | Pill with spinner "Starting…" (always visible). | — |
| on | Red dot + "Notes on · 12:31" (elapsed), always visible, even when the toolbox hides. | Opens the banner details with **Stop** (starter, moderators). |
| paused | Amber dot + "Notes paused". | Details with the reason. |
| stopping | Spinner "Saving…". | — |
| saved (starter only) | Toast for 15 s (§2.4), then idle. | — |

Position and toolbox sync are decided in `MN-0` against `stable-11031`
(the toolbox, filmstrip and openDesk bar must not be covered at 1280 px,
768 px and 320 px). Keyboard: the pill is a `<button>` in tab order after
Jitsi's toolbox; visible focus ring.

### 2.2 The dialog

```text
┌ Start meeting notes ─────────────────────────────── ✕ ┐
│ Synaplan writes down what is said in this meeting.     │
│ Everyone here will see that notes are on.              │
│ Audio is not kept.                                     │
│                                                        │
│ Meeting language   [ Deutsch                     ▾ ]   │
│ Save to            [ Meetings (Files)            ▾ ]   │
│                    · Meetings                          │
│                    · Projekt Atlas                     │
│                    · New folder…                       │
│                                                        │
│ (admin notice line, if set)                            │
│                                                        │
│            [ Cancel ]   [ Start meeting notes ]        │
│ Signed in as Demo One · Notes are saved in your Files  │
└────────────────────────────────────────────────────────┘
```

- Opens with a skeleton while `GET jitsi/me` runs; silent sign-in happens
  before (≤ 5 s); on `login_required` the body is replaced by "Sign in to
  continue" + **Sign in**.
- Defaults: language and folder from the admin settings, then the person's
  last choice (stored in `localStorage` on the Jitsi origin, key
  `synaplan.meetingNotes.last`).
- **New folder…** reveals one text input (house field style inside the
  shadow root, same metrics) with the placeholder "Folder name".
- `canStart:false` replaces the form with the reason sentence and **Close**.
- 409 `already_running`: "Meeting notes are already on in this meeting,
  started by {name}." + **Close**.
- Width 420 px, full width minus 16 px below 480 px; focus trap; `Esc` and ✕
  close; first focus on the language select; `role="dialog"`,
  `aria-modal="true"`, labelled by the title.

### 2.3 The banner (everyone who has the loader)

Driven only by room metadata, so it works for every participant without a
Synaplan call:

`metadata.synaplanNotes = { state: "on" | "starting" | "paused" | "off", startedBy: "Anna Beispiel", since: "2026-10-04T08:02:11Z", language: "de" }`

```text
        ┌──────────────────────────────────────────────────────────┐
        │ ● Meeting notes are on · started by Anna Beispiel · 10:02 │  [Stop]  [–]
        └──────────────────────────────────────────────────────────┘
```

- Top centre, under the openDesk bar; collapses to the pill after 10 s
  (`[–]`), re-expands on every state change. Never fully hidden while on.
- **Stop** visible for the starter (session mine, from `jitsi/me`) and for
  moderators (local role). Starter ⇒ plugin stop; moderator ⇒ metadata
  `isTranscribingEnabled=false` (works without Synaplan).
- `aria-live="polite"` announces state changes.

Participants without the loader (Jitsi mobile apps, other clients) see
Jitsi's own transcription indicator and the chat lines from §4.

### 2.4 After Stop (starter)

Toast: "Saved to Files › Meetings › 2026-10-04 1002 standup.md" with
**Open** (`https://synaplan.<domain>/files?…` deep link to the file; exact
route decided in `MN-7`, must select the folder and highlight the file).
Variants per final state in §3.

## 3. Copy (en / de)

All five locales ship in the same PR (es, fr, tr written in `MN-7` and
reviewed by a native reader). Sentences end with a period; no HTTP codes,
no product-internal words.

| Key | en | de |
|-----|----|----|
| `jitsi.button.label` | Meeting notes | Mitschrift |
| `jitsi.button.on` | Notes on · {elapsed} | Mitschrift läuft · {elapsed} |
| `jitsi.button.paused` | Notes paused | Mitschrift pausiert |
| `jitsi.button.saving` | Saving… | Wird gespeichert… |
| `jitsi.dialog.title` | Start meeting notes | Mitschrift starten |
| `jitsi.dialog.intro` | Synaplan writes down what is said in this meeting. Everyone here will see that notes are on. Audio is not kept. | Synaplan schreibt mit, was in dieser Besprechung gesagt wird. Alle hier sehen, dass die Mitschrift läuft. Audio wird nicht gespeichert. |
| `jitsi.dialog.language` | Meeting language | Sprache der Besprechung |
| `jitsi.dialog.auto` | Detect automatically | Automatisch erkennen |
| `jitsi.dialog.folder` | Save to | Speichern in |
| `jitsi.dialog.newFolder` | New folder… | Neuer Ordner… |
| `jitsi.dialog.start` | Start meeting notes | Mitschrift starten |
| `jitsi.dialog.cancel` | Cancel | Abbrechen |
| `jitsi.dialog.signedIn` | Signed in as {name} · Notes are saved in your Files | Angemeldet als {name} · Die Mitschrift wird in Ihren Dateien gespeichert |
| `jitsi.dialog.signIn` | Sign in to continue. | Melden Sie sich an, um fortzufahren. |
| `jitsi.reason.guest` | Guests cannot start meeting notes. | Gäste können keine Mitschrift starten. |
| `jitsi.reason.e2ee` | Meeting notes don't work in end-to-end encrypted meetings. | In Ende-zu-Ende-verschlüsselten Besprechungen ist keine Mitschrift möglich. |
| `jitsi.reason.breakout` | Meeting notes work in the main room only. | Die Mitschrift funktioniert nur im Hauptraum. |
| `jitsi.reason.notAllowed` (v1.1) | Your administrator has not allowed you to start meeting notes. | Ihre Administration hat Ihnen das Starten der Mitschrift nicht freigegeben. |
| `jitsi.error.alreadyRunning` | Meeting notes are already on in this meeting, started by {name}. | In dieser Besprechung läuft bereits eine Mitschrift, gestartet von {name}. |
| `jitsi.error.cannotStart` | Meeting notes could not start. Nothing is being written down. Try again in a minute or ask your administrator. | Die Mitschrift konnte nicht starten. Es wird nichts mitgeschrieben. Versuchen Sie es in einer Minute erneut oder wenden Sie sich an Ihre Administration. |
| `jitsi.error.noLocalModel` | No speech recognition on your organisation's servers is set up. Nothing is being written down. | Auf den Servern Ihrer Organisation ist keine Spracherkennung eingerichtet. Es wird nichts mitgeschrieben. |
| `jitsi.banner.on` | Meeting notes are on · started by {name} · {time} | Mitschrift läuft · gestartet von {name} · {time} |
| `jitsi.banner.starting` | Meeting notes are starting… | Mitschrift wird gestartet… |
| `jitsi.banner.paused` | Meeting notes are paused: speech recognition is not available. The meeting continues. | Mitschrift pausiert: Die Spracherkennung ist nicht verfügbar. Die Besprechung läuft weiter. |
| `jitsi.banner.stop` | Stop | Beenden |
| `jitsi.toast.saved` | Saved to Files › {folder} › {file} | Gespeichert in Dateien › {folder} › {file} |
| `jitsi.toast.savedGaps` | Saved to Files › {folder} › {file}. Some parts are missing; the file says where. | Gespeichert in Dateien › {folder} › {file}. Einige Abschnitte fehlen; die Datei nennt die Zeiten. |
| `jitsi.toast.nothing` | Meeting notes stopped. No speech was recognised, so no file was saved. | Mitschrift beendet. Es wurde keine Sprache erkannt, daher wurde keine Datei gespeichert. |
| `jitsi.toast.failed` | Your notes could not be saved to Files. They are kept in Synaplan for 7 days. Open Meeting notes to save them again. | Ihre Mitschrift konnte nicht in Dateien gespeichert werden. Sie bleibt 7 Tage in Synaplan. Öffnen Sie „Mitschrift“, um sie erneut zu speichern. |
| `jitsi.toast.open` | Open | Öffnen |
| `jitsi.chat.started` | Meeting notes are on, started by {name}. Speech in this meeting is written down; audio is not kept. A moderator can stop it at any time. | Die Mitschrift läuft, gestartet von {name}. Was hier gesagt wird, wird mitgeschrieben; Audio wird nicht gespeichert. Moderierende können sie jederzeit beenden. |
| `jitsi.chat.stopped` | Meeting notes stopped by {name}. | Mitschrift beendet von {name}. |
| `jitsi.chat.failed` | Meeting notes could not start. Nothing was written down. | Die Mitschrift konnte nicht starten. Es wurde nichts mitgeschrieben. |
| `jitsi.chat.off` | Meeting notes were turned off by your administrator. The notes so far are saved. | Die Mitschrift wurde von der Administration abgeschaltet. Das bisher Mitgeschriebene ist gespeichert. |

The chat lines are sent by Prosody, which has no translation catalogue: the
plugin passes the line in the **starter's** language in the `start`/`stop`
call; v1.1 sends per-occupant language if Prosody can read it.

## 4. Prosody module `mod_synaplan_notes`

Loaded on the main VirtualHost (`XMPP_MODULES` in docker-jitsi-meet) and
attaching to the MUC component with Jitsi's `process_host_module` helper,
the same pattern Jitsi's own modules use.

### 4.1 Configuration (one extra `conf.d` file or env)

```lua
synaplan_notes_secret = os.getenv("SYNAPLAN_NOTES_SECRET")          -- required
synaplan_notes_callback_url = os.getenv("SYNAPLAN_NOTES_CALLBACK_URL") -- …/public/prosody/events
synaplan_notes_muc = "muc.meet.jitsi"                                -- MUC component
synaplan_notes_http_path = "synaplan-notes"                          -- http://<prosody>:5280/synaplan-notes/v1/*
```

Missing secret ⇒ the module logs one error and serves only `GET /v1/health`
with `{ok:false, reason:"secret missing"}`.

### 4.2 Behaviour

| Hook / route | Behaviour |
|--------------|-----------|
| `POST /v1/start` | Verify HMAC. Find the room on the MUC. Check `room._data.meetingId == body.meetingId` (else 409 `meeting_changed`), the starter's `userSub` is the `jitsi_meet_context_user.id` of a current occupant (else 403), no active Synaplan ref (else 409), no E2EE flag (else 422). Then set `jitsiMetadata.asyncTranscription = true`, merge `recording.isTranscribingEnabled = true`, set `transcription = { urlParams = { notes = ref, lang = language } }` and `synaplanNotes = { state = "starting", startedBy, since, language }`, remember `room._synaplan_ref`, fire `room-metadata-changed` on the MUC host, send the chat line, schedule a roster callback, answer 200 with the roster. |
| `POST /v1/stop` | Verify HMAC and ref. Set `recording.isTranscribingEnabled = false`, `synaplanNotes.state = "off"`, remove `transcription` and `room._synaplan_ref`, fire `room-metadata-changed`, send the chat line. Idempotent. |
| `GET /v1/rooms/{room}` | Verify HMAC. Existence, live meeting id, transcribing flag, ref hash, roster. |
| `POST /v1/state` | Verify HMAC and ref. Set `synaplanNotes.state` to `on`, `paused` or `starting` (session state from the plugin, so the banner follows it), fire `room-metadata-changed`. No chat line. |
| `GET /v1/health` | No auth. `{ok, version, muc}`; no room data. |
| `jitsi-metadata-allow-moderation` for key `recording` | If `data.isTranscribingEnabled == true` and the room has no `_synaplan_ref` ⇒ return `false` (deny; Synaplan is the only starter). If it is `false` ⇒ return `nil` (default moderator rule applies, so moderators can stop). When a moderator stops: set `synaplanNotes.state = "off"`, remove `transcription` and `room._synaplan_ref` (so nobody can restart from the client without Synaplan), send the stop chat line, callback `transcription-stopped` with the moderator's name. |
| `jitsi-metadata-allow-moderation` for key `synaplanNotes` | Always `false` (server-only key). |
| `muc-occupant-joined` / `-left` / nick change | If the room has a ref: debounce 2 s, callback `roster`. |
| `muc-occupant-pre-change` (Prosody fires this when an occupant's presence is updated) | While `room._synaplan_ref` is set: if the presence shows E2EE on, run the same stop as `POST /v1/stop` with reason `e2ee`. The marker is the one the Jitsi client already publishes as `features/e2ee.enabled`; `MN-4` records one presence stanza from the dev cluster with E2EE switched on and matches that stanza, not a guessed tag. Then `synaplanNotes.state = "off"`, `transcription` and `_synaplan_ref` cleared, chat line `jitsi.reason.e2ee`, callback `transcription-stopped` `{by:"e2ee", name}`. Idempotent: a second presence with E2EE still on does not send a second callback. The plugin finalizes; the file ends with "Notes stopped because the meeting became end-to-end encrypted." |
| `muc-room-destroyed` | If the room has a ref: callback `room-destroyed`. |

Chat lines use Jitsi's system-message format (`json-message` with
`type: "system_chat_message"`, display name "Meeting notes"), sent to every
occupant; `MN-0` confirms the client renders it as a system line.

Callbacks: `net.http` POST, 3 tries with 1/3/9 s backoff, HMAC header
`X-Synaplan-Signature: t=<unix>,sig=<hex hmac_sha256(secret, t.."\n"..method.."\n"..path.."\n"..sha256hex(body))>`.

Tests: busted specs with a fake room (`jitsiMetadata`, occupants with
sessions), fake metadata component event bus, fake `net.http`.

## 5. Jicofo

```hocon
# /config/custom-jicofo.conf  (included by the image's jicofo.conf)
jicofo {
  transcription {
    url-template = "ws://synaplan-transcriber.<ns>.svc:8095/transcribe?sessionId={{MEETING_ID}}&sendBack=true"
    http-headers {
      "Authorization" = "Bearer "${SYNAPLAN_TRANSCRIBER_TOKEN}
    }
  }
}
```

`SYNAPLAN_TRANSCRIBER_TOKEN` comes from a Kubernetes Secret as an env var
of the Jicofo container (HOCON env substitution; verify the concatenation
syntax in `MN-0`, otherwise render the file from a template in an init
step). The per-room `notes` and `lang` parameters are appended by Jicofo
from the room metadata ([01 §3](./01_findings.md#3-spike-2026-10-04-bridge-transcription-on-the-dev-cluster)).

## 6. Jitsi `config.js`

```js
config.transcription = {
  enabled: true,              // captions UI and transcription state in the client
  useAppLanguage: true,       // viewer's captions language follows the Jitsi UI language
  autoCaptionOnTranscribe: true // show captions when transcription starts (verify key in MN-0)
};
```

Captions are shown to a viewer only if the result `language` matches the
viewer's subtitle language ([01 §3](./01_findings.md#3-spike-2026-10-04-bridge-transcription-on-the-dev-cluster)).
Rules for v1.0:

- The transcriber sets `language` to the **session language**.
- With `useAppLanguage`, a German UI shows German captions; an English UI in
  a German meeting shows none (Jitsi's behaviour; translation is later).
- If `MN-0` finds the matching too strict, set `preferredLanguage` from the
  room language through the loader for viewers who have not picked one.
- Admin switch `captions = off` ⇒ the transcriber sends no results; Jitsi
  still shows its transcribing indicator.

Jitsi's own "Start transcription" control: with Prosody denying client
starts it would fail silently, so `MN-0` decides between hiding it through
config (preferred) or a CSS rule in `plugin.head.html`. Jitsi's stop
control stays.

## 7. Including the loader

Preferred (no edit of openDesk's files): one line appended to Jitsi's
`custom-config.js`, which openDesk already mounts and which runs in `<head>`
before the app:

```js
(function () {
  var s = document.createElement('script');
  s.type = 'module';
  s.src = 'https://synaplan.<domain>/api/v1/plugins/meeting_notes/public/jitsi/loader.js';
  s.crossOrigin = 'anonymous';
  document.head.appendChild(s);
})();
```

Plus the static file `static/synaplan-notes-silent.html` mounted next to
openDesk's other static files. `MN-0` / `MN-12` find the openDesk values
hook that **appends** to `custom-config.js` instead of replacing it; the
fallbacks, in order: an extra `<script>` in `body.html` through the same
ConfigMap mechanism, then an ingress `sub_filter`.

## 8. Keycloak client

`deploy/keycloak/synaplan-meeting-notes-client.json`:

```json
{
  "clientId": "synaplan-meeting-notes",
  "name": "Synaplan meeting notes (Jitsi button)",
  "enabled": true,
  "protocol": "openid-connect",
  "publicClient": true,
  "standardFlowEnabled": true,
  "implicitFlowEnabled": false,
  "directAccessGrantsEnabled": false,
  "serviceAccountsEnabled": false,
  "redirectUris": ["https://meet.<domain>/static/synaplan-notes-silent.html"],
  "webOrigins": ["https://meet.<domain>"],
  "attributes": { "pkce.code.challenge.method": "S256", "post.logout.redirect.uris": "+" },
  "defaultClientScopes": ["web-origins", "profile", "email"],
  "protocolMappers": [
    { "name": "audience-synaplan", "protocol": "openid-connect", "protocolMapper": "oidc-audience-mapper",
      "config": { "included.client.audience": "synaplan", "access.token.claim": "true", "id.token.claim": "false" } },
    { "name": "sub", "protocol": "openid-connect", "protocolMapper": "oidc-usermodel-property-mapper",
      "config": { "user.attribute": "id", "claim.name": "sub", "jsonType.label": "String", "access.token.claim": "true", "id.token.claim": "true", "userinfo.token.claim": "true" } },
    { "name": "groups", "protocol": "openid-connect", "protocolMapper": "oidc-group-membership-mapper",
      "config": { "claim.name": "groups", "full.path": "false", "access.token.claim": "true", "id.token.claim": "true", "userinfo.token.claim": "true" } }
  ]
}
```

The `groups` mapper lets Synaplan's directory-group sync work on first
sign-in through the button, so v1.1 group limits see the same groups as a
normal Synaplan login.

## 9. Edge cases

| Case | v1.0 behaviour |
|------|----------------|
| Meeting embedded in Element (Jitsi in a widget iframe) | Same Jitsi page ⇒ loader runs; silent sign-in works same-site; verified in `MN-0`. |
| Jitsi mobile app | No loader; transcribed like everyone (bridge); sees Jitsi's indicator and the chat lines; cannot start. |
| Guest (no token) | Transcribed (bridge), sees banner and chat lines, no Start. Listed as "Guest 1, Guest 2" (display name if set, marked "guest"). |
| E2EE switched on during notes | `muc-occupant-pre-change` ([§4.2](#42-behaviour)) stops notes, posts the e2ee sentence and callbacks `{by:"e2ee"}` once. The file ends with "Notes stopped because the meeting became end-to-end encrypted." |
| Breakout rooms | No Start in breakout rooms. Main-room notes continue; people in breakouts are not heard (bridge sends what the main conference has). |
| Room re-created with the same name | New meeting id ⇒ a new session; the old one is already terminal (room lock released on finalize). |
| Several Jitsi deployments on one Synaplan | `jitsi_hosts` lists allowed hosts; each has its own Prosody URL and secret (v1.1; v1.0 supports one). |
