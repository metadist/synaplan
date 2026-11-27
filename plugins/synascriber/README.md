# Meeting subtitles

This plugin is Synaplan's side of subtitles for **Jitsi** and for **OpenTalk**.

An administrator installs it by starting one small program and telling the meeting app where that program is. Jitsi and OpenTalk each need their own program.

- Install: [docs.synaplan.com/meeting-notes](https://docs.synaplan.com/meeting-notes)
- Jitsi, in this repository: [docs/OPENDESK_MEETING_NOTES.md](../../docs/OPENDESK_MEETING_NOTES.md)
- OpenTalk, in this repository: [docs/OPENTALK_MEETING_NOTES.md](../../docs/OPENTALK_MEETING_NOTES.md)

Jitsi on openDesk, with the Helm charts: add the block in the Jitsi page and apply. The **Meeting notes** button appears. You do not edit Jitsi by hand.

OpenTalk: turn on **OpenTalk meetings** on this plugin's page, start a second program, and paste its address into the OpenTalk controller. A moderator then uses **More options → Enable subtitles**. When the lines stop, the transcript is saved the same way as a Jitsi meeting.

## For people changing this plugin

How a Jitsi meeting moves from the button to the saved file: [ARCHITECTURE.md](ARCHITECTURE.md).

| Part | Where | Runs in |
|------|-------|---------|
| `backend/` | this plugin | Synaplan |
| `frontend/jitsi/loader.js` | served by Synaplan, loaded by Jitsi | the browser: button and subtitles |
| `frontend/index.js` | this plugin | the plugin page in Synaplan |
| `mod_synascriber.lua` | [the subtitles image](../../sidecars/synaplan-transcriber/prosody/) | Jitsi's Prosody |
| subtitles service | [sidecars/synaplan-transcriber](../../sidecars/synaplan-transcriber/) | its own container |

Deployment for Jitsi on openDesk: [synaplan-charts](https://github.com/metadist/synaplan-charts), `technical.synaplan.synascriber`. That chart does not start the OpenTalk service.

Tests: `backend/tests/Plugin/SynaScriber/`.

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
