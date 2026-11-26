# Meeting notes in Nextcloud Talk and OpenTalk — STATUS

Step log for [`00_master_plan.md`](./00_master_plan.md). A walk is an entry
here, not a chat message. Numbers come from commands, with the command next
to them.

| Step | State | PR | Notes |
| ---- | ----- | -- | ----- |
| MN0 | local OpenTalk answered U2–U5 and U7 on 2026-11-26 | — | No `spike/` directory. U1, U6, U8–U15 and §0 #4, #7, #8, #11 stay open |
| MN1 | in the OpenTalk branch | — | Sessions carry `source` and `keep`. Jitsi rows have neither and still store |
| MN2 | in the OpenTalk branch | — | A window that starts with `RIFF` is posted as WAV |
| MN3 | not started | — | |
| MN4 | in the OpenTalk branch | — | Mode `opentalk`. The join is tested with a fake socket, not a recorded fixture |
| MN5 | walked locally 2026-11-26, J-MN-1 | — | Subtitles only. Not walked in the lab and not with three people |
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

## Walked on 2026-11-26

Local OpenTalk is the official testing environment at
`/wwwroot/opentalk-testing-environment` (controller v0.35.3, room server
v0.10.3, web frontend v2.10.2), not `opentalk-compose`: that one wants a
public domain (U4). The controller listens on 18000 because Synaplan holds
8000. Alice (`alice` / `alice`) started an ad-hoc meeting, opened More
options → Enable subtitles, and saved German.

| What | Result | How |
| ---- | ------ | --- |
| Join | The service left the lobby (`can_enter: true`) and LiveKit marked the hidden participant active | transcriber log `in the meeting`; `docker logs` of livekit `participant active` |
| Audio | 16 kHz mono `Int16Array` frames, level about 600–1700 while speech played | transcriber log `hearing` |
| Subtitle | The room showed `Alice Adams` and `Das ist ein Tissot.` Groq had returned `Guten Tag.`, `ein Tesser unter`, `Titel Guten Tag`, then that line. From the pause cut to the text was about 0.4 s; the cut itself waits for about 0.9 s of audio | page text; transcriber log timestamps `14:18:53.802` window, `14:18:54.155` text |
| Stop | `subtitles stopped (stopped) after 9 lines.` The session row is `nothing_to_save`, `keep` false, `files` empty | transcriber log; `plugin_data` for `synascriber` / `session` |
| Where it has to run | The transcriber must share LiveKit's network. On this Docker Desktop machine that is `--network host` inside the Docker VM. A process on the WSL host completes signaling and then times out on UDP (`wait_pc_connection`) | livekit ICE stats |

U2 is yes. U3 is yes for Groq `whisper-large-v3` with a WAV body. U5: the popout event carries a token and no address, so `OPENTALK_LIVEKIT_URL` is used; the credentials event is the fallback and does carry `service_url`. U7: everyone gets "Subtitles are now available", and a person who shows them sees the speaker's name and the line.

## Open questions

`00_master_plan.md` §7, U1–U15. None answered yet.
