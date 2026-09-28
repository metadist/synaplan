# Meeting notes

Sidecar for openDesk. It speaks the Jitsi caption socket and Element voice messages, sends audio to Synaplan, and deletes the audio.

PHP does not see the media. Configuration, the Jitsi snippet, and saved transcripts live in Synaplan when `OPENDESK_STT_URL` is set.

Operator guide: [`docs/OPENDESK_MEETING_NOTES.md`](../../docs/OPENDESK_MEETING_NOTES.md).

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

`GET /health` reports which side is on. `GET /element-call` explains why live Element Call captions are not in this version: the bot would have to join as a visible participant.
