# Status — Backend integrations (Wave 6)

Plan of record: [`00_master_plan.md`](./00_master_plan.md).
Flagship: [`04_opendesk_audio_transcriber.md`](./04_opendesk_audio_transcriber.md).

## Steps

| Step | State | Notes |
| ---- | ----- | ----- |
| Master plan + catalog written | done 2026-09-17 | This folder |
| Decision checklist (§0) | open | Tick before any product PR |
| BI1 — Integrations page + Connect form | planned | synaplan `ota-candidate` |
| BI2 — Catalog docs + snippets | planned | synaplan-docs |
| BI3 — VS Code / Cursor client | planned | new repo |
| BI4 — Neovim client | planned | new repo |
| BI5 — openDesk Jitsi transcriber (OD-A) | planned | new repo + Synaplan STT |
| BI6 — Element voice messages (OD-B) | planned | after OD-A |
| BI7 — Element Call captions (OD-C) | planned | after OD-B |
| BI8 — Word / Excel spike | planned | Synaoffice; see `03_office_and_mail.md` |

## Decisions

| Date | Decision |
| ---- | -------- |
| 2026-09-17 | Track created. openDesk chat = Element; meetings = Jitsi. v1 STT = Jitsi meetings. Nextcloud Talk is not the openDesk path. |

## Review log

**2026-09-17 (first pass):** catalog and transcriber plan drafted against
verified Synaplan APIs (`/v1/messages`, `/v1/chat/completions`,
`/v1/audio/transcriptions` + sessions) and official openDesk docs.
No code in this change.
