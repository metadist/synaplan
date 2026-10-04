# 02 — Architecture

Read [00](./00_master_plan.md) §4 first. This file is the contract between
the four moving parts: **loader** (in the Jitsi page), **plugin** (in
Synaplan), **Prosody module**, **transcriber**.

---

## 1. Trust boundaries

```text
 Browser (untrusted)        Synaplan (trusted)        openDesk Jitsi (trusted, other team)
 ─────────────────────      ──────────────────        ─────────────────────────────────────
 loader  ── bearer ──────▶  plugin  ── HMAC ───────▶  Prosody (mod_synaplan_notes)
                            plugin  ◀── HMAC ───────  Prosody (roster, stopped, room ended)
                            plugin  ◀── token ──────  transcriber  ◀── WebSocket ── bridge
                                                       (same cluster network)       (Jicofo header)
```

- The browser never talks to Prosody's control API, never sees a secret,
  and never sends audio. It only asks Synaplan to start or stop.
- Prosody's control API and the transcriber are **cluster-internal**
  (ClusterIP). Nothing in them is exposed through an ingress.
- Synaplan's public plugin endpoints (`/public/…`) authenticate every call
  themselves (HMAC or bearer token, constant-time compare).

## 2. Session state machine

One **meeting session** per Jitsi conference instance (meeting id) at a time.

```text
            start ok                bridge connected        stop / session-end
 (none) ──────────────▶ starting ───────────────────▶ running ───────────────▶ stopping
   ▲                      │  │                           │  ▲                      │
   │       30 s no bridge │  │ Prosody refused            │  │ speech ok again      │ finalize
   │                      ▼  ▼                           ▼  │                      ▼
   │                    failed                         paused (speech down)    saved | saved_with_gaps
   │                                                                            | nothing_to_save | failed
   └──────────── a new session may start after a terminal state ──────────────────┘
```

| State | Meaning | Entered when | Left when |
|-------|---------|--------------|-----------|
| `starting` | Prosody accepted; waiting for the bridge. | `POST …/jitsi/sessions` and Prosody `start` returned 200. | Transcriber `connected` event ⇒ `running`; 30 s timeout ⇒ `failed` (plugin calls Prosody `stop`). |
| `running` | Audio windows arrive. | `connected`. | Stop paths ⇒ `stopping`; speech failures ⇒ `paused`. |
| `paused` | Audio arrives, speech-to-text fails; windows are counted as a gap. | 3 consecutive failed windows or engine reported down. | A window succeeds ⇒ `running`. |
| `stopping` | Flushing the last windows. | Owner Stop, Prosody `transcription-stopped` / `room-destroyed`, transcriber `session-end`, admin Stop all, plugin off. | Transcriber `finished` event, or 60 s after entering ⇒ finalize. |
| `saved` | File written, no gaps. | Finalize with ≥ 1 segment, 0 gaps. | terminal |
| `saved_with_gaps` | File written, gaps marked with times. | Finalize with gaps. | terminal |
| `nothing_to_save` | No speech recognised. | Finalize with 0 segments. | terminal |
| `failed` | Could not start, or the file could not be written. | see above | terminal |

**Watchdog** (plugin periodic task, every 60 s): `starting` older than 30 s ⇒
`failed`; `running`/`paused` without a window for 10 min **and** Prosody says
the room is gone or not transcribing ⇒ `stopping`; `stopping` older than 60 s
⇒ finalize. No session can stay non-terminal for more than ~11 minutes after
its meeting ended (Perfect-UX bar point 6).

## 3. Start and stop authority

| Action | Who | Path | Synaplan needed? |
|--------|-----|------|------------------|
| Start | Signed-in openDesk user who is **in the room** (v1.0); plus policy (v1.1) | Loader → plugin → Prosody `start` | yes |
| Stop (owner) | The person who started | Loader banner **Stop** → plugin → Prosody `stop` | yes |
| Stop (moderator) | Any Jitsi moderator | Jitsi's own "stop transcription" control → metadata `isTranscribingEnabled=false` → Prosody allows it → bridge `session-end` → transcriber → plugin | **no** for stopping; yes for saving (watchdog saves later) |
| Stop (everyone left) | — | Bridge `session-end`, Prosody `room-destroyed` | no for stopping |
| Stop all | Synaplan admin | Admin page → plugin → Prosody `stop` per running session | yes |
| Turn off | Synaplan admin | Plugin `enabled=0` ⇒ Stop all + loader config says off | yes |

Clients can **not** start transcription by setting `recording.isTranscribingEnabled=true`
themselves: `mod_synaplan_notes` denies that update unless the room has an
active Synaplan reference set by the control API ([04 §4](./04_jitsi_and_opendesk.md#4-prosody-module-mod_synaplan_notes)).
Clients **can** set it to `false` (moderators only, Jitsi's default rule) so a
stop never depends on Synaplan.

## 4. Data model (plugin, no schema change)

All rows use `plugin_data` (`plugin_name = 'meeting_notes'`) and `BCONFIG`.

| What | `user_id` | `data_type` | `data_key` | `data` (JSON) |
|------|-----------|-------------|------------|---------------|
| Session | owner | `session` | `ms_<16 hex>` | `{id, ref_hash, state, room, meeting_id, jitsi_host, language, folder_group_key, folder_label, profile, captions, started_at, connected_at, stopped_at, stop_reason, finished_at, file_id, gaps:[{from_ms,to_ms,reason}], segment_count, speakers:{endpointId:{name, user_sub?, guest}}, error}` |
| Reference index | `0` | `session_ref` | `sha256(ref)` | `{owner_id, session_id, meeting_id, created_at}` |
| Segment | owner | `segment` | `ms_<id>:<6-digit seq>` | `{seq, endpoint_id, speaker, t0_ms, t1_ms, text, language, confidence?, flags:[]}` |
| Room lock | `0` | `room_lock` | `sha256(jitsi_host + room)` | `{session_id, owner_id, meeting_id, since}` (one active session per room) |
| Audit | core `BAUDITLOG` via the existing audit service, events `meeting_notes.started / stopped / saved / failed / settings_changed / connection_rotated` (metadata only, no text). | | | |

Settings: `BCONFIG` owner `0`, group `P_meeting_notes` ([03 §5](./03_plugin_meeting_notes.md#5-settings-bconfig-owner-0-group-p_meeting_notes)).
Secrets in the same group, encrypted with core `EncryptionService`.

`ref` is 128 random bits, base64url (22 chars). Only its SHA-256 is stored;
the plain value lives in Prosody's room metadata (`transcription.urlParams`)
for the duration of the meeting and in the bridge URL. Room metadata is
broadcast to clients, so `ref` alone grants nothing: the transcriber
endpoints also require the transcriber token, which only the transcriber
holds. The loader never uses `ref`.

Segment volume: ~1 segment per speaker per 5–12 s ⇒ a 60-minute meeting with
4 active speakers ≈ 1,200 rows. Listing uses a prefix query on the unique
index (`user_id, plugin_name, data_type, data_key`) through a plugin
repository class. A dedicated table is a v1.1 option if volumes grow.

## 5. APIs

### 5.1 Loader → plugin (Keycloak bearer, CORS `*`, no cookies)

All under `/api/v1/plugins/meeting_notes/`. Behind the normal `^/api`
authentication (bearer), except `public/…`.

| Method & path | Purpose | Request | Responses |
|---------------|---------|---------|-----------|
| `GET public/jitsi/loader.js` | The loader bundle (ES module). Public, `Cache-Control: public, max-age=300`, `Content-Type: text/javascript`. Served whenever the plugin is installed; on/off is decided by `config`, so a cached loader still exits when the plugin is off. | — | 200 JS |
| `GET public/jitsi/config` | Is the button on, and how to sign in. Public, `max-age=60`. | `?host=meet.<domain>` | `{enabled:true, version, keycloak:{issuer, clientId}, api:"https://synaplan.<domain>/api/v1/plugins/meeting_notes", languages:["de","en",…], defaultLanguage:"de", captions:true}` or `{enabled:false}` |
| `GET jitsi/me` | Can this person start here; folders; defaults. | `?room=&meetingId=` | `{canStart:true, reason:null, displayName, folders:[{key,label}], defaultFolder:{key,label}, languages, defaultLanguage, running:null|{sessionId, startedBy, since, mine:bool}}`; `canStart:false` with `reason` in `guest | e2ee | breakout | not_allowed | running_elsewhere` |
| `POST jitsi/sessions` | Start. | `{room, meetingId, jitsiHost, language, folder:{key}|{newLabel}, e2ee:false, breakout:false}` | 201 `{sessionId, state:"starting"}`; 409 `{error:"already_running", startedBy, since}`; 403 `{error:"not_allowed", message}`; 422 `{error:"not_in_room"|"meeting_changed", message}`; 503 `{error:"jitsi_unreachable"|"transcriber_unreachable"|"speech_unavailable", message}` |
| `GET jitsi/sessions/{id}` | Poll own session (every 2 s while starting/stopping, 10 s while running). | — | `{sessionId, state, startedAt, segments, gaps, file:{id, name, folderLabel, url}|null, message}` |
| `POST jitsi/sessions/{id}/stop` | Stop own session. | — | 202 `{state:"stopping"}`; 404; 409 already terminal |

`message` fields are the one-sentence copy in the user's language (from the
`Accept-Language` header, fallback `en`), so the loader never composes error
text itself.

### 5.2 Synaplan app pages → plugin (cookie session, user-scoped)

Under `/api/v1/user/{userId}/plugins/meeting_notes/` (house pattern; checks
`#[CurrentUser]` = `{userId}`).

| Method & path | Who | Purpose |
|---------------|-----|---------|
| `GET sessions` | owner | Own sessions, newest first, with state, file link, message. |
| `GET sessions/{id}` | owner | One session + segment count + speakers. |
| `POST sessions/{id}/stop` | owner | Same as 5.1 stop. |
| `DELETE sessions/{id}` | owner | Removes the session record and segments. The file in Files stays (consequence copy says so). |
| `GET admin/status` | admin | The four status lines ([03 §6](./03_plugin_meeting_notes.md#6-admin-page)). |
| `GET admin/settings` · `PUT admin/settings` | admin | Settings. |
| `POST admin/connection` | admin | Create or rotate the Prosody secret and the transcriber token; returns the snippets **once** with the plain secrets. |
| `GET admin/sessions` | admin | Metadata only (who, room, when, state, duration). No text, no file links of others. |
| `POST admin/stop-all` | admin | Stop every running session. |

### 5.3 Plugin → Prosody (HMAC, cluster-internal)

Base URL from settings, e.g. `http://jitsi-prosody:5280/synaplan-notes/v1`.

| Method & path | Body | Answer |
|---------------|------|--------|
| `GET health` | — | `{ok:true, module:"synaplan_notes", version:"1.0.0", muc:"muc.meet.jitsi"}` |
| `POST start` | `{room, meetingId, ref, language, captions, startedBy:{userSub, name}, chatLine}` | 200 `{meetingId, occupants:[…]}`; 404 `room_not_found`; 409 `meeting_changed` (live meeting id differs) or `already_transcribing`; 403 `starter_not_in_room`; 422 `e2ee` |
| `POST stop` | `{room, ref, reason, chatLine}` | 200 `{stopped:true}`; unknown ref ⇒ 200 `{stopped:false}` (idempotent) |
| `POST state` | `{room, ref, state:"starting"|"on"|"paused"}` | 200; keeps `metadata.synaplanNotes.state` (the banner) in step with the session |
| `GET rooms/{room}` | — | `{exists, meetingId, transcribing, synaplanRefHash, occupants:[…]}` (watchdog) |

Occupant: `{endpointId, name, userSub|null, email|null, role:"moderator"|"participant", guest:bool}`.

### 5.4 Prosody → plugin (HMAC)

`POST /api/v1/plugins/meeting_notes/public/prosody/events`

```json
{ "type": "roster", "room": "standup", "meetingId": "997aef8e-…", "refHash": "…",
  "occupants": [{ "endpointId": "25236799", "name": "Demo One", "userSub": "585f7db3-…", "guest": false, "role": "moderator" }] }
```

`type` ∈ `roster` (on start, join, leave, display-name change; debounced 2 s),
`transcription-stopped` (`{by:"moderator"|"synaplan", name}`),
`room-destroyed`. Answer 204. Unknown `refHash` ⇒ 404 (Prosody logs and
gives up; no retries beyond 3 × backoff).

### 5.5 Transcriber → plugin (bearer token, cluster-internal source)

| Method & path | Purpose | Request | Response |
|---------------|---------|---------|----------|
| `GET public/transcriber/sessions/{ref}` | Bind a bridge connection to a session. Called once on connect and every 30 s. | header `Authorization: Bearer <transcriber token>`, `?meetingId=` | 200 `{sessionId, state, language, profile:"balanced"|"fast"|"accurate", captions, glossary:[…], speakers:{endpointId:{name}}, excluded:[endpointId], maxConcurrency}`; 404 unknown ref; 409 meeting id mismatch or terminal ⇒ transcriber closes the socket (bridge reconnects are ignored) |
| `POST public/transcriber/sessions/{ref}/audio` | One speech window. **The only way audio enters Synaplan.** | `multipart/form-data`: `audio` (Ogg/Opus, ≤ 2 MB, ≤ 30 s), `endpointId`, `seq`, `t0Ms`, `t1Ms` (relative to session start), `cut` (`pause`/`soft`/`hard`/`stop`), `prompt` (≤ 500 chars), `Idempotency-Key` header `<ref-hash8>:<endpointId>:<seq>` | 200 `{seq, text, language, speaker, dropped:null|"no_speech"|"hallucination"|"excluded"|"duplicate", segmentSeq}`; 409 session not accepting (stopping/terminal); 429/503 with `Retry-After` (speech busy or down; the plugin also records the gap) |
| `POST public/transcriber/sessions/{ref}/events` | Lifecycle. | `{type:"connected"|"speaker-start"|"speaker-stop"|"stt-failure"|"session-end"|"finished", endpointId?, at, detail?}` | 204 |

The plugin transcribes with `AiFacade::transcribe()` **as the session owner**
(usage metering and rate limits on the owner, model from plugin settings or
the owner's SOUND2TEXT default), writes the segment, then answers. The temp
audio file is deleted in a `finally`. No audio is logged.

### 5.6 Transcriber ↔ bridge (Jitsi protocol, verified)

Inbound: `start`, `media`, `ping`, `stop`, `session-end` (see
[01 §3](./01_findings.md#3-spike-2026-10-04-bridge-transcription-on-the-dev-cluster)).
Outbound: `pong`, and when captions are on:

```json
{ "type": "transcription-result", "event": "transcription-result",
  "message_id": "<sessionId>:<endpointId>:<seq>", "is_interim": false,
  "participant": { "id": "25236799", "name": "Demo One" },
  "transcript": [{ "text": "Wir fangen mit dem Status an.", "confidence": 0.92 }],
  "language": "de", "timestamp": 1791121908264 }
```

`participant.id` is the **endpoint id** (not the tag), `name` comes from the
roster. `language` is the session language (two-letter); see
[04 §6](./04_jitsi_and_opendesk.md#6-jitsi-configjs) for the matching client setting.

## 6. Identity in the Jitsi page

| Option | Verdict |
|--------|---------|
| Synaplan session cookie | **No.** `SameSite=Strict` cookie is not usable with `allow_origin:*` CORS (no credentials); changing CORS globally is a security regression. |
| Synaplan page in an iframe | **No** for v1. `X-Frame-Options: SAMEORIGIN` on every page; would need a Caddy change and frame-ancestor config; login inside iframes is fragile. |
| Trust the Jitsi token (HS256) | **No.** Synaplan would need the Jitsi app secret, which can mint tokens for any room. Too much power for a notes feature. |
| **Keycloak public client** `synaplan-meeting-notes` | **Yes.** Authorization code + PKCE with `prompt=none` in a hidden iframe; the redirect lands on a small static page **on the Jitsi origin**. |

**Chosen flow (D3):**

1. Keycloak client `synaplan-meeting-notes`: public, standard flow only,
   PKCE `S256` required, redirect URI
   `https://meet.<domain>/static/synaplan-notes-silent.html`, web origin
   `https://meet.<domain>`. Mappers: audience `synaplan`, `sub` (Nubus realms
   have no `basic` scope), `email`, `full name`. Default scopes
   `profile email`. Token lifetime is the realm default (minutes).
2. `synaplan-notes-silent.html` (≈ 20 lines, shipped in the plugin repo under
   `jitsi/static/`, mounted next to openDesk's own `body.html`) reads `code`,
   `state` or `error` from its URL and calls
   `window.parent.postMessage({...}, location.origin)`. Same origin as the
   loader, so no cross-origin messaging is involved.
3. The loader opens a hidden iframe to Keycloak's `auth` endpoint with
   `prompt=none`, waits ≤ 5 s for the message, then exchanges the code at the
   token endpoint (CORS allowed through the web origin). The access token is
   kept in memory only and renewed the same way before it expires.
4. If Keycloak answers `login_required` (rare: the person is already signed
   in to Jitsi through Keycloak), the dialog shows **Sign in to continue**,
   which runs the same request in a popup started from that click.
5. Calls to `/api/v1/plugins/meeting_notes/jitsi/*` carry
   `Authorization: Bearer <token>`. Synaplan's `OidcBearerAuthenticator`
   validates issuer, signature and audience, and maps the person to their
   Synaplan account (creating it on first use, like any SSO login).

Why the silent page is on the Jitsi origin and not on Synaplan: Synaplan's
Caddy sets `X-Frame-Options: SAMEORIGIN` on every response, so a Synaplan
page cannot complete inside an iframe on `meet.<domain>`. One extra static
file next to the loader include is a smaller change than a framing exception
in Synaplan. `MN-0` verifies the flow in Chromium, Firefox and Safari.

## 7. Failure handling

| What fails | When | What the person sees | What happens |
|------------|------|----------------------|--------------|
| Plugin off or Synaplan down | page load | No button. | Loader: one `config` call, fails or `enabled:false`, exits. |
| Keycloak silent sign-in | dialog open | "Sign in to continue" with a button (popup). | — |
| Prosody unreachable / module missing | Start | "Meeting notes could not start. Nothing is being written down. Try again in a minute or ask your administrator." | Session `failed` (`jitsi_unreachable`), audit. |
| Transcriber never connects (30 s) | after Start | The starter sees the same sentence. Others saw "Meeting notes are starting…" for at most 30 s, then the chat line "Meeting notes could not start. Nothing was written down." | Plugin calls Prosody `stop`; session `failed` (`transcriber_unreachable`). |
| Speech engine down mid-meeting | running | Banner: "Meeting notes are paused: speech recognition is not available. The meeting continues." | Windows answered 503; plugin records gap `[t0,t1]`; state `paused`; resumes on first success; file says "No notes from 10:14 to 10:17: speech recognition was not available." |
| Transcriber crashes mid-meeting | running | Banner unchanged for ≤ 30 s, then paused text. | Bridge reconnects the WebSocket (Exporter reconnect); the transcriber binds again via `ref`; gap recorded for the missing time. |
| Synaplan down mid-meeting | running | Captions stop; banner state from metadata still "on". | Transcriber retries windows for 60 s, then marks them lost and keeps going; on `session-end` it retries `finished` for 10 min. Watchdog finalizes when Synaplan is back. |
| File write fails | finalize | Personal page and toast: "Your notes could not be saved to Files. The text is kept here for 7 days. Try again." with **Save again**. | Session `failed` with segments kept; retry button reruns finalize. |
| Two people press Start | Start | Second: "Meeting notes are already on in this meeting, started by Anna." | Room lock row; Prosody `already_transcribing`. |

Copy in all five locales lives in the plugin i18n files and the loader bundle
([04 §3](./04_jitsi_and_opendesk.md#3-copy-en--de)).

## 8. Security and privacy

| Threat | Treatment |
|--------|-----------|
| Covert notes | Prosody posts a chat line on start and stop to everyone, sets metadata that every loader turns into a banner, and Jitsi shows its own indicator to every client including mobile apps. The plugin cannot start without Prosody doing all three. |
| Starting notes in a meeting you are not in | Prosody checks that `startedBy.userSub` is the token identity of a current occupant and that `meetingId` is the live one. |
| Forged roster / events | HMAC-SHA256 over `timestamp + method + path + sha256(body)`, ±60 s window, nonce cache 5 min. |
| Audio injection into someone's notes | Audio only via `/public/transcriber/…` with the transcriber token **and** a live `ref`; `ref` is unguessable and bound to the meeting id; windows for terminal sessions are refused. |
| Audio retention | Transcriber keeps ≤ 30 s per speaker in memory; plugin writes a temp file per window and deletes it in `finally`; no audio in logs, metrics or exceptions. Tested ([07 MN-5/MN-3](./07_sprints.md)). |
| Prompt injection via speech | Transcript is user data; nothing executes it. Summaries (later) are a separate, approvable Saved Task. |
| Cloud speech on a sovereign install | Plugin setting `allow_cloud_stt` default **off**; the model picker then lists only self-hosted models; Start refuses with "No speech model on your organisation's servers is set up." |
| Admin reads others' meetings | Admin list shows metadata only; files belong to their owners (core rule: admins do not see others' files unless shared). |
| Loader script compromise | Served by Synaplan from the plugin directory (read-only mount), same site as Jitsi; versioned; renders text only (no `innerHTML` with server strings) inside a Shadow DOM. |
| E2EE meetings | Loader detects `features/e2ee.enabled` and offers no Start; Prosody refuses `start` if the room has E2EE flagged. |

Legal note for the admin docs (not legal advice): employee meetings may need
a works-council or staff-council agreement and a data-protection impact
assessment; the visible banner, chat notice and stop are the technical
baseline, v1.1 adds per-person opt-out.

## 9. Capacity (starting numbers, measured in `MN-8`)

| Item | Per active speaker | Notes |
|------|--------------------|-------|
| Bridge → transcriber | ~30 kbit/s Opus | measured in the spike |
| Transcriber CPU | Opus decode + voice detection, < 2 % of a core | WASM decoder |
| Synaplan requests | 1 per 5–12 s of speech | one PHP request per window |
| GPU (Whisper large-v3-turbo, f16, whisper-server) | **0.17 s per 12.5 s utterance, ~72× real time, 2.5 GB VRAM** (measured on an RTX PRO 6000 Blackwell, 2026-10-04) | one server process ≈ 50+ meetings with one active speaker each; concurrency measured in `MN-8c` |
| CPU (whisper.cpp large-v3-turbo q5, 8 threads) | ~6–10 s per 10 s window (estimate) | dev only |

## 10. Observability

Transcriber `/metrics` (Prometheus): `mn_sessions_active`,
`mn_windows_total{result}`, `mn_window_seconds` (histogram of audio length),
`mn_stt_latency_seconds`, `mn_gap_seconds_total`, `mn_bridge_reconnects_total`.
Plugin: audit events + a `GET admin/status` that reads the transcriber's
`/health`. Logs carry session id, endpoint id, sequence, timings — never text
or audio.
