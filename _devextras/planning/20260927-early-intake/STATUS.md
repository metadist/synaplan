# Status — Early intake (2026-09-27)

Plan of record: [`00_master_plan.md`](./00_master_plan.md).
Live order: [`../20260925_roadmap.md`](../20260925_roadmap.md) §1 rows 1b–1d
(combined with [#2211](https://github.com/metadist/synaplan/pull/2211)).

## Steps

| Step | State | Notes |
| ---- | ----- | ----- |
| Roadmap order + sprint files | done 2026-09-27 | This folder. No product code. |
| E1 transcription (J-STT-1…3) | in progress | No cloud key uses local whisper.cpp. Speech off, missing binary, and missing model file each end in one sentence and a next step. |
| E1 #2204 quieter model placeholder | planned | Own commit. |
| E1 #2205 host ports | done 2026-09-28 | `SYNAPLAN_TTS_PORT` publishes spoken answers (default 10200, bound to 127.0.0.1). J-EASY-2 walked: `.env` set `SYNAPLAN_FRONTEND_PORT=15173`, `docker compose up -d frontend` published `0.0.0.0:15173`, and `http://127.0.0.1:15173/login` loaded. Frontend restored to 5173. |
| E1 #2206 two-file compose docs | planned | Own commit. Do not shrink the dev compose. |
| E2 Telegram channel | planned | After E1 starts. [#2202](https://github.com/metadist/synaplan/issues/2202). |
| openDesk OD-0 | waiting on E1 | Then [`../20260917-backend-integrations/04_opendesk_audio_transcriber.md`](../20260917-backend-integrations/04_opendesk_audio_transcriber.md). |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-27 | Sound transcription is the next feature sprint (row 1b) beside the UX close-out from [#2211](https://github.com/metadist/synaplan/pull/2211). Telegram is row 1d. #2204, #2205, #2206 are row 1c. openDesk meeting notes (row 2) wait until J-STT-1 is walked. |

## Review log

**2026-09-27:** Placement only. Image-generation bug #2207 stays on
[#2208](https://github.com/metadist/synaplan/pull/2208), not in this sprint.

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
