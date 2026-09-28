# Meeting notes

Meeting notes turn speech into text for an [openDesk](https://www.opendesk.eu/en/product) installation.

- **Jitsi** (the meetings app Nordeck maintains for openDesk) shows live captions.
- **Element** (Matrix chat) replies to a voice message with the text.
- The **transcript** is what is kept. Audio is discarded after each window.

Synaplan is the speech-to-text engine. A small sidecar, `sidecars/synaplan-transcriber`, holds the meeting audio. PHP never opens a Jitsi or Matrix socket.

The detailed plan lives in this repository at `_devextras/planning/20260917-backend-integrations/04_opendesk_audio_transcriber.md`. The private planning checkout named in early notes was not required: the sidecar sits next to the other sidecars, and the Synaplan module only stores text.

## Turn it on

The feature is absent until you point Synaplan at the sidecar. No URL means no routes and no Meeting notes row that claims to be installed.

```bash
# backend/.env
OPENDESK_STT_URL=http://transcriber:8095
OPENDESK_STT_PUBLIC_URL=https://notes.example
OPENDESK_STT_LANGUAGE=auto
OPENDESK_STT_MODE=opendesk
```

`OPENDESK_STT_MODE` is `jitsi`, `element`, or `opendesk` (both). Language is `auto`, `de`, `en`, `es`, `fr`, or `tr`. Empty language means auto. On a sovereign install, leave `SYNAPLAN_STT_MODEL` empty so the sidecar uses the local speech-to-text model. A cloud model is opt-in.

Create an API key with only the scope `audio:transcribe`. That key can transcribe and save notes. It cannot chat, and it cannot read files.

Start the sidecar (it is not part of a plain `docker compose up`):

```bash
SYNAPLAN_API_KEY=sk_... docker compose --profile transcriber up -d transcriber
```

The same variables are listed in `sidecars/synaplan-transcriber/env.example` and, for Helm, in `sidecars/synaplan-transcriber/helm/opendesk-values.example.yaml`.

Signed-in operators can read the snippet Synaplan would hand to Jitsi:

`GET /api/v1/opendesk/meeting-notes/connect`

## Jitsi (Nordeck)

Jitsi Videobridge opens one WebSocket and sends each person’s Opus audio as JSON (`event: media`, base64 payload). The sidecar wraps those packets as Ogg, sends an 8 second window to `POST /v1/audio/transcriptions/sessions`, and writes a `transcription-result` back on the same socket. That is the caption the meeting shows.

In Jicofo:

```
transcription {
  url-template = "wss://notes.example/transcribe?sessionId={{MEETING_ID}}&sendBack=true"
  http-headers {
    "Authorization" = "Bearer <TRANSCRIBER_AUTH_TOKEN>"
  }
}
```

`{{MEETING_ID}}` and `{{REGION}}` are Jicofo’s own placeholders. In `config.js`:

```js
config.transcription = { enabled: true }
```

Enable transcription in Prosody the way your Jitsi (or docker-jitsi-meet) release documents it. Do not point this at Jigasi. Jitsi’s own handbook marks Jigasi transcription as deprecated; Nordeck still uses Jigasi for phone dial-in, which is a different job.

In the meeting, a moderator uses Jitsi’s own transcription control. Everyone sees captions. Closing the socket (Stop, or the last person leaving) writes the text and drops the audio.

Captions arrive per window, about every 8 seconds, not word by word.

## Element (Matrix)

Set `MATRIX_HOMESERVER`, `MATRIX_ACCESS_TOKEN`, and `MATRIX_USER_ID` (for example `@synaplan-notes:example`). Invite that account to a room the way you invite a person.

A voice message (`m.audio`, including Element’s voice-message flag) is downloaded and sent to `POST /v1/audio/transcriptions`. The reply is a thread on that message. If there was no speech, the reply is “No speech in this note.”

Encrypted rooms are refused. The sidecar does not break Megolm. The room gets one notice:

> This room is locked. Invite Meeting notes as a member, or turn captions off.

Invite the account as a normal member of an unencrypted room and voice messages work. If the room stays encrypted, this version still cannot read it.

### Element Call

Element Call (MatrixRTC / LiveKit) is how openDesk does 1:1 and small video. Live captions there mean joining the call as a **visible** participant named Meeting notes. Hidden listening is not acceptable, and that join is not in this version.

`GET /element-call` on the sidecar answers with that explanation. Voice messages in the Element room work today. Jitsi meetings work today.

## Where the notes go

After a meeting the sidecar, if you configured them:

1. Saves the transcript on Synaplan (`POST /api/v1/opendesk/meeting-notes`). List them with `GET /api/v1/opendesk/meeting-notes`.
2. Writes a Markdown file into Nextcloud (`NEXTCLOUD_URL`, user, app password, `NOTES_NEXTCLOUD_FOLDER`, default `/Meetings`).
3. Posts one line in `NOTES_MATRIX_ROOM`, for example “Notes from 10:00 — open in Files /Meetings/2026-09-28-standup.md. Audio was not kept.”

The sentence at the end says what was saved. A destination that was configured and failed is named. A destination you never configured is left out.

## Stop

- **Stop** in the Jitsi meeting closes the socket. Captions end. Notes already saved stay. Audio was not kept.
- **Disconnect** means deleting the `audio:transcribe` key in Synaplan, and removing the Matrix access token. The sidecar then cannot transcribe or post.

## What this version does not do

- It does not replace Jitsi or Element.
- It does not transcribe Element Call live.
- It does not translate live, keep the audio, or record the video.
- It does not use Nextcloud Talk. Talk is a different product.
