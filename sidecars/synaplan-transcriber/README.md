# Subtitles service

This program sits next to Jitsi, or next to OpenTalk. It listens to the meeting and sends the sound to Synaplan. Synaplan sends text back. The sound is not kept.

One container does one meeting app. Jitsi and OpenTalk need two containers.

The install, including what you paste into Jitsi or into OpenTalk:

- [docs.synaplan.com/meeting-notes](https://docs.synaplan.com/meeting-notes)
- Jitsi in this repository: [docs/OPENDESK_MEETING_NOTES.md](../../docs/OPENDESK_MEETING_NOTES.md)
- OpenTalk in this repository: [docs/OPENTALK_MEETING_NOTES.md](../../docs/OPENTALK_MEETING_NOTES.md)

## Jitsi

```bash
docker run -d --name synaplan-jitsi \
  -p 8095:8095 \
  -e TRANSCRIBER_MODE=jitsi \
  -e SYNAPLAN_URL=https://your-synaplan.example \
  -e SYNAPLAN_TRANSCRIBER_SECRET=password-one \
  -e TRANSCRIBER_AUTH_TOKEN=password-two \
  ghcr.io/metadist/synaplan-transcriber:latest
```

`password-one` is the transcriber secret on the Synaplan plugin page. `password-two` is what Jicofo sends. The button, Prosody, and the Jicofo block are on the Jitsi page linked above.

The Prosody file is in this image at `/app/prosody/mod_synascriber.lua`.

## OpenTalk

```bash
docker run -d --name synaplan-opentalk \
  -p 8095:8095 \
  -e TRANSCRIBER_MODE=opentalk \
  -e SYNAPLAN_URL=https://your-synaplan.example \
  -e SYNAPLAN_TRANSCRIBER_SECRET=the-password-from-the-plugin-page \
  -e OPENTALK_CONTROLLER_URL=https://your-opentalk.example \
  -e OPENTALK_SERVICE_KEY=controller:the-controller-secret \
  -e OPENTALK_INIT_KEY=transcription:password-you-invent \
  -e OPENTALK_LIVEKIT_URL=http://livekit:7880 \
  ghcr.io/metadist/synaplan-transcriber:latest
```

The service does not start without the LiveKit address. Paste `transcription` and `password-you-invent` into the OpenTalk controller, as on the OpenTalk page linked above.

Open `http://the-service:8095/health`. The page says `"ok": true`.

## Image

The image is `ghcr.io/metadist/synaplan-transcriber`. CI publishes `ci-<sha>-amd64` from the tested tar and `ci-<sha>-arm64` from a native build, then a manifest under `latest` on `main` or `<version>`, `<major>.<minor>` and `<major>` on a release tag. A promote tag moves those aliases onto the already published digests. There is no short-commit tag. The image is Debian, not Alpine.

```bash
npm test
npm start
```
