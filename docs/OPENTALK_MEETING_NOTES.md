> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 4: Channels, Gateways & Integrations (12/12)**
> **← Previous:** [4.11 openDesk Meeting Notes](OPENDESK_MEETING_NOTES.md) · **Next:** [5.1 Local Development Workflow →](DEVELOPMENT.md)

# OpenTalk subtitles

Synaplan writes the subtitles in an OpenTalk meeting. People read the lines in the meeting. Synaplan does not keep the audio, and it does not save a transcript.

You need three things: the meeting-notes plugin, the transcriber, and one setting in OpenTalk.

## 1. Turn it on in Synaplan

Install the plugin if it is not installed yet:

```bash
php bin/console app:plugin:install-verified-users synascriber
```

Open the plugin page. Turn on **OpenTalk meetings**.

Set the speech server the same way you would for any transcription: its address, its model, and its key. Set a transcriber secret. You will paste that same secret into the transcriber in the next step.

An administrator can do this on the plugin page, or with `synascriber:settings`. Put secrets on stdin, not in the command:

```bash
echo '{"opentalk_enabled": true, "stt_url": "https://api.groq.com/openai", "stt_model": "whisper-large-v3", "stt_api_key": "…", "transcriber_secret": "…"}' \
  | php bin/console synascriber:settings
```

## 2. Start the transcriber

Run `synaplan-transcriber` where it can reach OpenTalk's LiveKit server. On Docker Desktop that means the same network as LiveKit. A process on another machine can sign in and still fail to hear anyone.

```bash
TRANSCRIBER_MODE=opentalk
SYNAPLAN_URL=https://your-synaplan.example
SYNAPLAN_TRANSCRIBER_SECRET=the-secret-from-step-1
OPENTALK_CONTROLLER_URL=https://your-opentalk-controller.example
OPENTALK_SERVICE_KEY=controller:the-controller-secret
OPENTALK_INIT_KEY=transcription:the-transcription-secret
OPENTALK_LIVEKIT_URL=http://livekit:7880
```

Both keys are written `id:secret`.

- `OPENTALK_SERVICE_KEY` is how the transcriber calls OpenTalk.
- `OPENTALK_INIT_KEY` is how OpenTalk calls the transcriber.
- `OPENTALK_LIVEKIT_URL` is the media server. OpenTalk's popout token does not include that address.

`GET /health` on port 8095 answers when the process is up.

## 3. Point OpenTalk at the transcriber

In the OpenTalk controller config:

```toml
[roomserver.modules.transcription]
url = "https://transcriber.example:8095"
api_key = { id = "transcription", secret = "the-transcription-secret" }
```

The id and secret are the same pair as `OPENTALK_INIT_KEY`. Restart the controller after you save this.

## Use it

A moderator opens **More options → Enable subtitles**, turns on **Enable subtitles for everyone**, picks a language, and saves.

People who want the lines press **Show**. Each line has the speaker's name.

To stop, open the same dialog, turn the switch off, and save. The lines stop. Nothing is written down.

## What this does not do

- It does not save a transcript. Jitsi meeting notes do. OpenTalk subtitles do not.
- It does not cover a breakout room.
- It does not run when the meeting is end-to-end encrypted. OpenTalk hides the control in that case.

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 4: Channels, Gateways & Integrations (12/12)**
> **← Previous:** [4.11 openDesk Meeting Notes](OPENDESK_MEETING_NOTES.md) · **Next:** [5.1 Local Development Workflow →](DEVELOPMENT.md)
