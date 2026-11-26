# Meeting notes

Sidecar for openDesk. It speaks the Jitsi caption socket and Element voice messages, sends audio to Synaplan, and deletes the audio.

PHP does not see the media. Configuration, the Jitsi snippet, and saved transcripts live in Synaplan when `OPENDESK_STT_URL` is set.

Operator guide: [`docs/OPENDESK_MEETING_NOTES.md`](../../docs/OPENDESK_MEETING_NOTES.md).

The image is `ghcr.io/metadist/synaplan-transcriber`. CI publishes `ci-<sha>-amd64` from the tested tar and `ci-<sha>-arm64` from a native build, then a manifest under `latest` on `main` or `<version>`, `<major>.<minor>` and `<major>` on a release tag. A promote tag moves those aliases onto the already published digests. There is no short-commit tag.

```bash
npm test
npm start
```

Modes (`TRANSCRIBER_MODE`):

| Mode | What runs |
| --- | --- |
| `jitsi` | `GET /transcribe` WebSocket for Jitsi Videobridge |
| `element` | Matrix sync for voice messages |
| `opendesk` | Both. This is the openDesk default |
| `opentalk` | `POST /v1/init` for an OpenTalk 26.3 transcription service. Subtitles only; audio is not stored |

## OpenTalk

Subtitles only. Audio is not stored, and no transcript is written.

1. In Synaplan, turn on **OpenTalk meetings** on the synascriber plugin page and set the speech server and `transcriber_secret`.
2. Run this process where it can reach LiveKit:

```bash
TRANSCRIBER_MODE=opentalk
SYNAPLAN_URL=https://your-synaplan.example
SYNAPLAN_TRANSCRIBER_SECRET=same-value-as-the-plugin
OPENTALK_CONTROLLER_URL=https://your-opentalk-controller.example
OPENTALK_SERVICE_KEY=controller:the-controller-secret
OPENTALK_INIT_KEY=transcription:the-transcription-secret
OPENTALK_LIVEKIT_URL=http://livekit:7880
```

3. In the OpenTalk controller, set `[roomserver.modules.transcription]` `url` to this process (port 8095) and `api_key` to the same id and secret as `OPENTALK_INIT_KEY`.

A moderator then uses **More options → Enable subtitles**. The image is Debian, not Alpine: the LiveKit client ships a glibc build only.

Full steps: [`docs/OPENTALK_MEETING_NOTES.md`](../../docs/OPENTALK_MEETING_NOTES.md).

`GET /health` reports which side is on. `GET /element-call` explains why live Element Call captions are not in this version: the bot would have to join as a visible participant.

## Prosody module

`prosody/mod_synascriber.lua` is Jitsi's side of meeting notes: loaded on
Jitsi's Prosody (`XMPP_MODULES=synascriber`, secret `SYNASCRIBER_SECRET`), it
lets the synascriber plugin switch the bridge transcription per room
(`POST /synascriber/start`, `/stop`, `GET /state`, `/health`). It ships in
this image under `/app/prosody/`; synaplan-charts copies it into the Prosody
pod with an init container.
