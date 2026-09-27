# Early intake — master plan

**Status:** Draft 2026-09-27. Tick §0 before the first product PR of
each sprint.
**Order:** [`../20260925_roadmap.md`](../20260925_roadmap.md) §1 rows 1b–1d.
**Class:** E1 transcription and #2204 are `ota-candidate` where the UI
changes; #2205 and #2206 are compose and docs (`no-app-impact` /
backend deploy, not a store binary). E2 is `ota-candidate` for the
channel screen plus `backend-only` for the webhook.

---

## 0. Decision checklist

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 1 | E1 does not add a new speech engine. Local model is whisper.cpp (`WHISPER_DEFAULT_MODEL`, recommend `base`). | Locked | |
| 2 | E1 does not add a new page. Transcription stays in chat and Files. | Locked | |
| 3 | #2204, #2205, #2206 ship as their own commits inside the E1 window. They do not share a PR with Telegram. | Locked | |
| 4 | Telegram is a channel: inbound update → the same pipeline as WhatsApp → Bot API reply. Flag off ⇒ the channel is absent. | Locked | |
| 5 | Telegram does not implement OpenClaw tools. | Locked | |
| 6 | openDesk Jitsi work does not start in E1. It starts after J-STT-1 is walked. | Locked | |

---

## 1. Journeys

| Id | Who | Sprint |
| -- | --- | ------ |
| **J-STT-1** | A person with no cloud key records a voice note in chat, or drops an audio file, and reads the text. The audio is in Files within ten seconds. | E1 |
| **J-STT-2** | Speech is off or the model file is missing. One sentence names the recovery (turn on local speech, or pick a speech model). The chat does not stay on “working”. | E1 |
| **J-STT-3** | On a sovereign install, recording does not send audio to a browser vendor. `WEB_SPEECH_ENABLED=false` keeps the server path. | E1 |
| **J-EASY-1** | Model settings: the empty “-- Select Model --” row is quieter than a real model, in light and dark, at 320 px. | E1 |
| **J-EASY-2** | A person whose machine already uses port 5173 sets one env value, runs `docker compose up`, and opens the new URL. They do not edit YAML. | E1 |
| **J-EASY-3** | A person who never cloned the repo copies two files, sets `SYNAPLAN_VERSION`, and starts the published image. | E1 |
| **J-TG-1** | A person pastes a bot token, messages the bot, and finds the thread under Incoming chats. | E2 |
| **J-TG-2** | Disconnect on that row stops new replies and says history stays. | E2 |

---

## 2. What we do not rebuild

| Already here | Use it |
| ------------ | ------ |
| Chat record-then-transcribe | `ChatInput.vue` → `chatApi.transcribeAudio` |
| File audio processing | Whisper.cpp via the file pipeline |
| OpenAI-compatible STT | `POST /v1/audio/transcriptions` and sessions (`docs/OPENAI_COMPATIBLE_API.md`) |
| Catalog row | `service: Whisper`, tag `sound2text` |
| WhatsApp channel shape | `WhatsappModule`, `WhatsAppService`, Incoming chats |
| Two-file self-host | `deploy/compose.yaml`, `deploy/selfhost.env.example` |
| Telegram pilot notes | [`2026-archive/20260822-open-plugin-platform/README.md`](../2026-archive/20260822-open-plugin-platform/README.md) |
