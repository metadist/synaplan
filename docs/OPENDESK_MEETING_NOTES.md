> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 4: Channels, Gateways & Integrations (12/12)**
> **← Previous:** [4.10 Self-Hosted Live Voice Endpoints](LIVE_VOICE_SELF_HOSTED.md) · **Next:** [4.12 OpenTalk subtitles →](OPENTALK_MEETING_NOTES.md)

# Meeting notes

Synaplan turns speech in a meeting into text. The customer already runs the meeting product. Synaplan does not install Jitsi or OpenTalk.

One transcriber process runs one mode. Jitsi meeting notes and OpenTalk subtitles are two processes.

| Install | Mode | What people get |
| --- | --- | --- |
| Jitsi in openDesk | `jitsi`, with the meeting-notes plugin | Live captions, then one Markdown transcript per signed-in participant |
| OpenTalk 26.3 or later | `opentalk`, its own process | Live subtitles in OpenTalk. Nothing is saved. See [OpenTalk subtitles](OPENTALK_MEETING_NOTES.md) |
| Older path | `opendesk`, `jitsi`, or `element`, with an API key | Jitsi captions without the plugin, and Element voice messages |

Audio is not stored on any of these paths.

## What you run

| Piece | Where | Required for |
| --- | --- | --- |
| Plugin `synascriber` | inside Synaplan | Jitsi notes and OpenTalk subtitles |
| Speech server | whisper.cpp, or any server with `POST /v1/audio/transcriptions` | both |
| Transcriber image `ghcr.io/metadist/synaplan-transcriber` | its own container, port 8095 | both, one container per mode |
| Jitsi button, Prosody module, Jicofo | the Jitsi you already run | Jitsi notes |
| OpenTalk transcription module | the OpenTalk controller you already run | OpenTalk subtitles |

`GET /health` on port 8095 says which path that process actually armed: `plugin`, `captionsWithoutPlugin`, `opentalk`, `element`. A process that cannot hear an OpenTalk meeting refuses to start.

## Jitsi in openDesk

This is the install for a new openDesk. The Helm release in [synaplan-charts](https://github.com/metadist/synaplan-charts/blob/main/helmfile/README.md#meeting-notes) deploys it. The chart does not deploy OpenTalk.

```yaml
apps:
  synaplanStt:
    enabled: true
technical:
  synaplan:
    synascriber:
      enabled: true
      jitsiRelease: jitsi
customization:
  release:
    jitsi:
      synascriber: "<synaplan-charts>/helmfile/opendesk/synascriber-jitsi.yaml.gotmpl"
```

Also add `helmfile/opendesk/synascriber-client.yaml.gotmpl` to openDesk's environment, then apply.

The release then installs the plugin for every user, starts the transcriber in mode `jitsi`, writes the plugin settings, loads the Meeting notes button into Jitsi, and points Jicofo's bridge at the transcriber. Whisper comes from the `stt` release, or from `technical.synaplan.speech.whisperServerUrl` when you already run a speech server.

In a meeting, a signed-in person starts notes with the Meeting notes button. Everyone sees captions. When the notes stop, each signed-in participant finds a Markdown file under Sources › Generated. Guests get no copy.

### Without the chart

1. Install the plugin for every user: `DEFAULT_USER_PLUGINS=["synascriber"]`. For accounts that already exist: `php bin/console app:plugin:install-verified-users synascriber`.
2. Write the settings. Put secrets on stdin, not in the command:

   ```bash
   echo '{"enabled": true, "stt_url": "http://whisper:8080", "prosody_url": "http://prosody:5280",
          "jitsi_url": "https://meet.example", "prosody_secret": "…", "transcriber_secret": "…"}' \
     | php bin/console synascriber:settings
   ```

3. Start the transcriber with `TRANSCRIBER_MODE=jitsi`, `SYNAPLAN_URL`, `SYNAPLAN_TRANSCRIBER_SECRET` (the same value as `transcriber_secret`), and `TRANSCRIBER_AUTH_TOKEN` (the bearer Jicofo sends).
4. On Jitsi, load the button, the Prosody module, and the Jicofo transcription URL. The parts and the three secrets are in [plugins/synascriber/README.md](../plugins/synascriber/README.md). How a meeting moves from the button to the saved file is in [plugins/synascriber/ARCHITECTURE.md](../plugins/synascriber/ARCHITECTURE.md).

The speech server is any OpenAI transcription endpoint. whisper.cpp ignores `stt_model`. vLLM and a cloud service need `stt_model` and, when the server requires it, `stt_api_key`.

## OpenTalk

OpenTalk subtitles are a second transcriber, `TRANSCRIBER_MODE=opentalk`. The chart above does not start it. The plugin switch is **OpenTalk meetings**. OpenTalk 26.3 or later calls this process; a moderator uses OpenTalk's own subtitle control. Synaplan writes no file.

The variables, the controller block, and the limits are in [OpenTalk subtitles](OPENTALK_MEETING_NOTES.md).

## Older path

`OPENDESK_STT_URL` is the path from before the plugin. It is still shipped. Use it for Element voice messages, or for a Jitsi that points Jicofo at this process without the Meeting notes button. The plugin does not read these variables, and this path does not read the plugin's settings.

The feature is absent until the URL is set. No URL means no routes and no row that claims this path is installed.

```bash
# backend/.env
OPENDESK_STT_URL=http://transcriber:8095
OPENDESK_STT_PUBLIC_URL=https://notes.example
OPENDESK_STT_LANGUAGE=auto
OPENDESK_STT_MODE=opendesk
```

`OPENDESK_STT_MODE` is `jitsi`, `element`, or `opendesk` (both). Language is `auto`, `de`, `en`, `es`, `fr`, or `tr`. On a sovereign install, leave `SYNAPLAN_STT_MODEL` empty so the sidecar uses the local speech-to-text model.

Create an API key with only the scope `audio:transcribe`. That key can transcribe and save a finished note. It cannot chat, list notes, or read the operator snippet. Give it to the transcriber as `SYNAPLAN_API_KEY`.

`TRANSCRIBER_AUTH_TOKEN` is required for `jitsi` and `opendesk`. Jicofo sends it as `Authorization: Bearer`. The local Compose port is bound to `127.0.0.1`. The Compose default mode is `opendesk`, which is this older path:

```bash
SYNAPLAN_API_KEY=sk_... TRANSCRIBER_AUTH_TOKEN=... docker compose --profile transcriber up -d transcriber
```

The same variables are in `sidecars/synaplan-transcriber/env.example`. `sidecars/synaplan-transcriber/helm/opendesk-values.example.yaml` is an example of this older path. It is not the synaplan-charts release.

Signed-in operators can read the snippet Synaplan would hand to Jitsi:

`GET /api/v1/opendesk/meeting-notes/connect`

### Jitsi without the plugin

Jitsi Videobridge opens one WebSocket and sends each person's Opus audio as JSON. The sidecar sends an 8 second window to `POST /v1/audio/transcriptions/sessions` and writes a caption back on the same socket.

In Jicofo:

```
transcription {
  url-template = "wss://notes.example/transcribe?sessionId={{MEETING_ID}}&sendBack=true"
  http-headers {
    "Authorization" = "Bearer <TRANSCRIBER_AUTH_TOKEN>"
  }
}
```

`{{MEETING_ID}}` and `{{REGION}}` are Jicofo's own placeholders. In `config.js`:

```js
config.transcription = { enabled: true }
```

Enable transcription in Prosody the way your Jitsi release documents it. Do not point this at Jigasi. Jitsi marks Jigasi transcription as deprecated. Nordeck still uses Jigasi for phone dial-in, which is a different job.

A moderator uses Jitsi's own transcription control. Captions arrive about every 8 seconds. Closing the socket writes the text and drops the audio.

### Element

Set `MATRIX_HOMESERVER`, `MATRIX_ACCESS_TOKEN`, and `MATRIX_USER_ID` (for example `@synaplan-notes:example`). Invite that account to a room the way you invite a person.

A voice message is downloaded and sent to `POST /v1/audio/transcriptions`. The reply is a thread on that message. If there was no speech, the reply is "No speech in this note."

Encrypted rooms are refused. The sidecar does not break Megolm. Invite the account as a normal member of an unencrypted room.

Element Call is not transcribed. Live captions there would mean joining the call as a visible participant, and that join is not in this version. `GET /element-call` on the sidecar says so.

### Where those notes go

After a meeting on this older path, the sidecar, for each destination you configured:

1. Saves a copy through Synaplan (`POST /api/v1/opendesk/meeting-notes`).
2. Writes a Markdown file into Nextcloud (`NEXTCLOUD_URL`, user, app password, `NOTES_NEXTCLOUD_FOLDER`, default `/Meetings`). The file name includes the time.
3. Posts one line in `NOTES_MATRIX_ROOM`.

A destination you never configured is left out of the closing sentence. A destination that was configured and failed is named.

### Stop

- **Stop** in the Jitsi meeting closes the socket. Captions end. Notes already saved stay.
- **Disconnect** means deleting the `audio:transcribe` key, and removing the Matrix access token.

## What none of this does

- It does not install or replace Jitsi, Element, or OpenTalk.
- It does not transcribe Element Call.
- It does not translate live, keep the audio, or record the video.
- It does not use Nextcloud Talk.

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 4: Channels, Gateways & Integrations (12/12)**
> **← Previous:** [4.10 Self-Hosted Live Voice Endpoints](LIVE_VOICE_SELF_HOSTED.md) · **Next:** [4.12 OpenTalk subtitles →](OPENTALK_MEETING_NOTES.md)
