# Meeting notes

One process, one mode (`TRANSCRIBER_MODE`). It holds the meeting audio, sends short windows to Synaplan, and deletes the audio. PHP does not see the media.

Operator guide: [`docs/OPENDESK_MEETING_NOTES.md`](../../docs/OPENDESK_MEETING_NOTES.md). OpenTalk: [`docs/OPENTALK_MEETING_NOTES.md`](../../docs/OPENTALK_MEETING_NOTES.md).

The image is `ghcr.io/metadist/synaplan-transcriber`. CI publishes `ci-<sha>-amd64` from the tested tar and `ci-<sha>-arm64` from a native build, then a manifest under `latest` on `main` or `<version>`, `<major>.<minor>` and `<major>` on a release tag. A promote tag moves those aliases onto the already published digests. There is no short-commit tag.

```bash
npm test
npm start
```

| Mode | What is armed | Credential |
| --- | --- | --- |
| `jitsi` | Jitsi Videobridge WebSocket `GET /transcribe`. With `SYNAPLAN_TRANSCRIBER_SECRET` this is the meeting-notes plugin (the openDesk chart). With only `SYNAPLAN_API_KEY` these are captions without the plugin | `TRANSCRIBER_AUTH_TOKEN`, sent by Jicofo as `Authorization: Bearer` |
| `element` | Matrix sync for voice messages | `SYNAPLAN_API_KEY` with scope `audio:transcribe` |
| `opendesk` | Both of the above. Compose defaults to this. It is the older path | both of the above, as far as you use each side |
| `opentalk` | `POST /v1/init` for an OpenTalk 26.3 transcription service. Subtitles only; audio is not stored. Not started by the openDesk chart | `SYNAPLAN_TRANSCRIBER_SECRET`, plus the OpenTalk keys and `OPENTALK_LIVEKIT_URL` |

`GET /health` names the paths that are actually armed (`plugin`, `captionsWithoutPlugin`, `opentalk`, `element`). `GET /element-call` explains why live Element Call captions are not in this version: the bot would have to join as a visible participant.

## OpenTalk

Subtitles only. A missing `OPENTALK_LIVEKIT_URL` stops the process at startup. OpenTalk's popout token does not carry the media address, so a process without it can sign in and still hear nobody.

```bash
TRANSCRIBER_MODE=opentalk
SYNAPLAN_URL=https://your-synaplan.example
SYNAPLAN_TRANSCRIBER_SECRET=same-value-as-the-plugin
OPENTALK_CONTROLLER_URL=https://your-opentalk-controller.example
OPENTALK_SERVICE_KEY=controller:the-controller-secret
OPENTALK_INIT_KEY=transcription:the-transcription-secret
OPENTALK_LIVEKIT_URL=http://livekit:7880
```

In the OpenTalk controller, set `[roomserver.modules.transcription]` `url` to this process (port 8095) and `api_key` to the same id and secret as `OPENTALK_INIT_KEY`.

A moderator then uses **More options → Enable subtitles**. The image is Debian, not Alpine: the LiveKit client ships a glibc build only.

## Prosody module

`prosody/mod_synascriber.lua` is Jitsi's side of meeting notes: loaded on
Jitsi's Prosody (`XMPP_MODULES=synascriber`, secret `SYNASCRIBER_SECRET`), it
lets the synascriber plugin switch the bridge transcription per room
(`POST /synascriber/start`, `/stop`, `GET /state`, `/health`). It ships in
this image under `/app/prosody/`; synaplan-charts copies it into the Prosody
pod with an init container.
