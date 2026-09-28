# Status — Early intake (2026-09-27)

Plan of record: [`00_master_plan.md`](./00_master_plan.md).
Live order: [`../20260925_roadmap.md`](../20260925_roadmap.md) §1 rows 1b–1d
(combined with [#2211](https://github.com/metadist/synaplan/pull/2211)).

## Steps

| Step | State | Notes |
| ---- | ----- | ----- |
| Roadmap order + sprint files | done 2026-09-27 | This folder. No product code. |
| E1 transcription (J-STT-1…3) | in progress | No cloud key uses local whisper.cpp. Speech off, missing binary, and missing model file each end in one sentence and a next step. |
| E1 #2204 quieter model placeholder | done 2026-09-28 | Closed trigger uses `txt-model-placeholder` when no model is set. J-EASY-1 walked as admin@synaplan.com: light V2 contrast 5.29:1, dark V2 5.94:1, row fits at 320 px (238 px wide, no overflow). Selected model name stays the louder line. [#2214](https://github.com/metadist/synaplan/pull/2214), [#2241](https://github.com/metadist/synaplan/pull/2241). |
| E1 #2205 host ports | done 2026-09-28 | `SYNAPLAN_TTS_PORT` publishes spoken answers (default 10200, bound to 127.0.0.1). J-EASY-2 walked: `.env` set `SYNAPLAN_FRONTEND_PORT=15173`, `docker compose up -d frontend` published `0.0.0.0:15173`, and `http://127.0.0.1:15173/login` loaded. Frontend restored to 5173. [#2215](https://github.com/metadist/synaplan/pull/2215), [#2242](https://github.com/metadist/synaplan/pull/2242). |
| E1 #2206 two-file compose docs | done 2026-09-28 | Centrifugo via env plus `secrets-init`. Dev compose unchanged. |
| E2 Telegram channel | planned | After E1 starts. [#2202](https://github.com/metadist/synaplan/issues/2202). |
| openDesk OD-0 | waiting on E1 | Then [`../20260917-backend-integrations/04_opendesk_audio_transcriber.md`](../20260917-backend-integrations/04_opendesk_audio_transcriber.md). |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-27 | Sound transcription is the next feature sprint (row 1b) beside the UX close-out from [#2211](https://github.com/metadist/synaplan/pull/2211). Telegram is row 1d. #2204, #2205, #2206 are row 1c. openDesk meeting notes (row 2) wait until J-STT-1 is walked. |
| 2026-09-28 | #2206 two-file = Centrifugo via env + secrets-init. README download URLs stay on `main` until the release tag that contains this change exists; pinning them is the follow-up after that tag. |

## Review log

**2026-09-27:** Placement only. Image-generation bug #2207 stays on
[#2208](https://github.com/metadist/synaplan/pull/2208), not in this sprint.

**2026-09-28 — J-EASY-1.** Account `admin@synaplan.com`, page `/ai/models`,
design-v2. Cleared Text-to-Speech to "-- Select Model --", then chose
Gemini 2.5 Flash TTS again (the stored default was unchanged after reload,
because an empty choice is omitted from the save). Closed trigger:
`txt-model-placeholder`, italic. Light `#6b6b6b` on the card is 5.29:1;
dark `#8b95a7` is 5.94:1. A selected model stays full ink. At 320 px the
row is 238 px wide and stays inside the screen. Theme set back to light.
Recorded on [#2241](https://github.com/metadist/synaplan/pull/2241).

**2026-09-28 — J-EASY-2.** No project `.env` existed, so port 5173 was the
published frontend. Wrote one line, `SYNAPLAN_FRONTEND_PORT=15173`, and ran
`docker compose up -d frontend` (the rest of the stack was already up).
`docker compose port` reported `0.0.0.0:15173`. Opened
`http://127.0.0.1:15173/login` through a temporary tunnel; the login page
loaded. Removed the line and republished the frontend on 5173. `docker
compose config` with no overrides still publishes 5173, 8000, 3307, 9999,
8082, 1025, 8025, 6333, and `127.0.0.1:10200`. Setting
`SYNAPLAN_TTS_PORT=11200` moves only that host port. The minimal file's
`SYNAPLAN_TTS_URL` follows it; the standard file keeps `http://tts:10200`.
Recorded on [#2242](https://github.com/metadist/synaplan/pull/2242).

**2026-09-28 — J-EASY-3.** Empty directory on the m4 Docker host, only
`compose.yaml` and `.env`, `SYNAPLAN_HTTP_PORT=18000` and the three public
URLs pointed at that port. `docker compose up -d` generated
`data/secrets.env` (mode 600). Centrifugo became healthy with no config
file. `down` and `up` left that file byte-for-byte unchanged. Pulling
`SYNAPLAN_VERSION=5.0.4` succeeded, then the file was set back to `5.0.5`.
A second directory with `APP_SECRET=replace-with-a-stable-random-value`
exited 1, wrote no secrets file, and created no database. The message was:
"Synaplan did not start and created nothing: APP_SECRET still has the
example value." The published `5.0.5` PHP process hit an illegal
instruction during migrations on this Colima VM, so `/setup`, the first
chat, and the realtime badge in the browser were not completed here.
`http://127.0.0.1:18000/setup` did return the app HTML before that.
