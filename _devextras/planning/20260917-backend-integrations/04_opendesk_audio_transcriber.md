# openDesk audio transcriber — master plan

**Status:** Draft 2026-09-17. Flagship of Wave 6. No media-path code
until §0 here **and** [`00_master_plan.md`](./00_master_plan.md) §0
are ticked.
**Product name (en):** **Meeting notes** in primary copy; **Transcriber**
in docs. Never “Jigasi”, “MatrixRTC”, or “whisper” on the button.
**Class:** new repo + a small Synaplan FeatureModule (`opendesk_stt`,
default off). `backend-only` from `synaplan/`.
**Binding UX:** [`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md).

This is the plan for turning Synaplan into the speech-to-text brain of
an [openDesk](https://www.opendesk.eu/en/product) deployment.

---

## 0. Decision checklist (tick before any code)

| # | Decision | Proposed default | Agree? |
| - | -------- | ---------------- | ------ |
| 1 | openDesk **chat + 1:1 / small-group A/V** = **Element** (Synapse + Element Call / MatrixRTC). openDesk **meetings** = **Jitsi** (Nordeck). We build both, in that product order: meetings first. | Locked | |
| 2 | Nextcloud Talk is **not** the openDesk chat path. A Talk adapter is a catalog row for Nextcloud-only installs. | Locked | |
| 3 | **v1 (OD-A) = Jitsi meetings** via the **bridge-based** transcriber (JVB → WebSocket → OpenAI-compatible STT). Synaplan is that STT. Do not start on Jigasi; it is deprecated for transcription. | Locked | |
| 4 | **v1.1 (OD-B) = Element voice messages** (`m.audio` / MSC3245). Bot in the room, one-shot `POST /v1/audio/transcriptions`. | Locked | |
| 5 | **v2 (OD-C) = Element Call live captions.** Bot joins LiveKit as a **visible** participant. Hidden eavesdropping is forbidden. | Locked | |
| 6 | Default STT = the user’s / operator’s SOUND2TEXT model (local whisper.cpp preferred on sovereign installs). Cloud STT is opt-in. | Locked | |
| 7 | Consent is a first-class control: a named person starts transcription; everyone sees a banner; **Stop** is one click. | Locked | |
| 8 | Audio is discarded after the window is transcribed. The **transcript** is the artefact. Retention is a setting (days), default 365, 0 = until someone deletes. | Locked | |
| 9 | Findability: transcript is posted in the Element room **and** saved as a Markdown/PDF file in a chosen Nextcloud folder. Ten seconds from either place. | Locked | |
| 10 | E2EE: we **do not** break Megolm or Element Call end-to-end encryption. If we cannot hear as a consented participant, we refuse with one sentence. | Locked | |
| 11 | Synaplan never mounts Jitsi’s or LiveKit’s sockets from PHP. A small sidecar / bot process holds media. PHP sees text. | Locked | |
| 12 | Scoped key: `audio:transcribe` + `files:write` + `messages:write` (room post). No unscoped bot key. | Locked | |
| 13 | Languages: meeting picker (de / en / fr / auto). Auto uses the STT model’s detector. | Locked | |
| 14 | No live translation in v1. Translation is a later Saved Task on the transcript file. | Locked | |
| 15 | Feature flag `OPENDESK_STT.ENABLED` default 0. Absent module ⇒ no routes, no cards. | Locked | |

---

## 1. What openDesk actually runs

Verified 2026-09-17 against
[docs.opendesk.eu — architecture](https://docs.opendesk.eu/operations/architecture/)
and [the product page](https://www.opendesk.eu/en/product).

openDesk 1.18 (Aug 2026) ships, among others:

| Function | Component | Role for this plan |
| -------- | --------- | ------------------ |
| Chat | Element Web 1.12.x + Synapse | Rooms, voice messages, 1:1 calls |
| 1:1 / small A/V | Element Call via **MatrixRTC + LiveKit** | OD-C |
| Meetings | **Jitsi** 2.0.11146 (Nordeck) | OD-A |
| SIP dial-in | Jigasi (optional) | Out of scope for STT |
| Files | Nextcloud 33 | Transcript file lands here |
| Weboffice | Collabora 26.04 | Out of scope (other track) |
| Mail | OX App Suite | Later link from calendar |
| IAM | Nubus (Keycloak + OpenLDAP) | SSO for the Connect form |
| Widgets | Nordeck | “Start notes” button in Element |

**Nordeck** maintains Jitsi for ZenDiS and ships Element widgets
(meeting-bot, date fixer). We integrate; we do not fork Jitsi or
Element.

### 1.1 Two audio worlds (do not mix them)

```
Element room ── text, files, voice messages ── OD-B (one-shot STT)
     │
     └── Element Call (MatrixRTC / LiveKit) ── OD-C (live captions)
                                               bot must JOIN the call

Jitsi meeting ── JVB has everyone’s Opus ── OD-A (live captions)
                 (typical openDesk deploy is NOT E2EE)
```

Nextcloud Talk’s `live_transcription` app (Vosk + Talk HPB) is a
**different product**. Do not reuse its admin copy for openDesk.

---

## 2. What Synaplan already has (reuse)

| Piece | Where | Use |
| ----- | ----- | --- |
| One-shot STT | `POST /v1/audio/transcriptions` | OD-B voice messages; OD-A fallback file |
| Streaming sessions | `POST /v1/audio/transcriptions/sessions` + `/audio` + SSE | OD-A / OD-C live windows |
| Model list | `GET /v1/audio/models` | Connect form picker |
| Local whisper.cpp | `WhisperService` / base image | Sovereign default |
| Cloud STT | `AiFacade::transcribe` (Groq / OpenAI / Voxtral) | Opt-in |
| File pipeline | `FileProcessor` audio / video | “Drop a recording in Nextcloud” already works |
| TTS | `synaplan-tts` + `/v1/audio/speech` | **Out of v1** (captions, not a talking bot) |

Jitsi’s new bridge-based proxy
([handbook](https://jitsi.github.io/handbook/docs/devops-guide/transcription/),
[2026 architecture note](https://jitsi.org/blog/a-new-architecture-for-transcription-and-more/))
already speaks **OpenAI-compatible** STT. That is the OD-A seam:
point Jicofo’s `url-template` at a thin Synaplan adapter, not at
Deepgram.

Element Call bots that work today (e.g. community MatrixRTC +
LiveKit + Whisper) join as a participant, resample 48 kHz → 16 kHz,
VAD, then STT. OD-C copies that pattern and sends windows to
Synaplan sessions. We do not vendor a random bot; we write a
small, tested sidecar.

---

## 3. Threat model (short)

| Threat | Treatment |
| ------ | --------- |
| Hidden listener in a call | Forbidden. OD-C bot is a visible tile named “Meeting notes”. OD-A uses Jitsi’s own caption channel (everyone sees “Captions on”). |
| Bot in an E2EE room without keys | Refuse. Copy: “This room is locked. Invite Meeting notes as a member, or turn captions off.” |
| Key leak from a Helm value | Scoped key in Nubus / Synaplan secrets; rotate from Integrations → Disconnect. |
| Audio retained | Discard after commit. Only text + metadata (room, time, language, model) stored. |
| Prompt injection via spoken text | Transcript is stored as **user data**, not executed. Summarize runs as a separate, approve-able step. |
| Cross-tenant mix-up | `client_id` = `{opendeskHost}:{roomId}:{startedBy}`. Sessions API already isolates by API key. |
| Cloud STT on a “local only” assistant | Honour Q8 / sovereignty. If the picked STT is cloud and the job is local-only, pause with an explanation. |
| PHP sees media | It must not. Sidecar only. |

---

## 4. v1 architecture — Jitsi meetings (OD-A)

```
Jitsi Meet ── Jicofo ── JVB ── WebSocket (Opus, per speaker)
                                │
                                ▼
                     synaplan-opendesk-transcriber
                     (thin proxy: decode Opus, window, VAD)
                                │
                                ▼
                     POST /v1/audio/transcriptions/sessions/{id}/audio
                                │
                                ▼
                     Synaplan STT (whisper.cpp or catalog)
                                │
                     ┌──────────┼──────────┐
                     ▼                     ▼
              Jitsi captions         Element room + Nextcloud file
              (same WebSocket)       (after Stop or 30 s idle)
```

**Operator setup (Connect form emits this, never raw YAML as the
first screen):**

1. Synaplan URL + scoped key + STT model + language + Nextcloud
   folder + optional Element room alias.
2. Snippet for Jicofo `jicofo.transcription.url-template` and
   `config.js` `transcription.enabled`.
3. Test: “Send a 3-second tone → we show ‘(silence)’ or fail with
   a sentence.”

**User setup:** none, if the operator enabled it. In the meeting:
**Start notes** (moderator) → banner “Notes are on. Stop anytime.”
→ captions. After hangup or Stop: “Notes saved to Files /
Meetings/2026-09-17-standup.md”.

**Why not Jigasi?** Jitsi’s own docs: Jigasi transcription is
deprecated. Nordeck still cares about Jigasi for **SIP**. We do
not pile STT onto a dying path.

---

## 5. v1.1 — Element voice messages (OD-B)

A dedicated Matrix user `@synaplan-notes:<server>` (Nubus-provisioned
or a local Synapse account). Invited to a room like any bot.

On `m.audio` / voice-message events the sidecar:

1. Downloads the media **as that user** (so E2EE works if the bot
   is a member and has keys).
2. `POST /v1/audio/transcriptions` with `client_id=matrix:{room}:{event}`.
3. Replies in a thread: the text + “Saved to Files” if a folder is
   set.
4. Empty audio: “No speech in this note.”

No live join. No captions. This is the honest v1.1: the thing
people already drop in Element.

---

## 6. v2 — Element Call live captions (OD-C)

Harder. Do not start before OD-A is walked.

1. Watch `org.matrix.msc3401.call.member` (or current MatrixRTC
   successor) in rooms the bot is in.
2. If a member has **Start notes** (Nordeck widget or a room
   command), the sidecar joins the LiveKit room as a participant
   named “Meeting notes” (dummy video tile, published
   `call.member` state).
3. Subscribe to audio, 48 kHz → 16 kHz, VAD, session STT.
4. Publish captions as Matrix `m.room.message` (or the current
   Element caption event) **and** keep a running file.
5. On hangup / Stop: same findability as OD-A.

If the call is E2EE and the SFU cannot see media, the bot still
only hears what a normal invited participant would hear. If that
is nothing, refuse (row 10).

Community prior art (MatrixRTC + LiveKit + Whisper) proves the
join path exists. We still write our own sidecar so the only
upstream is Synaplan.

**TTS / talking bot is out.** `synaplan-tts` is a later “read the
notes back” feature, not v2.

---

## 7. Synaplan-side module (small)

`FeatureModule` `opendesk_stt`:

| Route / job | Why |
| ----------- | --- |
| OpenAI-compatible STT that Jitsi’s proxy already expects (if the sessions API is not a drop-in, a 40-line translator) | OD-A |
| Webhook the sidecar calls on `session.done` with `{room, folder, text}` | write file + optional Element notice via the sidecar, not via PHP Matrix |
| Integrations card + Connect snippet | BI1 |
| Audit: `opendesk_stt.started` / `.stopped` / `.saved` | U7 |

PHP does not speak Matrix or XMPP.

---

## 8. Journeys (walk these or it is not done)

| Id | Journey | Sprint |
| -- | ------- | ------ |
| **J-OD-1** | Moderator in a Jitsi meeting clicks **Start notes**. Everyone sees the banner. They talk for two minutes. Captions appear. **Stop**. A Markdown file is in the chosen Nextcloud folder **and** a line appears in the linked Element room: “Notes from 10:00 — open in Files.” Account → Integrations shows the session and **Disconnect**. | OD-A |
| **J-OD-2** | Flag off / module absent: no **Start notes**, no Jitsi config snippet, no teaser. | OD-A |
| **J-OD-3** | STT down: banner becomes “Notes could not be saved. The meeting was not recorded. Try again or Disconnect.” Meeting continues. | OD-A |
| **J-OD-4** | Element: someone drops a 20 s voice note in a room the bot is in. A thread reply is the text. Same file folder if configured. | OD-B |
| **J-OD-5** | E2EE room, bot not a member: one sentence, no retry loop. Invite the bot → J-OD-4 works. | OD-B |
| **J-OD-6** | Element Call: **Start notes** makes a visible “Meeting notes” tile. Stop removes it. Transcript findable as in J-OD-1. | OD-C |

Empty Integrations card: “Connect Meeting notes to write captions
from Jitsi and voice notes from Element into your Files.”

Undo: **Stop** (this meeting) and **Disconnect** (this Synaplan).
Consequence copy: “Captions stop. Already saved notes stay in
Files. Audio was not kept.”

---

## 9. Sprints

| Sprint | Exit | Repo |
| ------ | ---- | ---- |
| **OD-0** | Threat model signed; Helm/Nubus sketch; §0 ticked; spike: Jitsi docker-jitsi-meet → Synaplan sessions API with a 10 s clip | notes in STATUS |
| **OD-A1** | Sidecar implements the JVB WebSocket dialect Jitsi ships (or the OpenAI realtime dialect the proxy already has). Characterization fixture: Opus fixture in, transcript JSON out. | `synaplan-opendesk-transcriber` |
| **OD-A2** | Synaplan module + Integrations card + snippet. J-OD-1 walked on a local Jitsi + local Synaplan. Five locales. | `synaplan` + sidecar |
| **OD-A3** | Nextcloud write + Element notice. Honest failure copy. Audit. J-OD-2, J-OD-3. | both |
| **OD-B** | Matrix bot, voice messages, J-OD-4, J-OD-5. | sidecar |
| **OD-C** | Element Call join, visible tile, J-OD-6. | sidecar |

Do not start OD-C in the same PR as OD-A.

---

## 10. Packaging for openDesk operators

Target: a Helm snippet they drop next to the openDesk Helmfile,
plus a Nubus OIDC client if the Connect page is used by humans.

| Value | Purpose |
| ----- | ------- |
| `synaplan.url` | Instance |
| `synaplan.transcribeToken` | Scoped key |
| `synaplan.sttModel` | Catalog id or empty = default |
| `notes.nextcloudFolder` | e.g. `/Meetings` |
| `notes.matrixRoom` | optional alias |
| `notes.language` | `auto` / `de` / `en` / `fr` |

We do **not** vend a full openDesk fork. A short
`docs/opendesk-meeting-notes.md` in `synaplan-docs` is the
operator story.

---

## 11. Out of scope (say so)

- Replacing Element or Jitsi.
- Live translation, speaker diarization beyond what Jitsi already
  tags, or a searchable “all meetings” product in Synaplan v1
  (the file in Nextcloud *is* the product).
- Phone / SIP transcription (Jigasi).
- Recording the meeting video (Jibri). We are captions + a text
  file.
- A talking bot (TTS into the call).
- Using Nextcloud Talk as a shortcut to claim “openDesk support.”

---

## 12. Success

A public-administration team on openDesk 1.18 can turn on Meeting
notes, run a Jitsi standup in German, and find a transcript in
Nextcloud without a docs tab. Element voice notes in the project
room get a text reply. Nobody wonders whether Nextcloud Talk is
involved. Stop and Disconnect work on the same row.
