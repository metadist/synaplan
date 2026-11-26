> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 4: Channels, Gateways & Integrations (12/12)**
> **← Previous:** [4.10 Self-Hosted Live Voice Endpoints](LIVE_VOICE_SELF_HOSTED.md) · **Next:** [4.12 OpenTalk subtitles →](OPENTALK_MEETING_NOTES.md)

# Meeting subtitles in Jitsi

Synaplan writes what people say in a Jitsi meeting. People see the lines in the meeting. When the notes stop, each signed-in person gets a text file in Synaplan under **Sources → Generated**.

You already have Jitsi. You start Synaplan's subtitles service and you tell Jitsi where it is. OpenTalk is a different install: [OpenTalk subtitles](OPENTALK_MEETING_NOTES.md). Do not point both at the same service.

The same steps are on [docs.synaplan.com/meeting-notes](https://docs.synaplan.com/meeting-notes).

You need a speech service (Whisper, or one you already use). On openDesk the steps below turn Whisper on.

## OpenDesk, with the Helm charts

Add this and apply. You do not edit Jitsi by hand. The charts put the **Meeting notes** button into Jitsi and start the service.

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

`<synaplan-charts>` is your checkout of [synaplan-charts](https://github.com/metadist/synaplan-charts). That file is the Jitsi install.

Also put `helmfile/opendesk/synascriber-client.yaml.gotmpl` into the openDesk environment folder, next to `synaplan-client.yaml.gotmpl`. That lets the button sign people in.

Apply the Synaplan release and the Jitsi release. In a meeting, click **Meeting notes**, pick a language, and speak.

## Without the charts

Invent two long passwords.

**1. Start the service**, where it can reach Synaplan and Jitsi can reach it.

```bash
docker run -d --name synaplan-jitsi \
  -p 8095:8095 \
  -e TRANSCRIBER_MODE=jitsi \
  -e SYNAPLAN_URL=https://your-synaplan.example \
  -e SYNAPLAN_TRANSCRIBER_SECRET=password-one \
  -e TRANSCRIBER_AUTH_TOKEN=password-two \
  ghcr.io/metadist/synaplan-transcriber:latest
```

**2. In Synaplan**, as an administrator. If Meeting notes is not in the sidebar:

```bash
php bin/console app:plugin:install-verified-users synascriber
```

On the plugin page:

| Field | What to put |
| --- | --- |
| Speech server | The address of Whisper, or of your speech service |
| Jitsi address | The address people use to join, such as `https://meet.example` |
| Prosody address | Jitsi's Prosody inside your network. In Docker this is often `http://prosody:5280` |
| Shared secret with Jitsi | A third password. Prosody needs the same one below |
| Transcriber secret | `password-one` |

**3. In Jitsi.** Then restart Prosody and Jicofo.

The button, in `head.html`:

```html
<script defer src="https://your-synaplan.example/api/v1/plugins/synascriber/assets/jitsi/loader.js"
  data-synaplan="https://your-synaplan.example"
  data-issuer="https://your-keycloak.example/realms/your-realm"
  data-client-id="synascriber"></script>
```

In Keycloak, create a public client named `synascriber`, with no secret. Redirect address:

`https://your-synaplan.example/api/v1/plugins/synascriber/jitsi/silent`

Prosody. Copy `/app/prosody/mod_synascriber.lua` from the subtitles image into Prosody's plugin folder, and set:

```
XMPP_MODULES=synascriber
SYNASCRIBER_SECRET=the-third-password
```

Jicofo. This sends the audio to Synaplan:

```
transcription {
  url-template = "ws://synaplan-jitsi:8095/transcribe?sessionId={{MEETING_ID}}"
  http-headers {
    "Authorization" = "Bearer password-two"
  }
}
```

`synaplan-jitsi` works when Jicofo and the service share a Docker network. Otherwise use the host name of the machine from step 1.

## It works when

Open `http://the-service:8095/health`. The page says `"ok": true`. In a meeting, click **Meeting notes** and say a sentence. The line shows the speaker's name.

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 4: Channels, Gateways & Integrations (12/12)**
> **← Previous:** [4.10 Self-Hosted Live Voice Endpoints](LIVE_VOICE_SELF_HOSTED.md) · **Next:** [4.12 OpenTalk subtitles →](OPENTALK_MEETING_NOTES.md)
