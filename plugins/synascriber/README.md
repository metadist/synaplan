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
| `/api/v1/plugins/synascriber/jitsi/silent` | `backend/`, public | the page Keycloak returns to after the button's sign-in; it hands the code to the Jitsi window named in the `jitsi_url` setting, and only that window may embed it |
| `frontend/index.js` | this plugin | Synaplan's plugin page |
| `mod_synascriber.lua` | [sidecars/synaplan-transcriber/prosody](../../sidecars/synaplan-transcriber/prosody/) | Jitsi's Prosody: switches the bridge transcription per room |
| transcriber | [sidecars/synaplan-transcriber](../../sidecars/synaplan-transcriber/) | its own pod |

## Setup

No account is involved: the settings, the sessions and the loader belong to
the installation, not to a user.

1. Install the plugin for every user: `DEFAULT_USER_PLUGINS=["synascriber"]`
   (existing users: `php bin/console app:plugin:install-verified-users synascriber`).
2. Write the settings. Secrets go in on stdin, never as arguments:

   ```bash
   echo '{"enabled": true, "stt_url": "http://whisper:8080", "prosody_url": "http://prosody:5280",
          "jitsi_url": "https://meet.example", "prosody_secret": "…", "transcriber_secret": "…"}' \
     | php bin/console synascriber:settings
   ```

   `--show` prints them (secrets only as set / not set). An administrator can
   change the same settings on the plugin page.
3. Give the transcriber `SYNAPLAN_TRANSCRIBER_SECRET` = `transcriber_secret`. It
   sends it as `X-Transcriber-Secret`; the transcriber routes accept nothing
   else.
4. Load the button in Jitsi's `head.html`:

   ```html
   <script defer src="https://<synaplan>/api/v1/plugins/synascriber/assets/jitsi/loader.js"
     data-synaplan="https://<synaplan>" data-issuer="https://<keycloak>/realms/<realm>"
     data-client-id="synascriber"></script>
   ```

   The older address with a user id (`/api/v1/user/<id>/plugins/synascriber/assets/…`)
   still works.

### Secrets

Three shared secrets, each between two parties. One value may appear under
more than one name; the names say where it is set.

| Secret | Held by | Set as |
|--------|---------|--------|
| transcriber secret | plugin ↔ transcriber | plugin setting `transcriber_secret`; transcriber env `SYNAPLAN_TRANSCRIBER_SECRET`, sent as header `X-Transcriber-Secret` |
| Prosody secret | plugin ↔ Jitsi's Prosody module | plugin setting `prosody_secret`; Prosody env `SYNASCRIBER_SECRET` |
| transcriber token | Jitsi's Jicofo ↔ transcriber | Jicofo's transcription `Authorization: Bearer …`; transcriber env `TRANSCRIBER_AUTH_TOKEN` |

`SYNAPLAN_API_KEY` is not one of them: the transcriber needs it only for
the paths that act as a Synaplan user (Element voice messages, Jitsi
captions without this plugin).

### Upgrading from 0.3.0

Up to 0.3.0 the first account that installed the plugin was its owner: the
transcriber used that account's API key and the sessions were stored under
it. Plugin 0.4.0 refuses that path on both ends, so a deployment moves in
lockstep: set `transcriber_secret` and give the transcriber
`SYNAPLAN_TRANSCRIBER_SECRET` in the same rollout, or meeting notes answer
`not_configured` until both are there. `migrations/002_store_without_owner.sql`
moves the stored sessions to the installation and deletes `owner_user_id`;
plugin migrations run on every install for every user, so the file is
idempotent and a rerun changes nothing. The owner's API key row in
`BAPIKEYS` stays; it authorises nothing on the plugin routes any more and
can be deleted from the API keys page.

Deployment (Jitsi's values, the Keycloak client, the transcriber, the
registration of the plugin): [synaplan-charts](https://github.com/metadist/synaplan-charts),
`technical.synaplan.synascriber`.

Tests: `backend/tests/Plugin/SynaScriber/`.
