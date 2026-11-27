> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 4: Channels, Gateways & Integrations (12/12)**
> **← Previous:** [4.11 Meeting subtitles in Jitsi](OPENDESK_MEETING_NOTES.md) · **Next:** [5.1 Local Development Workflow →](DEVELOPMENT.md)

# Meeting subtitles in OpenTalk

Synaplan writes the subtitles in an OpenTalk meeting, and saves the transcript when they stop. People read the lines in the meeting. Each person with a Synaplan account finds the text afterwards. Audio is not kept.

You already have OpenTalk 26.3 or newer. You start Synaplan's subtitles service and you tell OpenTalk where it is. Jitsi is a different install: [Meeting subtitles in Jitsi](OPENDESK_MEETING_NOTES.md). If that service is already running, leave it alone and start a second one.

The same steps are on [docs.synaplan.com/meeting-notes](https://docs.synaplan.com/meeting-notes).

**1. In Synaplan**, as an administrator. If Meeting notes is not in the sidebar:

```bash
php bin/console app:plugin:install-verified-users synascriber
```

Open the plugin page. Turn on **OpenTalk meetings**. Set the speech server. Invent a password and put it in **Transcriber secret**.

**2. Start the service** on a machine that can reach OpenTalk's LiveKit. Inside Docker that address is often `http://livekit:7880`.

You need the controller API key OpenTalk already has. It looks like `controller:a-long-secret`. If you do not have it, ask the person who installed OpenTalk.

Invent a second password. You paste it into OpenTalk in the next step.

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

The service does not start if the LiveKit address is empty.

**3. In OpenTalk.** Add this on the controller and restart it. The address is the machine from step 2, as the controller can reach it.

```toml
[roomserver.modules.transcription]
url = "https://the-subtitles-service:8095"
api_key = { id = "transcription", secret = "password-you-invent" }
```

**4. In a meeting.** A moderator opens **More options → Enable subtitles**, turns the switch on, picks a language, and saves. Everyone else presses **Show**. Turning the switch off stops the lines.

When the lines stop, Synaplan saves the transcript. Each person in the meeting who has a Synaplan account gets a copy under **Sources → Generated**. When nobody in the meeting matches an account, every administrator gets the copy, so the meeting is not lost. Audio is not kept. OpenTalk itself only says that subtitles are on, so tell people that a transcript is kept.

Open `http://the-service:8095/health`. The page says `"ok": true`.

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 4: Channels, Gateways & Integrations (12/12)**
> **← Previous:** [4.11 Meeting subtitles in Jitsi](OPENDESK_MEETING_NOTES.md) · **Next:** [5.1 Local Development Workflow →](DEVELOPMENT.md)
