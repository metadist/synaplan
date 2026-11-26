# Meeting notes in Nextcloud Talk and OpenTalk — STATUS

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk is an entry
here, not a chat message. Numbers come from commands, with the command next
to them.

| Step | State | PR | Notes |
| ---- | ----- | -- | ----- |
| MN0 | plan written 2026-11-26 | — | §0 #1–#12 open. Spike not started |
| MN1 | not started | — | |
| MN2 | not started | — | |
| MN3 | not started | — | |
| MN4 | not started | — | |
| MN5 | not started | — | |
| MN6 | not started | — | waits for §0 #7, #8 and U6 |
| MN7 | not started | — | |
| MN8 | not started | — | |
| MN9 | not started | — | |
| MN10 | not started | — | |
| MN11 | not started | — | |
| MN12 | not started | — | |

## Checked on 2026-11-26

No product code was changed. These are the measurements the plan rests on.

| What | Result | How |
| ---- | ------ | --- |
| Transcriber tests | 43 pass, 0 fail | `node --test` in `sidecars/synaplan-transcriber` (Node 24) |
| Plugin tests | 50 test methods in seven files | `rg -c "function test" backend/tests/Plugin/SynaScriber/*.php` |
| `SessionService.php` | 712 lines | `wc -l` |
| Jitsi notes test on synaplan-charts `main` | failed on 2026-11-24, run 45371985793: `notes.spec.ts:85`, no caption within 120 s, on the first attempt and on the retry. The transcriber logged "bridge connected" four times for the first session, two "finished after 0 windows", and "finished after 2 windows from 1 speakers" three and a half minutes after the start. The pull request run before it (2026-11-24) and the next one (2026-11-25) passed. Cause not established (U14) | `gh run view --job 138368285211 --log` |
| OpenTalk's contract | read from the published crates named in `01_research.md` S10; not run | — |
| Talk's internal client | read from the signaling API description and from Nextcloud's own app (S3, S5); not run | — |
| `werift` | 0.28.0, MIT | `https://registry.npmjs.org/werift/latest` |
| `@livekit/rtc-node` | 1.2.3, Apache-2.0 | `https://registry.npmjs.org/@livekit/rtc-node/latest` |

## Open questions

`00_master_plan.md` §7, U1–U15. None answered yet.
