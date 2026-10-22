# synascriber: meeting notes for Jitsi in openDesk

Jitsi's bridge streams each speaker's audio to the transcriber
([sidecars/synaplan-transcriber](../../sidecars/synaplan-transcriber/)); the
transcriber sends short audio windows to this plugin; the plugin gets the
text from a Whisper server and returns it as live captions. When the meeting
stops, the plugin saves one Markdown transcript per signed-in participant in
Sources, Generated. Audio is never stored.

| Part | Where | Runs in |
|------|-------|---------|
| `backend/` | this plugin | Synaplan: routes under `/api/v1/plugins/synascriber` (manifest) |
| `frontend/jitsi/loader.js` | this plugin, served as a plugin asset | each participant's browser in Jitsi: button, dialog, captions |
| `frontend/index.js` | this plugin | Synaplan's plugin page |
| `mod_synascriber.lua` | [sidecars/synaplan-transcriber/prosody](../../sidecars/synaplan-transcriber/prosody/) | Jitsi's Prosody: switches the bridge transcription per room |
| transcriber | [sidecars/synaplan-transcriber](../../sidecars/synaplan-transcriber/) | its own pod |

Deployment (Jitsi's values, the Keycloak client, the transcriber, the
registration of the plugin): [synaplan-charts](https://github.com/metadist/synaplan-charts),
`technical.synaplan.synascriber`. Install the plugin for every user with
`DEFAULT_USER_PLUGINS=["synascriber"]`.

Tests: `backend/tests/Plugin/SynaScriber/`.
