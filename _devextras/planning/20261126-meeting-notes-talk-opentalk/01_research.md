# How Nextcloud Talk and OpenTalk work

Research for [`00_master_plan.md`](./00_master_plan.md). Read on
2026-11-26. Every statement about the two products names its source in
§4; our own conclusions are marked. Nothing here was run. What could not
be checked is in the master plan §7 and is not stated as fact here.

## 1. Nextcloud Talk

### 1.1 Parts

| Part | What it does | Source |
| ---- | ------------ | ------ |
| Talk app (`spreed`) | Conversations, chat, the call screen. A Nextcloud server app. | S6 |
| High-performance backend | A signaling server plus a WebRTC media gateway (Janus). Optional for Talk itself. Without it a call has no media gateway, so there is no server a transcriber could take the audio from. | S5, S6 |
| The backend as one container | `ghcr.io/nextcloud-releases/aio-talk`: signaling on 8081, TURN on 3478 TCP and UDP. Configured with `NC_DOMAIN`, `TALK_HOST`, `TALK_PORT`, `TURN_SECRET`, `SIGNALING_SECRET`, `INTERNAL_SECRET`. Talk's admin settings then take the backend URL and the signaling secret. | S6, S3 |
| Internal client | A service that connects to the signaling server without being a Nextcloud user. It sends `hello` with `auth.type: "internal"`, a `random` of at least 32 bytes and `token`, the SHA-256 HMAC of `random` with the internal secret. Such clients "can join any room, even if they have not been invited". | S5 |
| Audio for an internal client | It joins the room, learns the sessions from the room events, sends `requestoffer` to a publisher's session, gets an SDP offer back, answers, and exchanges candidates. That is one WebRTC connection per publisher, so audio arrives per speaker. | S5, S3 |
| Virtual session | An internal client can add a session that the other participants see (`addsession`). The SIP bridge uses it to show phone callers. | S5 |
| Bots | Installed by an administrator (`occ talk:bot:install`), enabled per conversation. A bot posts with `POST /ocs/v2.php/apps/spreed/api/v1/bot/{token}/message` and two HMAC headers. A Nextcloud app can be the bot itself (`nextcloudapp://<app id>`, `BotInvokeEvent`). | S7 |

### 1.2 Nextcloud's own live transcription

The app `live_transcription` is what Nextcloud ships for captions (S1, S3):

- It is an external app (AppAPI), Python, AGPL-3.0-or-later. Nextcloud 33
  to 35. It needs the high-performance backend, "latest or released after
  September 2025".
- It is an internal client (§1.1) with aiortc as the WebRTC receiver. It
  takes `LT_HPB_URL` and `LT_INTERNAL_SECRET` at install time.
- The engine is Vosk, with one model per language. S1 lists the limits:
  no punctuation, and "languages other than English may have lower
  accuracy mainly due to the shipped models being smaller". An external
  engine can replace the built-in one (`LT_VOSK_SERVER_URL`,
  `LT_DISABLE_INTERNAL_VOSK`); the app speaks its own WebSocket dialect to
  it: 48 kHz 16-bit mono chunks, a `config` message with `sample_rate`
  and `language`.
- Talk calls the app through AppAPI under the fixed app id
  `live_transcription` (S4). The app's v1 routes are `call/transcribe`,
  `call/set-language`, `call/leave`, `languages` and `translation/*`.
- A moderator sets the language of the conversation. Each participant
  turns the captions on for themselves in the call bar (S2).
- The text goes to each participant who asked, as a signaling message:
  `{"type": "transcript", "message", "speakerSessionId", "final", "langId"}`.
- The manuals describe captions on the screen. They describe no saved
  transcript.

### 1.3 What that means for meeting notes

Our conclusions from §1.1 and §1.2.

| Question | Answer |
| -------- | ------ |
| Can a transcriber of ours hear a Talk call, per speaker? | Yes, as an internal client, the same way Nextcloud's own app does. It needs the backend's internal secret. |
| Without the high-performance backend? | No. There is no server in the media path. |
| Does Talk offer a place for a button in the call? | Not found in the documentation read. Nextcloud's own app gets its controls from Talk itself. Our button comes from the Nextcloud app, the way `loader.js` comes from Jitsi's `head.html` today. |
| Can every client be told that notes are on? | A chat message is shown by the web, desktop and mobile clients. A banner from our script exists in the web client only. |
| Who are the speakers? | A room event carries each session's id, the user id "for known users" and the user data Nextcloud supplied (S5). A Nextcloud user id is not a Synaplan account; the Nextcloud app holds that link per person. |

## 2. OpenTalk

### 2.1 Parts (26.2 and later)

| Part | What it does | Source |
| ---- | ------------ | ------ |
| Controller | Web API, storage API, persistent data. | S8 |
| RoomServer | Meeting signaling and the meeting modules (chat, polls, recording, transcription, …). New in 26.2; inside the controller process or its own service. | S8 |
| LiveKit | Audio and video. Participants reach its signaling through a proxy path of the controller (`/livekit`). | S9 |
| Recorder, Obelisk (phone), SMTP mailer, Orchestrator | Optional services. Recorder and Obelisk authenticate with API keys since 26.2. | S8 |
| Keycloak, PostgreSQL, object storage | Sign-in, data, files. | S11, S13 |
| Web frontend | React. 2.10 (OpenTalk 26.3.0, 2026-10-29) shows live subtitles. | S11, S12 |

OpenTalk is EUPL-1.2. Development happens on OpenTalk's own GitLab, and
merge requests from outside are "currently not possible" (S15).

### 2.2 The transcription contract

OpenTalk defines how a transcription service plugs in. The RoomServer
module, the service's web API and the signaling types are published
crates (S10). The module is switched on in the controller configuration
(S9):

```toml
[roomserver.modules.transcription]
url = "http://localhost:11413"
api_key = { id = "transcription", secret = "secret" }
```

One meeting, in order:

1. A moderator sends `start` with a language (two letters, or none for
   detection). Only moderators may start, stop or change the language.
2. The RoomServer calls the service: `POST {url}/v1/init` with
   `{"room_id", "breakout_room", "language"}` and `Authorization: Bearer`.
   The bearer is a JSON Web Token signed with HS256 and the key's secret.
   Its header carries the key id (`kid`) and a nonce. It expires after
   60 seconds. Everyone in the room sees the state `requested`.
3. The service asks the controller for a way in:
   `POST {controller}/internal/transcription/start` with
   `{"room_id", "breakout_room"}` and its own API key. The answer is
   `{"roomserver_address", "token"}`. The service opens the signaling
   WebSocket there and joins.
4. In the room the service is a participant of kind `transcription`,
   bound to the room it was requested for. Its visibility is `hidden`:
   the source calls it an "invisible service participant".
5. It reports `started`. Everyone sees the state `running`.
6. For each piece of speech it sends a `segment`:
   `{"participant_id", "track_id", "starts_at", "ends_at", "text"}`. The
   RoomServer forwards it to every connected participant. A segment has
   no "interim" marker.
7. The RoomServer can send the service `stop` and `change_language`.
8. When the service reports `stopped` or disconnects, the state goes back
   to `inactive`. An unexpected disconnect also sends an error to the room.

The request names the room and the language. It does not name the person
who started it.

### 2.3 The interface people see

From the frontend work item (S12) and the 2.10 change list (S11): the
host turns subtitles on for the meeting and picks the input language.
Everyone gets a notice ("Subtitles are on."). Each person then chooses
"Show subtitles" for themselves, and a subtitle bar shows the segments.
The language of a running service can be changed. Desktop layout only.
Transcription is greyed out in end-to-end encrypted rooms.

### 2.4 OpenTalk's own service

The web API crate names its repository as
`git.opentalk.dev/opentalk/backend/services/transcription` (S10). The
component table of the release bundles has a `transcription` column, and
it is empty for every release from 26.2.0 to 26.3.0 (S11). The
administration manual lists no transcription service (S8). So the
interface for subtitles ships in 26.3, and a service that fills it does
not ship with it.

### 2.5 Running it

| Way | What | Source |
| --- | ---- | ------ |
| OpenTalk Compose | The supported install: a package (`opentalk-compose`), `ot_domain` in one settings file, `otctl deploy`. One public host, certificates from Let's Encrypt by default. Ports: 80 and 443, LiveKit 7881 TCP, 3478 UDP and 50000–65535 UDP. At least 2 cores, 4 GB. | S13 |
| Kubernetes | A Helm chart has a version history page, but the 26.2 and 26.3 bundles list no chart version. A guide from May 2026 runs 25.4.7 with six manifests behind nginx-ingress and cert-manager; LiveKit is pinned to one node with UDP 20000–25000 and TCP 7881 open. | S11, S14 |

### 2.6 OpenTalk and openDesk

openDesk 1.20.4 (2026-11-20) ships Jitsi for meetings. ZenDiS announced
openConference, a video conferencing product based on OpenTalk, in
August 2025 (S16). Our reading: OpenTalk is the likely second meeting
product in the public sector, beside Jitsi.

## 3. Side by side

| | Jitsi (today) | OpenTalk 26.3 | Nextcloud Talk 33–35 |
| - | ------------- | ------------- | -------------------- |
| Media server | Jitsi Videobridge | LiveKit | Janus, behind the high-performance backend |
| How a transcriber gets audio | The bridge opens a WebSocket to it and pushes each speaker's Opus | It joins as the hidden transcription service and subscribes to each participant's track | It joins as an internal client and requests an offer from each publisher |
| One stream per speaker | yes | yes | yes |
| Who starts | Our button (`loader.js`) | OpenTalk's own control, moderators only | Our button, from the Nextcloud app |
| Text back into the meeting | Through the bridge, Jitsi shows it | `segment`, OpenTalk shows it | Not in the first version |
| The product's own offer | none | A defined contract, no service in the bundle | `live_transcription` (Vosk): captions |
| End-to-end encrypted meetings | Not possible | OpenTalk turns transcription off | Not possible |

## 4. Sources

| # | Source |
| - | ------ |
| S1 | Nextcloud 35 Administration Manual, "App: Live Transcription and Translation in Nextcloud Talk": <https://docs.nextcloud.com/server/stable/admin_manual/ai/app_live_transcription.html> |
| S2 | Nextcloud 35 User Manual, Talk, "Transcription and translation": <https://docs.nextcloud.com/server/stable/user_manual/en/talk/call_transcription.html> |
| S3 | nextcloud/live_transcription, branch `master`, pushed 2026-11-24: `readme.md`, `appinfo/info.xml`, `example.env`, `ex_app/lib/main.py`, `spreed_client.py`, `transcriber.py`, `vosk_server.py`, `LICENSES/` |
| S4 | nextcloud/spreed, issue 15026, "Live transcription followup": <https://github.com/nextcloud/spreed/issues/15026> |
| S5 | strukturag/nextcloud-spreed-signaling, `docs/standalone-signaling-api-v1.md`, branch `master`: "Client type `internal`", "Request offer from publisher", "Internal clients", "Add virtual session" |
| S6 | Nextcloud Talk documentation, "Quick install": <https://nextcloud-talk.readthedocs.io/en/latest/quick-install/> |
| S7 | Nextcloud Talk documentation, "Bots and webhooks": <https://nextcloud-talk.readthedocs.io/en/latest/bots/> |
| S8 | OpenTalk documentation 26.3, "OpenTalk Administration": <https://docs.opentalk.eu/26.3/admin/>; 26.2 "Migration Guide": <https://docs.opentalk.eu/26.2/admin/migration/> |
| S9 | OpenTalk documentation 26.3, controller "Configuration", "Modules" and "LiveKit": <https://docs.opentalk.eu/26.3/admin/controller/core/configuration/>, <https://docs.opentalk.eu/26.3/admin/controller/advanced/modules/>, <https://docs.opentalk.eu/26.3/admin/controller/core/livekit/> |
| S10 | Published crates, read on docs.rs: `opentalk-transcription-web-api` 0.7.0 (`v1.rs`), `opentalk-roomserver-module-transcription` 0.10.4 (`lib.rs`), `opentalk-roomserver-types-transcription` 0.10.4 (`segment.rs`, `service/command.rs`, `service/event.rs`), `opentalk-roomserver-types` 0.10.4 (`client_parameters.rs`), `opentalk-client-signaling` (`client.rs`, `http.rs`), `opentalk-service-auth` (`api_key.rs`). All EUPL-1.2 |
| S11 | OpenTalk release notes: v26.3.0 (<https://docs.opentalk.eu/releases/26.3.0/>), the web frontend history (<https://docs.opentalk.eu/releases/components/web-frontend/>), the release bundles and their component table (<https://docs.opentalk.eu/releases/>) |
| S12 | OpenTalk web app, work item 3353, "Conference UI: Implement Live Subtitles": <https://git.opentalk.dev/opentalk/frontend/web/web-app/-/work_items/3353> |
| S13 | OpenTalk documentation 26.3, OpenTalk Compose, "Quickstart Installation": <https://docs.opentalk.eu/26.3/admin/deployment/opentalk-compose/1-quickstart-installation/> |
| S14 | "Running OpenTalk in Kubernetes", Ralph Soika, 2026-05-15: <https://ralph.blog.imixs.com/2026/05/15/running-opentalk-in-kubernetes/>; "OpenTalk Helm-Charts version history": <https://docs.opentalk.eu/releases/components/opentalk-helm/> |
| S15 | OpenTalk documentation 26.3, "OpenTalk Developer Documentation": <https://docs.opentalk.eu/26.3/developer/> |
| S16 | openDesk release matrix, v1.20.4: <https://releases.opendesk.eu/>; ZenDiS press release of 2025-08-21: <https://www.zendis.de/newsroom/presse/zendis-bringt-digitale-souveraenitaet-auf-die-smart-country-convention> |
