# Meeting notes in Nextcloud Talk and OpenTalk

**Status:** 2026-11-26. Plan only. §0 is open. See [`STATUS.md`](./STATUS.md).
**Owner:** product owner.
**Roadmap:** row 49. Steps `MN0`–`MN12`.
**Binding:** root [`AGENTS.md`](../../../AGENTS.md),
[`docs/UX_CONTRACT.md`](../../../docs/UX_CONTRACT.md) (U1–U12).
**Facts taken on:** 2026-11-26. Synaplan `main` @ `aa1a57502` (release
5.10.3, plugin `synascriber` 0.4.1), synaplan-charts `main` @ `298b66e`,
synaplan-nextcloud `main` @ `d3b9090` (app 2.1.0), OpenTalk 26.3.0, the
Nextcloud 35 manuals. How the two products work, with sources:
[`01_research.md`](./01_research.md). What was not verified is in §7 and is
not stated as fact anywhere else.

## Goal

Meeting notes work in Jitsi today. They work the same way in two more
meeting products:

- **OpenTalk.** A moderator turns subtitles on in OpenTalk. Everyone who
  shows them reads what is said, labelled with the speaker. Synaplan is the
  service behind OpenTalk's own subtitle controls.
- **Nextcloud Talk.** A signed-in person starts Meeting notes in a call.
  Everyone is told. After Stop, each participant with a connected Synaplan
  account finds the transcript in Sources › Generated.

One plugin, one transcriber image, one speech server. Audio is never stored.

**User flow:** journeys J-MN-1 to J-MN-7 in §5. Surfaces: OpenTalk's own
subtitle controls (not ours), the plugin page (one card per source), the
Talk web client (button, dialog and banner from the Nextcloud app), one
chat message in the Talk conversation.

## §0 Decisions

Each row states the proposed default. All are open.

| # | Decision | State |
| - | -------- | ----- |
| 1 | **One plugin, three sources.** `plugins/synascriber` stays the only meeting-notes plugin. A session gets a `source`: `jitsi`, `opentalk` or `talk`. Sessions, speech recognition, the transcript and its delivery are not copied. | open |
| 2 | **One transcriber image.** The two adapters are modes of `sidecars/synaplan-transcriber`, released with Synaplan as today. Alternative: one sidecar per product. | open |
| 3 | **OpenTalk through OpenTalk's own contract** (research §2.2). OpenTalk's subtitle controls and subtitle bar are the interface. Synaplan injects nothing. Needs OpenTalk 26.3 or later. | open |
| 4 | **Talk through the Nextcloud app and the high-performance backend.** `synaplan_integration` shows the button, dialog and banner in Talk's web client. The transcriber joins as an internal client. Not chosen: registering under the app id of Nextcloud's `live_transcription` (its routes are a private contract between Talk and that app), and the recording backend (its job is a recording file). A call without the high-performance backend cannot have notes. | open |
| 5 | **OpenTalk first, then Talk.** OpenTalk needs no interface of ours and no second product repository. Talk needs a WebRTC receiver, the Nextcloud app and its release train. Alternative: Talk first, because the Nextcloud app is already installed at customers. | open |
| 6 | **Smallest working part per product.** OpenTalk: live subtitles first (MN5), the saved transcript second (MN6). Talk: the saved transcript first (MN10), live captions later (MN12). | open |
| 7 | **Telling people.** The rule from Jitsi stays: a named person starts, everyone is told, one click stops. Talk: a banner in the web client and one chat message, which every client shows. OpenTalk tells people "Subtitles are on." It does not say that a transcript is kept. Proposed: the OpenTalk transcript is off by default (`opentalk_keep_transcript`). The settings card says that an organisation which turns it on has to announce it to participants itself. We ask OpenTalk for wording of its own (§7 U8). | open |
| 8 | **Who gets the transcript.** Talk: the person who started, and every participant whose Nextcloud account is connected to Synaplan through the app. Others get no copy, like guests in Jitsi. OpenTalk: the start request does not name who started. Proposed: every signed-in participant who was present while notes ran and whose Keycloak identity matches a Synaplan account, the Jitsi rule. That needs Synaplan and OpenTalk on one Keycloak realm, and §7 U6. | open |
| 9 | **Two new dependencies in the transcriber** (ask first, AGENTS.md): `werift` (MIT) receives Talk's audio as RTP without decoding it; `@livekit/rtc-node` (Apache-2.0) receives OpenTalk's audio. Decided after the spike has measured both (MN0). Fallback: adapters in Go with pion. | open |
| 10 | **No upstream source is copied or linked.** `live_transcription` is AGPL-3.0-or-later, OpenTalk is EUPL-1.2, Synaplan is Apache-2.0. The adapters are written from the published protocol descriptions and from fixtures recorded in our own installs. | open |
| 11 | **Lab on the `vultr-cluster` dev cluster.** Two unlisted services, each in its own namespace: a stock Nextcloud with Talk and the high-performance backend, and a stock OpenTalk. No portal tile, no link, sign-up off, `noindex`, accounts for named testers. Host names, ports and accounts are written down only in that private repository. Unlisted is not secret: issued certificates are public record. Alternative for OpenTalk: one virtual machine with `opentalk-compose`, the install OpenTalk supports (§7 U11). | open |
| 12 | **Off until configured.** Each source has its own switch (`opentalk_enabled`, `talk_enabled`, default off). Off means no button, no card that claims to be installed, and "not configured" on its routes. | open |

## 1. How the three products differ

Details and sources in [`01_research.md`](./01_research.md) §3.

| | Jitsi (today) | OpenTalk 26.3 | Nextcloud Talk 33–35 |
| - | ------------- | ------------- | -------------------- |
| How the transcriber gets audio | The bridge pushes each speaker's Opus over a WebSocket | It joins as the hidden transcription service and subscribes to each participant's track | It joins as an internal client of the high-performance backend and requests an offer from each publisher |
| Who starts | Our button | OpenTalk's own control, moderators only | Our button, from the Nextcloud app |
| Text back into the meeting | Through the bridge | `segment`, shown in OpenTalk's subtitle bar | Not in the first version |
| The product's own offer | none | A defined contract; no service in the release bundle | `live_transcription` (Vosk): captions; its manuals describe no saved transcript |

All three deliver one audio stream per speaker. That is what the plugin
needs: a line in the transcript has its speaker without guessing.

## 2. Meeting notes today

| Piece | Where | State |
| ----- | ----- | ----- |
| Plugin | `plugins/synascriber` 0.4.1 | Sessions, speech recognition with filters, live captions, one Markdown transcript per recipient. 50 PHPUnit tests. Tied to Jitsi: the `ProsodyClient` calls in `SessionService`, `JitsiController` and the button (`loader.js`), the room-name rule, and `ParticipantResolver` (Keycloak subject and e-mail). |
| Transcriber | `sidecars/synaplan-transcriber`, Node, one dependency (`ws`) | Modes `jitsi`, `element`, `opendesk`. The window logic (`pluginSession.js`: cuts at pauses, previews, retries, gaps) works on Opus packets. 43 tests. Image `ghcr.io/metadist/synaplan-transcriber`, published for amd64 and arm64 with every release. |
| Window contract | Four routes under `…/public/transcriber/sessions/{ref}`, header `X-Transcriber-Secret` | Already independent of Jitsi: one speaker, at most 30 s and 2 MB, a speaker id, a sequence number, times. `WhisperClient` sends every window as `window.ogg`, `audio/ogg`. A session is created only by the Jitsi route, as a signed-in person. |
| Speech server | The `stt` chart (whisper.cpp, German Whisper large-v3-turbo), or any server with the OpenAI transcription API | Not changed by this plan. |
| Deployment | synaplan-charts: `synascriber.*` values, a registration Job, Jitsi values and a Keycloak client for openDesk, a k3d test (`e2e/tests/opendesk/notes.spec.ts`) | Jitsi only. The notes test failed on `main` on 2026-11-24 and passed on the pull request runs before and after (§7 U14). |
| Guides | `plugins/synascriber/README.md`, `ARCHITECTURE.md`, `docs/OPENDESK_MEETING_NOTES.md` | The last one describes the older path with an API key, not the plugin. |
| Older path | `OpendeskSttModule`, `MeetingNotesController`, the sidecar's `jitsiSession.js`, `publish.js` and Element bot | Still shipped. Not touched here. |

## 3. Target

```
Jitsi bridge ───── pushes Opus ──────┐
OpenTalk (LiveKit) ─ adapter joins ──┤  transcriber          plugin synascriber       speech server
Talk (Janus) ─────── adapter joins ──┘  frames → windows ──► session → text ────────► /v1/audio/transcriptions
                                              ▲                 │
                        text for the meeting ─┘                 └─► one transcript per recipient
```

### 3.1 Plugin

| | Change | Why |
| - | ------ | --- |
| a | A session carries `source` and the source's room id. `SessionService` talks to a `MeetingSource` (start, stop, state with the people present, who may stop). `JitsiSource` wraps `ProsodyClient` as it is. | `SessionService` is 712 lines; new sources are new classes (size ratchet). |
| b | `OpenTalkSource`: OpenTalk drives start and stop, so the transcriber creates the session (`POST …/public/transcriber/sessions`, same secret) and reports the end. | Only the Jitsi route creates a session today. |
| c | `TalkSource`: start, stop and state routes that the Nextcloud app calls for a signed-in person. The plugin tells the transcriber to join and to leave (`transcriber_url`, bearer). | Nothing in Talk pushes audio to us; the adapter has to be asked in. |
| d | The audio route takes a `format` (`ogg`, `wav`); `WhisperClient` sends the matching name and type. | LiveKit hands out decoded audio (§7 U2, U3). |
| e | A session can be "subtitles only": windows are recognised and answered, nothing is stored. | §0 #7. |
| f | Recipients come from a resolver per source (§0 #8). | A Nextcloud user id and an OpenTalk participant are not Keycloak tokens from Jitsi. |
| g | Settings `opentalk_*` and `talk_*`, secrets write-only. The plugin page gets one card per source with its connection checks, in five locales. | §0 #12. |
| h | A session can have no starter. It is saved when at least one recipient has the file. Each recipient's copy is their result on the plugin page, and the stored text is deleted once a copy exists. When nobody present has a matching account, no file is written, the stored text is deleted, and the session ends with the reason `no_recipient`. When copies were due and none could be written, the session fails with `file_not_saved`, as today. The transcript header names the meeting instead of a starter. | `SessionService::finish` counts a session as saved only when the starter's copy exists, takes that copy as the result, and deletes the stored text only then. OpenTalk's start request names no starter (§0 #8). |

### 3.2 Transcriber

| | Change | Why |
| - | ------ | --- |
| a | `pluginSession.js` takes frames through a small codec seam: Opus packets (today), 16 kHz mono PCM (new). Cuts, previews, retries and gap reports stay. | One window logic for three sources. |
| b | Mode `opentalk`: answers `POST /v1/init` (token check), asks the controller for a way in, joins the RoomServer and LiveKit, sends `started`, `segment`, `stopped`, obeys `stop` and `change_language`. A breakout room is its own session. | Research §2.2. |
| c | Mode `talk`: joins when the plugin asks; internal `hello`, room join, one offer per publisher, leaves when told or when the call is empty. | Research §1.1. |
| d | Both join only for a live plugin session and connect only to the configured addresses. | "No session, no transcription", as in Jitsi. |

Two things to guard:

- The Talk backend's internal secret lets its holder join any call on that
  Nextcloud. It is a plugin secret like `prosody_secret`: write-only on the
  page, never logged. The guide says what it is.
- `POST /v1/init` is the one call that comes in from another product. It is
  refused unless the token is signed with the configured key and has not
  expired. A second `init` for a room that already has a session changes
  nothing.

### 3.3 Nextcloud app (`synaplan-nextcloud`)

A script on Talk pages only, and only when Synaplan reports that Meeting
notes for Talk are on. Routes that pass state, start and stop to Synaplan
as the signed-in person; each new call gets its line in the contract test.
A Talk moderator may stop notes they did not start. One chat message at
start and one at stop. Copy in English, German (informal and formal),
Spanish, French and Turkish, using the words of the plugin's locale files.

### 3.4 Charts and guides

`synascriber.opentalk.*` and `synascriber.talk.*` in the `synaplan` chart,
with a Secret reference for each key. OpenTalk's RoomServer must reach the
transcriber: a Service in the same cluster, or an Ingress path. Two operator
guides: `docs/OPENTALK_MEETING_NOTES.md`, `docs/NEXTCLOUD_TALK_MEETING_NOTES.md`.
`plugins/synascriber/ARCHITECTURE.md` describes three sources.

## 4. Environments

### 4.1 On the development machine

| Stack | How | Notes |
| ----- | --- | ----- |
| Synaplan | The dev stack, plus `docker compose --profile transcriber up -d transcriber` and a speech server (the image the `stt` chart runs, on CPU) | `stt_url` points at it. |
| OpenTalk 26.3 | `opentalk-compose` with a local domain and its own certificate; fallback `ot-setup` 26.3 (§7 U4) | `[roomserver.modules.transcription]` points at the transcriber on the host. |
| Nextcloud Talk | `synaplan-nextcloud`: `make dev-up` (Nextcloud on `:8081` with ada, bob and carol), `occ app:install spreed`, and the high-performance backend as one container (`ghcr.io/nextcloud-releases/aio-talk`: signaling, Janus, TURN) with development secrets (§7 U12) | `localhost` counts as a secure origin, so the microphone works without a certificate. |
| Two participants | Playwright with Chromium's fake microphone playing `e2e/tests/opendesk/fixtures/speech.wav` from synaplan-charts; a second context listens | The technique of `notes.spec.ts`. |

The three stacks do not have to run at once. OpenTalk Compose brings its
own proxy on ports 80 and 443.

### 4.2 Lab: two unlisted services

In the private infrastructure repository (`vultr-cluster`), beside the dev
install that already has Synaplan (§0 #11). How the labs are built (hosts,
ports, accounts) is written there, not here. What this plan needs:

- Each product in a namespace of its own, removable as a whole.
- Calls that work for people on other networks, mobile data included
  (§7 U11). Both media servers need UDP from outside.
- The labs use the dev install's Synaplan and speech server. For OpenTalk
  the Keycloak realm decides whether a transcript can find its recipients
  (§0 #8).
- Testers: the owner and two or three named people. A walk is a STATUS
  entry: date, how many people, which networks, what was said and what was
  written.

## 5. Journeys

| Id | Journey | Step |
| -- | ------- | ---- |
| **J-MN-1** | A moderator in an OpenTalk meeting turns subtitles on and picks German. Everyone gets OpenTalk's notice. Two people talk. Each person who shows subtitles reads the sentences with the speaker's name, a few seconds after a pause. The moderator turns subtitles off; the bar stops. Synaplan has stored nothing. | MN5 |
| **J-MN-2** | "Keep the transcript" is on. After subtitles are turned off, or the last person leaves, every signed-in participant with a Synaplan account finds the transcript in Sources › Generated within a minute. A guest gets no copy. The plugin page lists the meeting with its source. Second ending: nobody present has a Synaplan account. Nothing is written, nothing is kept, and Synaplan's log has one line with the reason. | MN6 |
| **J-MN-3** | In a Talk call in the browser, Ada clicks **Meeting notes**, picks a language and a folder, and starts. Everyone in the web client sees the banner. The conversation gets one message: who started notes, and that audio is not kept. Bob, on the mobile app, sees that message. People talk. Ada stops, or the call ends. Within a minute the transcript is in Sources › Generated for Ada and for every participant with a connected account, and the conversation gets a second message that says so. | MN10 |
| **J-MN-4** | A source that is off or not set up: no button in Talk, no card that claims it works. In OpenTalk the subtitle control answers with OpenTalk's own error, and Synaplan's log has one line that says why. | MN5, MN10 |
| **J-MN-5** | The speech server is down. Talk: the banner says that notes could not be written and what was not saved. The call goes on. OpenTalk: subtitles go back to off with OpenTalk's own error. No endless retry. | MN5, MN10 |
| **J-MN-6** | Notes are not possible: a Talk call without the high-performance backend, or an end-to-end encrypted meeting. One sentence says why, nothing is started, and nothing is left running. | MN5, MN10 |
| **J-MN-7** | The Jitsi journey of `notes.spec.ts` passes after every step that touches the plugin or the transcriber. | MN1, MN2, all later |

Every step that changes what a person sees (the plugin page in MN5 and
MN9, the Talk interface in MN10) writes the journey and these five exits
into STATUS before the code: journey walked in the browser; the other
person finds the result in ten seconds; consequence copy in all five
locales; empty, error and off states; dark, V2 and 320 px.

## 6. Steps

One branch and one pull request per step and repository, per root
`AGENTS.md`. Classes from `.github/mobile-impact-policy.json`:
`plugins/**` is `backend-only`; `sidecars/**`, `docs/**` and this folder
are `no-app-impact`.

| Step | What | Done when |
| ---- | ---- | --------- |
| **MN0** | This plan; the owner decides §0. A spike in `spike/` of this folder, not shipped: the cause of the failed notes test (U14); a script that passes OpenTalk's contract once on a local OpenTalk and gets text for 30 s of one participant's audio; a script that joins a local Talk call as an internal client and posts 30 s of one speaker as a window; processor and memory per stream for both. | STATUS holds the answers to U1–U5, U12 and U14, and §0 #9 is decided. |
| **MN1** | Plugin: sessions for more than one source (§3.1 a). `refactor:`, nothing changes for Jitsi. | The tests in `backend/tests/Plugin/SynaScriber/` pass unchanged; new tests run a second, fake source; J-MN-7 with this branch's images. |
| **MN2** | Transcriber: windows from Opus packets or PCM frames (§3.2 a). `refactor:`. | `node --test` passes unchanged for Opus; new tests feed PCM and get the same cuts; J-MN-7. |
| **MN3** | Lab (§4.2), in the private repository: a stock Nextcloud with Talk and the backend, and a stock OpenTalk 26.3. No Synaplan code. | Two people on different networks, one of them on mobile data, hold a call in each. STATUS names what carries the media. |
| **MN4** | Transcriber: mode `opentalk` (§3.2 b, d). Fixtures recorded on the local OpenTalk; the tests replay them without a network. | On the local OpenTalk, subtitles on makes the adapter join, `stop` makes it leave within five seconds; a wrong or expired token gets 401; no audio is accepted without a live session. |
| **MN5** | Plugin: OpenTalk as a source, subtitles only (§3.1 b, d, e, g). `feat:`. | J-MN-1, J-MN-4, J-MN-5 and J-MN-6 walked locally and in the lab with three people. STATUS has the delay from the end of a sentence to its subtitle. |
| **MN6** | Plugin: the OpenTalk transcript (§3.1 f, h): recipients without a starter, and what counts as saved. Only after §0 #7 and #8 are decided and U6 is answered. | J-MN-2 walked, both endings. PHPUnit covers a session without a starter: saved with one recipient, `no_recipient` with none, and no stored text left in either case. The Jitsi tests pass unchanged. |
| **MN7** | synaplan-charts: `synascriber.opentalk.*` with unit tests. Synaplan: `docs/OPENTALK_MEETING_NOTES.md`. | `make all` is green there. In the lab, meeting notes for OpenTalk are switched on through the chart's values, not by hand. |
| **MN8** | Transcriber: mode `talk` (§3.2 c, d). Fixtures from the local Talk. | A join request for a live session yields windows per speaker; people who leave and come back are followed; the adapter leaves when told or when the call is empty; a wrong internal secret ends the session as failed with one readable log line. |
| **MN9** | Plugin: Talk as a source (§3.1 c, f, g). `feat:`. | PHPUnit covers start, stop and who may do what. A session started through the routes by a test client ends as a transcript on the local stack. |
| **MN10** | synaplan-nextcloud: the button, dialog and banner in Talk, the two chat messages, a moderator may stop. | J-MN-3 to J-MN-6 walked locally and in the lab with three people, one of them on the mobile app. `make ci-local` is green there; the contract test lists every new call. |
| **MN11** | synaplan-charts: `synascriber.talk.*`. Synaplan: `docs/NEXTCLOUD_TALK_MEETING_NOTES.md`, `ARCHITECTURE.md` for three sources. | `make all` is green. In the lab, meeting notes for Talk are switched on through the chart's values. |
| **MN12** | Live captions in Talk. Two routes: our own overlay, fed through the Nextcloud app; or Synaplan's speech server as the engine behind Nextcloud's own `live_transcription` (research §1.2). | The route is chosen from the lab's results and written into STATUS. If built: a journey walked. |

Order: MN0, then MN1, MN2 and MN3, which do not depend on each other.
Then MN4 → MN5 → MN6 → MN7, then MN8 → MN9 → MN10 → MN11, then MN12.
With §0 #5 turned around, MN8–MN11 come before MN4–MN7.

Gates: `make ci-local` in Synaplan, and `make test-e2e` for MN5 and MN9
(they change the plugin page). The transcriber's tests run in its image,
as CI runs them. `make all` in synaplan-charts. `make ci-local` and the
end-to-end journeys in synaplan-nextcloud.

## 7. Not verified

Each item is answered by a step and recorded in STATUS. Until then it is
unknown.

| # | Question | Answered in | If the answer is no |
| - | -------- | ----------- | ------------------- |
| U1 | Can a Node process receive Talk's audio as an internal client with `werift`, Opus packets untouched? | MN0 | Another WebRTC library for Node, or the adapter in Go with pion. |
| U2 | Does `@livekit/rtc-node` let the hidden transcription participant subscribe to every microphone track and hand out 16 kHz mono PCM? | MN0 | LiveKit's Go SDK, RTP passed through. |
| U3 | Do the speech servers we support accept a WAV window as they accept Ogg? | MN0 | The adapter encodes Opus. |
| U4 | Does `opentalk-compose` 26.3 run on the development machine without a public domain? | MN0 | `ot-setup` 26.3, or develop against the lab. |
| U5 | Which LiveKit address and token does the transcription participant get when it joins? | MN0 | — |
| U6 | What does the transcription participant learn about the others: display name, e-mail, Keycloak subject? | MN4 | The transcript goes to the room's owner only, or stays off. |
| U7 | Does the 26.3 frontend behave as its work item says: a notice for everyone, subtitles per person, desktop layout only? | MN5 | The guide describes what it does. |
| U8 | Will OpenTalk say on screen that a transcript is kept? We ask on its community channel. | MN6 | §0 #7 stays as proposed. |
| U9 | Can an internal client add a visible "Meeting notes" session in Talk, so every client shows a participant and not only a message? | MN8 | Chat message and banner. |
| U10 | Can the Nextcloud app post the two messages as a Talk bot without a moderator enabling the bot in each conversation? | MN10 | The administrator enables it for all conversations, or the message is posted as the person who started. |
| U11 | Can the dev cluster carry the media of Talk's backend and of LiveKit to people outside it? | MN3 | OpenTalk on one virtual machine with `opentalk-compose`. |
| U12 | Does the Nextcloud dev stack hold a Talk call between two browser contexts on `localhost` with the backend container? | MN0 | A local domain with a certificate. |
| U13 | Can the Talk adapter see that a call is end-to-end encrypted before it joins? | MN8 | It stops when the audio cannot be decoded, with one sentence. |
| U14 | Why did the Jitsi notes test fail on synaplan-charts `main` on 2026-11-24? No caption arrived within 120 s; the transcriber logged four bridge connections for one session and its first windows after three and a half minutes. | MN0 | No cause found: MN1 and MN2 need another proof that Jitsi is unchanged. |
| U15 | OpenTalk's contract is young: the service's web API crate went from 0.5 to 0.7 between August and November 2026. Does a later OpenTalk change it? | every OpenTalk release | The guide names the tested OpenTalk versions. |

## 8. Out of scope

- Talk in openDesk. openDesk's chat is Element and its meetings are Jitsi.
  This plan is for Nextcloud installs that use Talk.
- A Talk call without the high-performance backend, federated
  conversations, and phone participants (the bridge publishes them as one
  mixed stream).
- Starting notes from Talk's mobile or desktop app. Those people see the
  chat message.
- OpenTalk before 26.3, and replacing OpenTalk's own service when an
  operator runs one. One RoomServer has one transcription address.
- Live translation, recording, and telling apart several people who share
  one microphone.
- The older path with an API key (`OpendeskSttModule`, the Element bot)
  and Element Call.
