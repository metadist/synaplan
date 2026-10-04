# 05 — Speech-to-text quality

How per-speaker Jitsi audio becomes good German (and English, …) text, and
where every knob lives. Numbers marked *start value* are tuned in `MN-8`
against the benchmark in §9; numbers marked *measured* come from the spike.

---

## 1. Targets (acceptance in `MN-8`)

| Metric | Target | How measured |
|--------|--------|--------------|
| Word error rate, German, headset / laptop mic | ≤ 12 % / ≤ 18 % (normalised: case, punctuation, numbers) | §9 set A + C |
| Word error rate, English | ≤ 10 % / ≤ 15 % | §9 set A |
| Caption delay after a speaker pauses (GPU engine) | p50 ≤ 1.5 s, p95 ≤ 3 s | transcriber metric `mn_stt_latency_seconds` + pause detection |
| Lines invented during silence or music | 0 per 10 min of non-speech | §9 set B |
| Speaker attribution | 100 % (one stream per participant) | §9 set C |
| Notes kept during a 60-minute meeting with 4 speakers | 100 % of speech time either in text or in a marked gap | §9 set C |

## 2. What the bridge gives us (measured)

- One Opus stream **per participant**, tagged `<endpointId>-<ssrc>`, never a
  mix. Crosstalk does not blur speakers; two people talking at once are two
  clean streams.
- 48 kHz Opus, mono content in a 2-channel declaration, in-band FEC, browser
  DTX: ~38 packets/s while speaking, nothing during silence, ~30 kbit/s.
- The browser already applied echo cancellation, noise suppression and AGC
  (Jitsi defaults; optional RNNoise "noise suppression" per participant).
- `media.timestamp` is the RTP clock (48 kHz units): gaps in it are silence.

Consequence: quality is limited by the model and by how we cut windows, not
by the transport.

## 3. Pipeline in the transcriber (plugin mode)

```text
 bridge WS ─▶ demux by tag ─▶ per speaker:
   Opus packets ─┬─▶ keep original packets (for upload, no re-encode)
                 └─▶ WASM Opus decode ─▶ 48→16 kHz ─▶ voice detection (VAD)
                                                     │
                     segmenter (pause / soft max / hard max rules, §4)
                                                     │
                     window = original packets of [t0 − pre-roll, t1] → Ogg/Opus mux
                                                     │
                     per-meeting queue (FIFO per speaker, ≤ N in flight)
                                                     │
            POST plugin /public/transcriber/sessions/{ref}/audio  (prompt, times, cut reason)
                                                     │
                     result → caption to bridge (if on) · context for the next prompt
```

- **Decoder:** WebAssembly Opus (no native addon, same choice Jitsi's own
  reference proxy defaults to). Decoding is only for VAD and levels; the
  upload re-muxes the **original** packets, so the model gets exactly what
  the bridge carried and the upload is ~30 kB per 10 s.
- **Resampling:** 48 kHz → 16 kHz with a short low-pass FIR (factor 3).
- **Memory:** ≤ 30 s of packets per speaker; dropped after upload or after
  a hard failure. Nothing on disk.

## 4. Windowing

Variable, speech-driven windows. Fixed 8 s windows (today's sidecar) cut
words and send silence, which costs accuracy and invents text.

| Rule | fast | **balanced** (default) | accurate |
|------|------|------------------------|----------|
| Speech start: VAD positive for | 3 frames (96 ms) | 3 frames | 4 frames |
| End of segment: silence for | 400 ms | 600 ms | 800 ms |
| Pre-roll kept before speech start | 200 ms | 300 ms | 300 ms |
| Minimum window (shorter ones wait ≤ 2 s to merge with the next) | 1.0 s | 1.5 s | 2.0 s |
| Soft maximum (then cut at the next pause ≥ 250 ms) | 8 s | 15 s | 20 s |
| Hard maximum (cut at the energy minimum of the last 2 s) | 12 s | 25 s | 28 s |
| Overlap carried into the next window on a hard cut | 0.3 s | 0.5 s | 0.8 s |
| Prompt context | last 120 chars of this speaker | last 200 chars + glossary | last 300 chars + glossary |

*Start values.* The hard maximum stays under Whisper's 30 s window. The
profile is an admin setting (`profile`), sent to the transcriber in the
session binding, so changing it needs no redeploy.

**Voice detection (VAD), staged:**

1. **DTX gaps** — RTP timestamp jumps > 60 ms or Opus DTX/comfort-noise
   packets (TOC + size ≤ 3 bytes) count as silence. Free and exact for
   browsers that use DTX.
2. **Energy** on decoded 16 kHz frames (32 ms): adaptive noise floor
   (10th percentile of the last 10 s) + 9 dB = speech. Catches open
   microphones without DTX.
3. **Silero VAD v5** (`onnxruntime-node`, model shipped in the image, no
   download at runtime) — v1.1 or earlier if `MN-8` shows noisy rooms fail
   with 1 + 2.

## 5. Filters after recognition

Applied in the plugin (`SegmentFilter`), with `response_format=verbose_json`
so per-segment scores are available. A dropped window still advances time;
it is counted, never silently lost.

| Rule | Drop when | Flag |
|------|-----------|------|
| No speech | `no_speech_prob > 0.6` **and** `avg_logprob < −1.0` (Whisper defaults) | `no_speech` |
| Repetition | `compression_ratio > 2.4`, or the same 3-gram ≥ 4 times | `hallucination` |
| Phantom phrases | Text equals (after normalising) a known filler on silence, e.g. de "Untertitel im Auftrag des ZDF", "Untertitelung des ZDF, 2020", "Vielen Dank fürs Zuschauen", "Bis zum nächsten Mal"; en "Thank you for watching", "Subtitles by the Amara.org community" — list in the plugin, per language, extensible by the admin (v1.1) | `hallucination` |
| Too short | < 2 characters after trimming | `no_speech` |
| Echo duplicate | Same normalised text from another endpoint within ±1.5 s (two people in one room with speakers) ⇒ keep the longer audio window | `duplicate` |
| Overlap duplicate | First words of a window equal the last words of the previous window of the same speaker after a hard cut ⇒ trim | — |

Engines without these scores (some cloud APIs) skip the score rules; the
phrase and duplicate rules still apply.

## 6. Engines

| Engine | Quality (de) | Speed | Sovereign | How Synaplan reaches it | Use |
|--------|--------------|-------|-----------|-------------------------|-----|
| **Whisper large-v3-turbo on GPU** (whisper.cpp `whisper-server`, CUDA) | FLEURS de dev: **5.88 % WER** (*measured*) | **0.17 s per 12.5 s utterance, ~72× real time** on an RTX PRO 6000 Blackwell (*measured*) | yes (own GPU) | **New**: server mode of Synaplan's own `WhisperProvider` (`MN-8a`, §6.2) | **Production default (D7)** |
| Whisper large-v3-turbo on GPU via faster-whisper (CTranslate2, batched; e.g. speaches) | high | similar per window, better under many parallel meetings | yes | same server-mode contract (OpenAI-compatible path) | Benchmark alternative in `MN-8c` |
| **German fine-tune** `primeline/whisper-large-v3-turbo-german` (Apache-2.0, 809M, same size and speed as turbo) | model card: 2.6 % vs 3.6 % on its own mix; **FLEURS de dev (not in its training data): 5.36 % vs 5.88 % stock** (*measured*, 363 utterances) — fewer dropped clauses, more spelling variants ("W-Lan") | as turbo (*measured*) | yes | same server; Transformers format only ⇒ convert once to ggml (whisper.cpp) or CTranslate2 (faster-whisper) | **Benchmark against stock turbo in `MN-8c`**; default for German meetings if it wins on set C |
| Whisper large-v3 on GPU | highest of the Whisper family | ~2× slower than turbo | yes | same | "accurate" option if GPU headroom allows |
| whisper.cpp CLI in the Synaplan image (CPU, today) | `small`: fair; `large-v3-turbo` q5: high | process + model load per call; `large-v3-turbo` ≈ 0.6–1× real time on 8 vCPU (*estimate*) | yes | exists (`WhisperProvider`) | Voice messages and uploads; development only for meetings |
| whisper.cpp `whisper-server` on CPU (in-cluster pod) | as the model | model stays loaded; CPU speed | yes | server mode (`MN-8a`) | Development and small installs without GPU |
| NVIDIA Parakeet TDT 0.6B v3 | high on benchmarks (German), punctuation | very fast | yes | different server API (NeMo/Riva) ⇒ later provider | candidate for v1.x throughput |
| Voxtral Mini Realtime (vLLM) | good | true streaming (interim words) | yes | vLLM realtime API ⇒ later | word-by-word captions (later) |
| Cloud (Groq whisper-large-v3-turbo, OpenAI, Mistral) | high | fast | **no** | exists | Only with `allow_cloud_stt`; useful as a **reference** in the benchmark |

### 6.1 What Synaplan's Whisper integration is today (2026-10-04)

`WhisperProvider` → `WhisperService` runs the **whisper.cpp CLI**
(`/usr/local/bin/whisper`, built **CPU-only**, pinned **v1.7.4**) as a
process per request inside the PHP pod: ffmpeg to 16 kHz WAV, then
`whisper -m ggml-<model>.bin -f … --output-txt --no-timestamps -l <lang|auto> -t $(nproc)`.
Models are `ggml-<name>.bin` files in `var/whisper` (Compose downloads
`tiny`; air-gapped openDesk editions pull a model OCI artifact with `oras` into a PVC).

| Good | Not good enough for meetings |
|------|------------------------------|
| Sovereign, no key, no extra service | Model loaded from disk on **every** call (large models: seconds) |
| Same model row everywhere ("Whisper (local)") | CPU only; the PHP image cannot use a GPU and should not carry CUDA |
| Air-gap friendly model artifacts (OCI + `oras`) | Text only: no segments, times or scores ⇒ the filters of §5 cannot work |
| Fine for voice messages, uploads, dictation | `prompt` is ignored; `-t $(nproc)` counts the node's cores, not the pod's ⇒ oversubscription under load |
| | Runs in the web/worker pods and scales with them; 1.7.4 has no built-in VAD (upstream is at 1.9.4) |

### 6.2 Decision: one Whisper story, two run modes (revised D7)

Keep Whisper as **Synaplan's own** speech engine and add a **server
mode**, instead of a separate generic provider:

- **`MN-8a` (core):** `WhisperService` gets `WHISPER_SERVER_URL`. When set,
  it posts the audio to a **whisper.cpp `whisper-server`** (model stays
  loaded) with `language`, `prompt`, `temperature=0`,
  `response_format=verbose_json`, and maps `segments[]`
  (`avg_logprob`, `no_speech_prob`) for §5. When empty, the CLI path stays
  as today. Same model row ("Whisper (local)"), same catalogue, same
  sovereignty flag; every Synaplan surface (chat microphone, uploads,
  `/v1/audio/transcriptions`, meeting notes) uses the GPU automatically.
  Also fix the CLI path: pass `--prompt`, request JSON output (`-oj`) for
  segments, take threads from the cgroup CPU quota, bump the base image to
  whisper.cpp 1.9.x.
- **Server runs (same container, three places):**
  1. development: CPU `whisper-server` pod in the cluster;
  2. **our GPU host** (Docker, CUDA image `ghcr.io/ggml-org/whisper.cpp:main-cuda`),
     reached over WireGuard or TLS + token;
  3. **openDesk Kubernetes with GPU nodes:** a `stt` sub-deployment in
     `synaplan-charts`, built like the existing `tts` one —
     `nvidia.com/gpu: 1`, GPU `nodeSelector`/tolerations, the model as an
     OCI artifact pulled by `oras` (the air-gap pattern), Service
     `synaplan-stt:8080`, `WHISPER_SERVER_URL` set by the chart when
     `stt.enabled`. The cluster operator provides the GPU node pool and
     the NVIDIA device plugin / GPU operator.
- **Models:** ggml files, one format for CPU and GPU. German: convert
  `primeline/whisper-large-v3-turbo-german` once with whisper.cpp's
  `convert-h5-to-ggml.py`, quantize (`q8_0` for GPU, `q5_0` for CPU), ship
  as `whisper-ggml-large-v3-turbo-german:<rev>`. VAD model
  `ggml-silero-v5.x` next to it.
- **Throughput (measured 2026-10-04, see `STATUS.md`):** `whisper-server`
  serialises inference (one global lock per process), but on the GPU host
  (RTX PRO 6000 Blackwell) one process transcribed 75.8 min of German in
  62 s — **~72× real time**, 0.17 s median per 12.5 s utterance including
  HTTP and ffmpeg, 2.5 GB VRAM. One process therefore carries roughly 50+
  meetings with one active speaker each before queueing; beyond that, more
  replicas (GPU time-slicing or MIG) or **faster-whisper** (CTranslate2,
  batched; e.g. speaches) behind the same server-mode contract. `MN-8c`
  still measures concurrency on set C.
- `verbose_json` from `whisper-server` 1.9.4 carries per segment `start`,
  `end`, `avg_logprob`, `no_speech_prob`, `temperature`, `tokens` and
  `words` — enough for the §5 filters (no `compression_ratio`; use the
  repetition rule instead).

**GPU placement (ops, private):** a dedicated GPU (or a fixed share of
one) for speech, separate from the chat models, reached over an encrypted
link (WireGuard or mTLS). Since 2026-10-04 a German `whisper-server` runs
on our GPU host next to the chat models (2.5 GB VRAM), for development with
test audio only; production use waits for the encrypted link (`MN-8b`).

## 7. Language

- One **meeting language** per session (dialog), passed as `language` to
  every window. Fixed language beats detection on short windows (fewer
  wrong-language lines, no English filler on German silence).
- `auto` is offered only if the admin allows it; then every window is
  detected and Synaplan's existing retry for an unexpected detected
  language (#2323) applies.
- Mixed-language meetings: v1 keeps the meeting language; per-speaker
  language is later.
- The caption `language` field equals the meeting language (two letters),
  so Jitsi shows captions to viewers whose subtitle language matches
  ([04 §6](./04_jitsi_and_opendesk.md#6-jitsi-configjs)).

## 8. Live captions

- **v1.0:** one final caption per window, labelled with the speaker's name,
  sent the moment the window's text returns (GPU: ~1–3 s after the speaker
  pauses; long monologues every ≤ 15 s by the soft maximum).
- Captions and transcript are the same text: what people saw is what is
  saved.
- **Later:** interim (word-by-word) captions need a streaming engine
  (Voxtral Realtime, or a local-agreement strategy on Whisper). The
  transcriber's caption sender already supports `is_interim` and
  `stability`; only the engine side is missing.

## 9. Benchmark and acceptance

Harness in the plugin repo, `tests/quality/`. Runs on demand against a
chosen Synaplan + engine; results go to `STATUS.md`.

| Set | Content | Purpose |
|-----|---------|---------|
| A | Public German and English read speech with references (e.g. Common Voice, VoxPopuli; licences checked in `MN-8`), 30 min each | Model WER baseline |
| B | 10 min of silence, keyboard noise, music, coughing | Invented lines must be 0 |
| C | Synthetic meetings: 2–4 Playwright participants with fake microphones (one WAV per person from set A), joined through the **real** Jitsi bridge and transcriber | End-to-end WER, delay, attribution, gaps |
| D (with consent only) | Recorded internal German meetings, headset and room mic | Real-world check; audio deleted after scoring |

Report per engine × profile: WER (normalised), delay p50/p95, real-time
factor, GPU memory, dropped windows by reason. The `MN-8` exit is the §1
table met for the chosen engine with the `balanced` profile.

## 10. Where each setting lives

| Setting | Place | Who edits | Why there |
|---------|-------|-----------|-----------|
| Button on/off, who may start, offered languages, default language, default folder, captions on/off, notes quality profile, notice line | **Plugin admin page** (BCONFIG `P_meeting_notes`) | Synaplan admin | Product decisions; no redeploy |
| Which speech model the notes use | **Plugin admin page** (picker over Synaplan's SOUND2TEXT models; cloud only if allowed) | Synaplan admin | Same model catalogue as the rest of Synaplan |
| Engine endpoint, key, model id, sovereignty flag | **Synaplan AI models / providers** (core) | Synaplan admin / operator | One place for every model Synaplan uses |
| Window and VAD numbers per profile | **Transcriber** defaults in code, overridable by env `MN_PROFILE_<PROFILE>_<RULE>` | Operator | Technical; rarely changed; profile chosen per session |
| Max sessions at once, max windows in flight per meeting | Plugin admin (sessions) + transcriber env (in flight) | Admin / operator | Protects the GPU |
| Glossary (names, terms) | Automatic: participant names. v1.1: admin list on the plugin page | Admin | Better names and terms in prompts |
| Captions language behaviour in the client | **openDesk Jitsi `config.js`** | openDesk admin | Jitsi client setting |
| Secrets (Prosody, transcriber token) | Created on the plugin page, stored encrypted; pasted into openDesk / Helm secrets | Synaplan admin, openDesk admin | Shown once, rotatable |
