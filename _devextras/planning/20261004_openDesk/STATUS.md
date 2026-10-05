# Status — openDesk meeting notes plugin

Plan of record: [`00_master_plan.md`](./00_master_plan.md). Steps:
[`07_sprints.md`](./07_sprints.md). A walk or a measurement is a dated line
here, not a chat message.

## Steps

| Step | State | Notes |
|------|-------|-------|
| `MN-0` Spike and decisions | in progress | Bridge path verified 2026-10-04 (below). Remaining boxes in 07. |
| `MN-1` Core prerequisites | not started | |
| `MN-2` Plugin skeleton | not started | |
| `MN-3` Sessions | not started | |
| `MN-4` Prosody module | not started | |
| `MN-5` Transcriber plugin mode | not started | |
| `MN-6` Transcript file | not started | |
| `MN-7` Jitsi surface | not started | |
| `MN-8` Engine and quality | not started | |
| `MN-9` v1.0 release | not started | |
| `MN-10` v1.1 | not started | |
| `MN-11` v1.2 | not started | |
| `MN-12` openDesk packaging | not started | |

## Decisions

| Date | Decision |
|------|----------|
| 2026-10-04 | Plan written. D1–D12 proposed in [`README.md`](./README.md) §4, waiting for the product owner. |

## Log

**2026-10-04 — Research.** Read `origin/main` `b0390620c` (plugin host,
auth, speech-to-text, files, #2252), `synaplan-charts` `origin/main`,
Synaform 4.4.3, the openDesk edition used on the dev cluster, Jitsi's
bridge-transcription handbook page and the July 2026 Jitsi blog post.
Findings in [`01_findings.md`](./01_findings.md).

**2026-10-04 — Spike MN-0.1: bridge transcription on openDesk's Jitsi
`stable-11031`.** Dev cluster, reversible. A WebSocket logger in the Jitsi
namespace; `custom-jicofo.conf` with `url-template` and an `Authorization`
header; a test Prosody module loaded at runtime that set
`asyncTranscription`, `transcription.urlParams` and (for one room)
`recording.isTranscribingEnabled`; Playwright Chromium as `demo1` with a
fake microphone. Results:

- The bridge connected with `sessionId=<meeting uuid>&sendBack=true&notes=…&lang=de`
  and the header, ~1.5 s after join.
- Per speaker: `start` (tag `<endpointId>-<ssrc>`, Opus 48 kHz,
  `customParameters.endpointId`, no name), `media` (~38 packets/s,
  ~30 kbit/s, RTP timestamps), `ping` every 10 s, `session-end` + close 1001
  on stop or when the last person left.
- A moderator's `setMetadata('recording', {isTranscribingEnabled})` started
  and stopped it.
- 20 test results accepted by the bridge (0 parse failures), broadcast, and
  received by the page as `non_participant_message_received` from
  `transcriber`; **not displayed** because the viewer's subtitle language
  was `en-US` and the results were `de` (open box in `MN-0`).
- `demo1` was a moderator; Jitsi showed `isTranscribing: true`.

Reverted the same day: logger removed, `custom-jicofo.conf` deleted and
Jicofo restarted, Prosody module unloaded and deleted. Spike scripts are in
the private `vultr-cluster` repo.

**2026-10-04 — MN-0 engine round 0 (GPU part).** Our GPU host (NVIDIA RTX
PRO 6000 Blackwell, 96 GB, shared with chat models that were idle during the
runs). whisper.cpp
`ghcr.io/ggml-org/whisper.cpp:main-cuda` (1.9.4, CUDA 13, runs on compute
12.0 through PTX), `whisper-server` with `-l de --convert`, one model at a
time. GPU access through NVIDIA Container Toolkit 1.20.1 + CDI, no Docker
restart. Test set: FLEURS de_de dev (CC-BY-4.0), 363 read utterances,
75.8 min, references normalised to lowercase without punctuation.

| Model | WER | Median / p95 per utterance | Speed | VRAM |
|-------|-----|----------------------------|-------|------|
| `ggml-large-v3-turbo` (stock) | 5.88 % | 0.168 / 0.226 s | ~72× real time | 2.5 GB |
| `primeline/whisper-large-v3-turbo-german` → ggml f16 | **5.36 %** | 0.166 / 0.216 s | ~73× real time | 2.5 GB |

Notes: on the first 150 utterances the gap was larger (7.47 vs 4.94 %)
because stock turbo dropped whole clauses there; over all 363 both models
badly damage 8 utterances (≥ 30 % of words wrong or missing), and per
utterance the German model is better on 26 and worse on 29 of the first
150 (spelling variants such as "W-Lan"). FLEURS is not in primeline's
training mix (Common Voice 17, MLS). Conversion needed a bf16 → f32 cast in
whisper.cpp's `convert-h5-to-ggml.py`. Read speech only: meeting audio
(set C) decides. CPU part of round 0 still open.
