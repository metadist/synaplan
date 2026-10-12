# Live voice conversations — master plan

**Status:** Draft 2026-10-08. §0 agreed 2026-10-12. LV0 numbers are in
`STATUS.md` (2026-10-12): both launch providers answer, Synaplan brain ~2.8 s,
spoken reply starts about 4.2–4.5 s after the user stops. Still open from LV0:
WebRTC, relay overhead, iPhone echo. LV1 (catalog, pricing, session table,
metering, activity duration) and LV2 (voice sidecar, tickets, callbacks,
delegation, transcript, reaper) are in the working tree. LV3 (button, consent
sheet, panel, settings, five locales) is in the working tree. LV4 (budget
warning, wrap-up, activity row, admin live list) is in the working tree.
LV5 (Realtime, Realtime mini, Gemini extended thinking, Grok Voice) is in
the working tree. LV6 (optional OpenAI WebRTC, admin switch default off) is
in the working tree. LV7 (native `liveVoice` capability, microphone purpose
string, store notes) is in the apps working tree. The button stays hidden on
older binaries. A native audio plugin is not included: loudspeaker echo in
WKWebView is still unmeasured. LV8 (CarPlay live relay, 20-second resume,
spoken budget end, spoken fallback to the turn-based loop) is in the working
tree of both repositories. J-LV-6 and J-LV-7 were not walked on a device.
A head-unit echo and barge-in pass is still open. Next product step is those
walks, then LV9.

**Goal:** A person presses one button next to the microphone and talks with
Synaplan like with ChatGPT Voice or Gemini Live: full duplex, interruptible,
no typing. The answer still comes from *their* Synaplan chat (chat model,
memories, knowledge, tools) whenever the provider allows it. The same
conversation runs in the web app, the mobile apps and CarPlay. Every second
is metered and its cost lands in the user's **one** monthly usage budget,
next to chat, images, video and everything else — no separate voice quota,
no separate bill. A budget that runs out mid-sentence ends the conversation
politely instead of cutting it off.
**Binding UX:** [`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md)
(U1–U12) and the Perfect-UX bar in `AGENTS.md`.
**Class:** LV1, LV2, LV5, LV9 `backend-only` (plus a new sidecar,
`no-app-impact`); LV3, LV4, LV6 `ota-candidate`; LV7, LV8
`store-required` in `synaplan-apps`. LV0 is a spike with no product code.

Files in this track (create the others when LV0 starts):

| File | Role |
| ---- | ---- |
| `00_master_plan.md` | Why, decisions, research, architecture, limits, billing, UX, step table |
| `01_sprints.md` | Per-step brief (files, exit criteria, class) — written from §11 |
| `STATUS.md` | Step log + measured numbers (latency, cost per minute, AEC results) |

---

## Start here (60 seconds)

**What exists today.** Synaplan has *turn-based* voice only: dictation into
the composer (Web Speech or Whisper via `purpose=dictation`), a "voice reply"
toggle that reads the answer aloud (`voiceReply=1` → TTS → SSE `audio`), and
in `synaplan-apps` a CarPlay loop of on-device `SpeechTranscriber` → chat
SSE → TTS. Nothing streams audio to a model yet. LV1 adds the catalog rows,
the `per_second` / `per_audio_token` prices, the `BVOICESESSIONS` table and
the metering service. The socket path is LV2.

**What we build.**

1. A **voice gateway** sidecar (`sidecars/synaplan-voice`, Node 22 + `ws`,
   the same shape as `synaplan-transcriber`) that holds the long-lived
   provider connection. PHP never holds an audio socket.
2. A **provider port** for speech-to-speech models. Launch adapters:
   OpenAI GPT-Live and Gemini Live. Later: OpenAI Realtime 2.1, xAI Grok
   Voice, and an OpenAI-Realtime-compatible self-hosted endpoint.
3. **Delegation**: the voice model handles the conversation; real answers
   always come from Synaplan's normal chat pipeline (`MessageProcessor`),
   i.e. the user's chat model with memories, knowledge and tools.

4. **Metering**: one `BUSELOG` row per conversation, updated every tick,
   carrying the voice cost and the cost of Synaplan's answers. It feeds the
   existing cost budget (`SUM(BCOST)` + markup), which is checked *during*
   the session. No minutes quota.
5. A **conversation button** beside the microphone, a live panel in the
   composer, transcripts as normal chat messages, usage on `/statistics`.
6. **Mobile + CarPlay**: the native shells connect to the same gateway over
   WebSocket with their own echo-cancelled audio graph.

**Where things live:**

| You want to change… | Go to… |
| ------------------- | ------ |
| Count quotas, cost budget | `backend/src/Service/RateLimitService.php` (`checkLimit`, `checkCostBudget`, `recordUsage`) |
| Duration metering precedent | `backend/src/Service/Usage/TranscriptionUsageRecorder.php` |
| Price math (`permin`, `per_second`) | `backend/src/Service/CostCalculationService.php` (`normaliseToPerUnit`) |
| Model rows, capability tags | `backend/src/Model/ModelCatalog.php` (`MODELS`, `CAPABILITY_TAGS`), `backend/src/Service/Model/CapabilityCatalog.php` |
| Live-voice discovery | `ModelDiscoveryIgnoreList::CLASS_RULES` — the OpenAI `realtime` rule was removed in LV1 |
| Default models per capability | `backend/src/Seed/DefaultModelConfigSeeder.php`, `ModelConfigService::getDefaultModel()` |
| Budget per level | `BSUBSCRIPTIONS.BCOST_BUDGET_MONTHLY` (seed: `backend/src/Seed/SubscriptionPlanSeeder.php`); no new `RATELIMITS_{LEVEL}` keys |
| Optional feature switch | `backend/src/Module/` (`FeatureModuleInterface`, `ModuleGateListener`) |
| Chat pipeline entry | `MessageProcessor::process()` / `processStream()`; `ChatHandler` |
| Short-lived socket token precedent | `backend/src/Realtime/Token/RealtimeTokenService.php` |
| Node audio sidecar precedent | `sidecars/synaplan-transcriber/` (+ compose profile `transcriber`) |
| Composer buttons | `frontend/src/components/ChatInput.vue` (`section-chat-primary-actions`) |
| Limit / paywall UX | `frontend/src/composables/usePaywallPrompt.ts`, `LimitReachedModal`, `SubscriptionPaywallModal` |
| Usage page | `frontend/src/views/StatisticsView.vue` → `UsageStatistics.vue`; `UsageStatsController` |
| Mobile impact classification | `.github/mobile-impact-policy.json`, `tests/mobile-impact.test.mjs` |
| CarPlay voice loop (apps repo) | `synaplan-apps/ios/App/App/CarPlay/VoiceConversationEngine.swift`, `VoiceAudioGraph.swift` |

**Rules for whoever drives this:**

- One step = one PR. LV1 lands before any UI.
- The flag defaults off. Flag off ⇒ no button, no settings row, 404 on the
  new routes (U11).
- Every number in `STATUS.md` comes from a command or a measured session.
- Provider facts below were verified on 2026-10-08. Re-check model IDs,
  prices and limits at LV0 and before every release; they move monthly.

---

## 0. Decision checklist

| # | Decision | Default | Agree? |
| - | -------- | ------- | ------ |
| 1 | The provider key never reaches a client. Browsers and apps get a single-use **gateway ticket**, never a provider token. | Locked | |
| 2 | A Node sidecar (`synaplan-voice`) holds provider sessions. FrankenPHP does not grow a WebSocket server. | Locked | |
| 3 | **Relay first**: every client and every provider goes client ⇄ gateway ⇄ provider. Direct browser WebRTC to OpenAI (server-side SDP exchange + sideband) is LV6, an optimisation behind an admin setting, not the baseline. | Proposed | |
| 4 | The brain is **always Synaplan**: the user's chat model with memories, knowledge and tools. GPT-Live runs with `client` delegation (not `responses`); the other adapters expose one tool, `consult_synaplan`, that runs the chat pipeline. There is **no** "voice model answers directly" mode. | Agreed 2026-10-12 | Yes |
| 5 | Launch models: **`gpt-live-1` and `gemini-3.8-live`** together. Later: `grok-voice-think-fast-2.0`, `gpt-realtime-2.1` / `-mini`, `gemini-3.8-live-extended-thinking`. Self-hosted OpenAI-Realtime-compatible endpoint in LV9. | Agreed 2026-10-12 | Yes |
| 6 | New capability `VOICE_LIVE`, catalog tag `voice2voice`, default key `DEFAULTMODEL.VOICE_LIVE`. Not mixed into `SOUND2TEXT` / `TEXT2SOUND`. | Locked | |
| 7 | **No own quota.** Live voice has no minutes limit and no `RATELIMITS_*` keys. Its cost is one more line in the user's monthly cost budget (`SUM(BCOST)` + markup, `RateLimitService::checkCostBudget`), shared with chat, images, video and every other paid action, checked at start and on every tick. Whatever budget an operator sets per level (including a free level, e.g. $1/month) applies unchanged. A level with no budget behaves exactly like chat today (unlimited). | Agreed 2026-10-12 | Yes |
| 8 | One `BUSELOG` row per conversation (action `VOICE_LIVE`), inserted at start with `BSTATUS=running`, updated on every tick, closed at the end. Its `BCOST` is the voice cost **plus** the cost of every Synaplan answer in that conversation; `BMETADATA` holds the split. The answers get no `MESSAGES` usage row of their own. Pipeline side steps (routing, RAG embeddings, memory extraction) keep recording exactly as for a typed message — they are in the same budget either way. | Agreed 2026-10-12 | Yes |
| 9 | Synaplan's answers inside a conversation cost money (in the budget) but do **not** count against the `MESSAGES` count limit. No extra token counting. | Agreed 2026-10-12 | Yes |
| 10 | When the budget runs out: warn when about 60 s of voice is left in the budget, then the model says one goodbye sentence, then the session closes (≤ 8 s grace). Web: the existing budget path (`COST_BUDGET_EXCEEDED` → top-up / plan). App: IAP paywall only. CarPlay: says one sentence and ends — the turn-based loop would draw on the same budget, so it is no fallback here (it stays the fallback for capacity and provider errors). | Proposed | |
| 11 | One live session per user at a time; per-provider concurrency caps from config (xAI allows only 10 per team). Full ⇒ honest copy + offer turn-based voice. | Locked | |
| 12 | New table `BVOICESESSIONS` for state, provider session id, seconds, end reason. Migration is raw, idempotent SQL (Galera rules). | Agreed 2026-10-12 (migration approved) | Yes |
| 13 | Synaplan stores no audio. Provider storage off where the API allows it (`store:false` on OpenAI, paid tier on Gemini). Transcripts are normal chat messages; incognito persists nothing. OpenAI ZDR and EU data residency (`eu.api.openai.com`) are requested **after** launch, not a launch blocker. | Agreed 2026-10-12 | Yes |
| 14 | Conversation glyph: `mdi:waveform` (Heroicons has no waveform; `SpeakerWaveIcon` is TTS, `MicrophoneIcon` is dictation). Added to the icon map in the same PR. | Proposed | |
| 15 | Widget and guest users get no live voice in v1. | Locked | |
| 16 | The native apps enable live voice only in a new store binary (`store-required`: new audio path to third parties ⇒ privacy labels). Older binaries keep the turn-based loop; the SPA hides the button unless the native capability is present. | Locked | |
| 17 | ~~Minute quotas per level~~ — replaced 2026-10-12 by decision 7. Measured in LV0 what the seeded budgets buy: see `STATUS.md`. | Superseded | Yes |
| 18 | Default max conversation length 30 min, idle end after 2 min of silence on both sides; both admin-configurable. On a self-hosted install without billing (budget gate off) this length and the admin's on/off switch are the only limits. | Agreed 2026-10-12 (self-host part) | |
| 19 | Provider accounts: OpenAI is on usage tier Build or higher (GPT-Live does not run on Free), Gemini runs on the paid tier (the free tier trains on data). Both confirmed ready 2026-10-12. xAI only when its adapter lands. | Agreed 2026-10-12 | Yes |


---

## 1. Journeys

| Id | Who | Step |
| -- | --- | ---- |
| **J-LV-1** | A PRO user opens a chat, presses the waveform button, allows the microphone once, talks, interrupts the answer mid-sentence, presses End. The transcript is in the same chat within ten seconds, the cost is in the usage budget on `/statistics` as one "Live conversation" row in Recent Activity (cost and talk time). | LV3, LV4 |
| **J-LV-2** | First use: one sheet says which company hears the voice (e.g. "Your voice goes to OpenAI while you talk"), that talking uses the same monthly usage budget as chat, and how to stop. One primary button. Declining leaves everything as before. | LV3 |
| **J-LV-3** | The user's budget covers about 40 more seconds. A banner shows the countdown; at zero the assistant says goodbye in one sentence, the panel shows "Conversation ended — your usage budget for this month is used up" with the top-up / plan action. Nothing was lost from the transcript. | LV4 |
| **J-LV-4** | Microphone denied, provider down, network lost, or all live slots busy: one sentence names the recovery and offers the turn-based microphone. The panel never stays on "connecting". | LV3 |
| **J-LV-5** | An admin turns the feature on: sets the gateway env, picks a `VOICE_LIVE` default model, sees the button appear for users. Turning it off removes the button within one runtime-config refresh. | LV2, LV3 |
| **J-LV-6** | In the iOS/Android app (new binary), the same button works with the phone speaker, without headphones, and the answer stops when the user talks over it. | LV7 |
| **J-LV-7** | In CarPlay the driver taps Synaplan, picks a chat, and talks freely. When the usage budget runs out mid-drive, the app says one sentence and ends the conversation (no screen text). When the live service is busy or down, it says one sentence and continues in the normal turn-based mode. | LV8 |

| **J-LV-8** | The user ends a conversation by closing the tab. The server still closes the provider session within 15 s, saves the transcript, and the usage row reaches a terminal state. | LV2 |

Each `ota-candidate` sprint lists the five exit bullets from the UX
contract §6 in `01_sprints.md` before implementation.

---

## 2. What we do not rebuild

| Already here | Use it |
| ------------ | ------ |
| Chat pipeline (model choice, memories, RAG, tools, history, summaries) | `MessageProcessor` / `ChatHandler`, called from the backend for delegation |
| Message storage | `BMESSAGES` IN/OUT + `BMESSAGEMETA`; no new message table |
| Count limits, cost budget, markup, top-ups, paywall logic | `RateLimitService`, `PremiumFeatureGate`, `usePaywallPrompt` |
| Duration pricing | `pricing_mode: per_second` + `inUnit: permin` + `media_usage.duration_seconds` |
| Short-lived signed tokens | `RealtimeTokenService` pattern (HS256, 60 s) |
| Node audio sidecar + compose profile + FeatureModule | `synaplan-transcriber`, `OpendeskSttModule` |
| Voice-mode prompt suffix for short spoken answers | `ChatHandler` VOICE-MODE prompt (used by `voiceReply`) |
| Native echo-cancelled audio graph with barge-in | `synaplan-apps` `VoiceAudioGraph.swift` (voice processing on one `AVAudioEngine`) |
| CarPlay templates, session store, permission prompt | `synaplan-apps` CarPlay scene (turn-based loop stays as fallback) |

---

## 3. Research — speech-to-speech models (verified 2026-10-08)

### 3.1 What "like ChatGPT / Gemini" means

Two architectures exist. **Cascade**: speech → text → LLM → text → speech
(Synaplan today, Claude voice mode, ChatGPT "Standard"). **Native
speech-to-speech**: one model hears audio and speaks audio; transcripts are
a side product (ChatGPT Advanced/Live, Gemini Live, Grok Voice). Only the
native kind hears tone, handles overlap, and reacts mid-sentence. GPT-Live
is additionally **full duplex**: it listens while speaking and decides many
times per second whether to talk, pause or yield.

### 3.2 Providers we integrate

| | OpenAI GPT-Live | OpenAI Realtime 2.1 | Google Gemini Live | xAI Grok Voice |
| - | - | - | - | - |
| Model IDs | `gpt-live-1` (no mini in the API) | `gpt-realtime-2.1`, `gpt-realtime-2.1-mini`, `gpt-realtime-2` | `gemini-3.8-live`, `gemini-3.8-live-extended-thinking` (legacy `gemini-3.1-flash-live-preview`) | `grok-voice-think-fast-2.0` (alias `grok-voice-latest`; pin the version) |
| Endpoint | `POST /v1/live/sessions` (WebRTC SDP) or `wss://api.openai.com/v1/live/sessions` (first message `session.start`); SIP | `wss://api.openai.com/v1/realtime`, WebRTC `POST /v1/realtime/calls`; SIP | WebSocket `BidiGenerateContent` (AI Studio or Vertex regional) | `wss://api.x.ai/v1/realtime?model=…` (OpenAI-Realtime compatible); SIP |
| Duplex | Full duplex, no client VAD | Turn-based, `server_vad` / `semantic_vad` | Turn-based with automatic / manual / hybrid VAD; `interrupted` event | Turn-based, `server_vad` or push-to-talk |
| Client tokens | **None** — server does the SDP exchange; data channel restricted with `allowed_client_events` | `POST /v1/realtime/client_secrets` (`ek_…`, 10–7200 s) | `auth_tokens.create` (`uses`, `expireTime`, `newSessionExpireTime`; v1alpha) | `POST /v1/realtime/client_secrets` (`expires_after`), subprotocol `xai-client-secret.<t>` |
| Audio | WS: PCM16 24 kHz (or 16 kHz), G.711; WebRTC: Opus | PCM16 24 kHz, G.711 | In PCM16 16 kHz, out PCM16 24 kHz | PCM 8–48 kHz (24 kHz default), G.711, Opus 24 kHz |
| Brain / tools | **Delegation**: `responses` (OpenAI model) or `client` (our backend; event `session.delegation.created` without task text, result via `session.commentary.append`, progress via `session.thinking.append`, each ≤ 500 tokens) | Function calling | Function calling, `NON_BLOCKING` default on 3.8 with scheduling `SILENT/WHEN_IDLE/INTERRUPTED`; `send_client_content` anytime | Functions, web/X search, collections, remote MCP |
| Transcripts | `session.input_transcript.delta`, `session.output_transcript.delta` (with `start_ms`/`end_ms`, no turn-complete event) | input transcription + `response.output_audio_transcript.delta` | `input_audio_transcription`, `output_audio_transcription` | `conversation.item.input_audio_transcription.updated` (cumulative) + output transcript |
| Limits | Context 128k, replacement engine at 90 %; initial history ≤ 128 msgs / 8,192 tokens; duration limit undocumented (`expires_at`); concurrency Build 50 / Launch 300 / Grow 500, Free tier blocked | 60 min per session; Build 400 RPM | Audio session 15 min without compression (unlimited with `contextWindowCompression`); connection ~10 min with `GoAway` + resumption handle (2 h) | **10 concurrent sessions per team**, 120 min per session |
| Price | **$0.05/min**, per second; WebRTC reserves 15 s at creation; backend tokens extra | 2.1: audio in $32 / cached $0.40 / out $64 per 1M; text $4 / $0.40 / $24. Mini: audio $10 / $0.30 / $20; text $0.60 / $0.06 / $2.40 | Audio in $3/1M (~$0.005/min), audio out $12/1M (~$0.018/min), text $0.75 / $4.50; ~25 audio tokens/s | **$0.08/min** (session duration with server VAD) + $0.004 per text item; function outputs free |
| Usage events | `session.usage.updated` (`usage.seconds` cumulative, `context_window.usage_ratio`), final in `session.closed` | `response.done.response.usage` (text/audio/cached in + text/audio out) | `usageMetadata` (cumulative, per modality) | none per tick — meter duration in the gateway |
| Data | ZDR-capable (`store:false`), EU residency `eu.api.openai.com` with amendment; recordings otherwise 30 days | ZDR, EU residency (tracing not EU) | Paid tier not used for training; Vertex EU multi-region, CMEK | ZDR / EU unverified — say so in the consent sheet |

Sources: developers.openai.com (`guides/live`, `live-conversations`,
`live-delegation`, `voice-webrtc`, `voice-websockets`, `voice-server-controls`,
`models/gpt-live-1`, `models/gpt-realtime-2.1`, `guides/your-data`),
openai.com/index/introducing-gpt-live-1-in-the-api, ai.google.dev
(`live-api/capabilities`, `session-management`, `ephemeral-tokens`,
`models/gemini-3.8-live`, `pricing`), docs.x.ai
(`audio/speech-to-speech`, `ephemeral-tokens`, `models/speech-to-speech`).

### 3.3 Considered, not in v1

| Option | Why not now | Revisit |
| ------ | ----------- | ------- |
| Azure OpenAI Realtime (Sweden/France Central) | Same protocol as Realtime; 2.x is public preview without SLA; `gpt-live-1` on Azure unverified | LV9 as a base-URL variant of the Realtime adapter |
| Qwen Cloud realtime (`qwen3.8-omni-flash-realtime`) | OpenAI-Realtime-like, WebSocket + WebRTC; no Qwen provider in Synaplan today | LV9 via the compatible adapter |
| AWS Nova 2 Sonic | HTTP/2 bidi + SigV4, 8 min connection, no AWS provider in Synaplan; price only from third parties | After v1 if demanded |
| ElevenLabs Agents, Deepgram Voice Agent, Cartesia, Speechmatics Flow, Hume EVI | Cascades with custom LLM — the same thing our turn-based loop already is, billed per minute on top | Not planned |
| Ultravox (API + open weights) | Own inference stack, no external LLM; 5 concurrent calls PAYG | Not planned |
| Anthropic | **No realtime / voice API** (Transparency Hub: text + image in) | Watch "more to share later this year" |
| Mistral | No speech-to-speech; Voxtral realtime STT + Voxtral TTS is a cascade | Cascade upgrade only |
| Groq | Whisper STT + Orpheus TTS only | — |
| Ollama | Audio input only (`/v1/chat/completions` `input_audio`), no audio output, no realtime | — |

### 3.4 Sovereign / self-hosted

| Option | Fit |
| ------ | --- |
| **Qwen3-Omni-30B-A3B** via vLLM-Omni | Real open-weight speech-to-speech, realtime buffers, ~630 ms first audio at 64 parallel requests. Needs a large GPU. Target of the LV9 "compatible endpoint" adapter. |
| **Kyutai Unmute** | MIT, semantic VAD, any OpenAI-compatible LLM (Ollama works) — but EN/FR only, NVIDIA ≥ 16 GB. Offer as documented recipe, not default. |
| Kyutai Moshi | Full duplex, no tools, no custom LLM. Demo value only. |
| No GPU | Keep the turn-based loop (Whisper + chat + Piper) and give it barge-in in the web too (LV9). |

### 3.5 Car audio (CarPlay)

Apple's WWDC26 guidance for voice apps is play-and-record, default mode,
no mixing; echo cancellation on the phone needs voice processing
(`setVoiceProcessingEnabled`, which implies `voiceChat`). These conflict;
the existing barge-in graph already uses voice processing. Whether a head
unit cancels echo itself varies by manufacturer and is undocumented.
GPT-Live does its own turn handling — no client VAD in front of it. Gate
the microphone ~500–800 ms after playback ends only for turn-based
providers. **Test on a real head unit before shipping LV8.**

---

## 4. Architecture

```
Browser / App / CarPlay
  │ 1. POST /api/v1/voice/live/sessions  (cookie or Bearer)
  ▼
Symfony backend ──────────────── checks flag, cost budget, concurrency,
  │                               creates BVOICESESSIONS + BUSELOG(running),
  │                               builds instructions + chat history,
  │                               returns { ticket, wsUrl, allowedSeconds, … }
  │
  │ 2. wss://<host>/voice/ws?ticket=…   (Caddy → sidecar, same origin)
  ▼
synaplan-voice (Node sidecar) ── verifies ticket (single use, 30 s),
  │                               opens provider session with the server key,
  │                               relays PCM16 both ways, maps provider events,
  │                               ticks usage, enforces allowedSeconds
  │        ▲  internal HTTP (HMAC, compose network only)
  │        └── /internal/voice/sessions/{id}/{usage|delegate|transcript|closed}
  ▼
Provider realtime API (OpenAI Live / Realtime, Gemini Live, xAI, compatible)
```

### 4.1 Components

| Component | Responsibility |
| --------- | -------------- |
| `VoiceLiveController` (`/api/v1/voice/live/*`) | Options, start, end, status, consent. Thin; delegates to services. Full OpenAPI annotations. |
| `VoiceLiveSessionService` | Pre-checks, session row, ticket minting, instruction + history assembly, finalisation. |
| `VoiceLiveUsageService` | Tick handling: provider usage → seconds/tokens → cost; updates `BUSELOG` row; re-checks the cost budget; returns `continue / warn / wrap_up / stop`. |
| `VoiceLiveDelegationService` | Builds an IN `Message` from the transcript segment, runs `MessageProcessor::process()` with voice options, returns spoken-length text + facts; adds the answer's cost to the conversation's `VOICE_LIVE` row (no `MESSAGES` usage row, no `MESSAGES` count check). |
| `VoiceTranscriptWriter` | Turns provider transcript deltas into ordered IN/OUT messages in the chat; marks them `source=live_voice`. |
| `InternalVoiceController` (`/internal/voice/*`) | Gateway callbacks; HMAC + network-restricted firewall; not in the public OpenAPI. |
| `LiveVoiceModule` (FeatureModule `live_voice`) | Configured when `LIVE_VOICE_GATEWAY_URL` + secret are set and at least one `voice2voice` model is active with its provider key. Gates the public routes. |
| `sidecars/synaplan-voice` | Provider adapters, relay, tick loop, wrap-up, reconnect/resumption, reaper for dead clients. |
| Frontend `services/voice/live/` + `LiveVoicePanel.vue` | Mic capture (`getUserMedia` with echo cancellation, noise suppression, AGC), PCM worklets, playback, state machine, UI. |

### 4.2 Provider port (gateway side)

```ts
interface LiveVoiceAdapter {
  open(cfg: SessionConfig): Promise<void>      // model, voice, instructions, history, tools, audio format
  sendAudio(pcm16: Buffer): void
  interrupt(): void                            // client barge-in hint where the provider needs it
  injectContext(kind: 'instruction' | 'fact' | 'say', text: string, delegationId?: string): void
  close(reason: EndReason): Promise<void>
  on(event: 'audio' | 'transcript' | 'delegate' | 'usage' | 'interrupted' | 'error' | 'closed', cb): void
}
```

| Adapter | Delegation mapping | Usage mapping |
| ------- | ------------------ | ------------- |
| `OpenAiLiveAdapter` | `delegation: {type:'client'}`; on `session.delegation.created` → `/delegate`; progress → `session.thinking.append`; answer → `session.commentary.append` (split into ≤ 500-token parts) | `session.usage.updated.usage.seconds` (delta per tick) |
| `OpenAiRealtimeAdapter` (also Azure, compatible endpoints) | function `consult_synaplan(question)` → `/delegate` → `function_call_output` | `response.done.usage` audio/text/cached tokens |
| `GeminiLiveAdapter` | function `consult_synaplan`, `NON_BLOCKING`, scheduling `WHEN_IDLE`; context via `send_client_content` | `usageMetadata` deltas; session resumption on `GoAway`, `contextWindowCompression` on |
| `XaiVoiceAdapter` | function `consult_synaplan` | gateway wall clock of the session (server VAD bills duration) + text items |

There is no "voice model answers directly" mode (decision 4): every
content answer is delegated to Synaplan. The voice model answers on its own
only for conversation control (greeting, "say that again", "slower").

### 4.3 Start payload (instructions and context)

The backend builds, per session: Synaplan system persona + the
`VOICE-MODE` short-answer rules, the UI language as a hint (providers
auto-detect), the chat's summary and last messages trimmed to 8,192 tokens
(GPT-Live's initial-history limit; same budget for all adapters), the
user's display name, and the rule "for facts about the user, their files or
anything needing tools, call Synaplan". No secrets, no full memory dump.

### 4.4 Transcript persistence

- Provider transcripts are segmented into turns by speaker and time
  (`start_ms`/`end_ms` where available; delegation boundaries; ≥ 1.2 s
  silence). Each turn becomes an IN or OUT `BMESSAGES` row in the chat,
  with meta `voice_session_id`, `voice_provider`, `voice_model`,
  `source=live_voice`, and OUT rows also carry `ai_chat_*` of the delegated
  model when one answered.
- Written incrementally (every closed turn), so a crash loses at most the
  turn in progress. Final flush on close.
- The UI labels these turns "Transcript — may differ slightly from what was
  said" (OpenAI documents that live transcripts are not verbatim).
- New chat: the session creates the chat first (`source=web`), titled from
  the first user turn by the existing title job.
- Incognito: nothing persisted, delegation runs with `incognito` options.

### 4.5 Data model (migration, ask first)

`BVOICESESSIONS`: `BID`, `BUSERID`, `BCHATID`, `BMODELID`, `BPROVIDER`,
`BPROVIDERSESSIONID`, `BSTATUS` (`connecting|live|ending|ended`),
`BENDREASON`, `BSTARTED`, `BENDED`, `BSECONDS`, `BUSELOGID`,
`BCLIENT` (`web|ios|android|carplay`), `BTRANSPORT` (`relay|webrtc`).
Raw `CREATE TABLE IF NOT EXISTS`, no Schema API (Galera rule). Cost lives
in the existing `BUSELOG.BCOST`, so no new `BUSELOG` column is needed;
`BSECONDS` is for display and the admin view, not for a limit.

### 4.6 Security

- Ticket: HS256, `sub`, `sid`, `exp` 30 s, single use (Redis `SETNX`),
  bound to the session row and the requesting client type.
- Caddy routes `/voice/ws` to the sidecar; Origin allow-list for browsers;
  per-user and per-IP connection rate limits.
- Internal callbacks: HMAC over body + timestamp, compose network only,
  firewall rejects public access.
- The gateway never logs audio or transcript text; logs carry session id,
  provider, timings and end reason only.
- Provider options: `store:false` where supported; OpenAI EU host via
  `OPENAI_LIVE_REGION=eu`; Gemini via Vertex EU when configured.

---

## 5. Usage limits, billing, and what happens at the limit

### 5.1 Pricing rows (catalog)

| Key | Mode | Price fields |
| --- | ---- | ------------ |
| `openai:gpt-live-1:voice2voice` | `per_second` | `priceIn 0.05`, `inUnit permin` |
| `openai:gpt-realtime-2.1:voice2voice` | `per_audio_token` (new) | `json.audio_price_in_per_1M 32`, `audio_cache_in_per_1M 0.40`, `audio_price_out_per_1M 64`, text 4 / 0.40 / 24 |
| `openai:gpt-realtime-2.1-mini:voice2voice` | `per_audio_token` | audio 10 / 0.30 / 20; text 0.60 / 0.06 / 2.40 |
| `google:gemini-3.8-live:voice2voice` | `per_audio_token` | audio in 3, out 12; text 0.75 / 4.50 |
| `google:gemini-3.8-live-extended-thinking:voice2voice` | `per_audio_token` | verify at LV0 |
| `xai:grok-voice-think-fast-2.0:voice2voice` | `per_second` | `priceIn 0.08`, `inUnit permin`; `json.text_item_price 0.004` |

`per_audio_token` is one new branch in `CostCalculationService` that prices
input and output audio/text/cached tokens separately (shape from OpenAI's
`input_token_details` / `output_token_details` and Gemini's per-modality
`usageMetadata`). Add to `docs/PRICING_MAINTENANCE.md`, the price-drift
job's mode handling, and remove the realtime rule in
`ModelDiscoveryIgnoreList::CLASS_RULES`.

### 5.2 Metering

1. **Start**: `BUSELOG` row inserted (`VOICE_LIVE`, `BSTATUS=running`,
   cost 0, `BMODEL` = voice model, `BMETADATA.voice_session_id`,
   `BMETADATA.source` = client, `BMETADATA.chat_id`). Written through
   `RateLimitService` like every other action, so markup, BYO-key
   `zero_cost` and price snapshot behave the same.
2. **Tick** every 15 s (and on close): gateway posts the provider's
   cumulative usage. Backend computes delta → seconds/tokens → `BCOST`
   (raw provider cost, like every row), updates the row and
   `BMETADATA.media_usage.duration_seconds`, updates `BVOICESESSIONS.BSECONDS`.
   Provider-reported numbers win over wall clock; xAI uses gateway wall
   clock by design.
3. **Close**: final usage from `session.closed` / last `usageMetadata`;
   `BSTATUS=success`; latency field = time to first audio.
4. **Delegation**: the cost of each `consult_synaplan` / GPT-Live
   delegation answer is added to the same `VOICE_LIVE` row
   (`BMETADATA.voice_cost`, `BMETADATA.answer_cost`, `answer_count`); it is
   not a `MESSAGES` row and does not touch the `MESSAGES` count limit.
5. **Crash safety**: a reaper closes sessions without a tick for 45 s
   (gateway gone) and bills up to the last tick. A provider reconnect does
   not open a second row.
6. BYO-key users: metered, `BCOST=0` (`zero_cost`), same as chat.

### 5.3 Gates during the session

The only gate is the user's normal cost budget, the same one chat, images
and video draw on. `allowedSeconds` at start = min(remaining budget ÷
worst-case charged cost per second of the chosen model — voice plus an
allowance for answers, markup included — and the admin max length). No
budget for the level, or the budget gate off (self-host without billing)
⇒ admin max length only. Each tick re-computes it: a chat in another tab
may have spent budget, a top-up may have arrived.

| Remaining | Gateway action | User sees |
| --------- | -------------- | --------- |
| > 60 s | continue | elapsed time, "about N min left in your budget" when a budget applies |
| ≤ 60 s | `warn`: instruction "mention once, briefly, that about a minute is left" | banner with countdown |
| 0 | `wrap_up`: instruction "say goodbye in one sentence and stop"; mic muted | "Wrapping up…" |
| 0 + 8 s or model done | `stop`: session closed, row finalised | terminal panel (§6.4) with the reason and the next action |

End reasons and copy targets: `user_end`, `idle`, `max_duration`,
`limit_budget` (the existing `COST_BUDGET_EXCEEDED` path: top-up / plan
on web, IAP paywall in the app), `provider_error`, `network_lost`,
`capacity`, `admin_disabled`.

The worst-case estimate can be beaten by a long answer. If a delegation
would push the budget past zero, the answer is still spoken (it is already
paid for) and the session moves straight to `wrap_up`.

### 5.4 Display

- **Panel**: elapsed time, "about 12 min left in your budget" (estimate
  from the remaining budget; hidden when no budget applies), the model and
  voice chip.
- **Taximeter** (`usageTaximeter` store): live cost updates on each tick.
- **Budget**: nothing new to build. `checkCostBudget()` already sums
  `BCOST` of *all* `BUSELOG` rows of the user in the billing period, whatever
  the action, times the markup, against `BSUBSCRIPTIONS.BCOST_BUDGET_MONTHLY`
  of the level (plus top-ups). The `VOICE_LIVE` row is one more of those
  rows, so the budget bar, "today / this week / this month" and the limit
  checks of chat, images and video see the voice cost without any change.
  Example from our production settings: NEW 1, PRO 15, TEAM 40, BUSINESS 80
  per month — a PRO user who talks for 60 min on GPT-Live (~$3 + answers)
  has that much less for chat and images that month.
- **`/statistics` → Recent Activity** (`UsageStatistics.vue`,
  `UsageStatsService::getActivityLog()` / `getRecentUsage()`): one row per
  conversation, the same row shape as every other action:

  | Column | Live conversation row |
  | --- | --- |
  | Action | chip "Live conversation" — i18n `config.usage.actions.voice_live`: de *Live-Gespräch*, en *Live conversation*, es *Conversación en vivo*, fr *Conversation en direct*, tr *Canlı sohbet* |
  | Duration | **new** under the chip: talk time, e.g. "4 min 12 s" (from `BMETADATA.media_usage.duration_seconds`, the key transcription already uses) |
  | Channel | web, iOS, Android, CarPlay (from `BMETADATA.source`) |
  | Model | the voice model (`GPT-Live`, `Gemini 3.8 Live`); the answering chat model in the tooltip |
  | Tokens | the answers' prompt / completion tokens (+ Gemini audio tokens); GPT-Live voice itself is per second, so no token figure for it |
  | Cost | voice + answers, same unit and markup rule as every other row |
  | Latency | time to first spoken audio |

  While a conversation runs, its row is already there and its cost grows
  each tick; the reaper guarantees it ends in a terminal state.
  The action filter gets "Live conversation" (`VOICE_LIVE`) and the missing
  "Transcription" (`TRANSCRIPTION`). The CSV export gets a `Duration (s)`
  column (empty for other actions). The API adds one optional
  `duration_seconds` to the activity and recent-usage items (OpenAPI +
  regenerated Zod). `VOICE_LIVE` is **not** added to
  `UsageStatsService::ACTION_TYPES` — that list drives the count limits,
  and live voice has none.
- **Activity row link**: the row opens the chat the conversation was in.
- **Admin**: per-model concurrency and voice seconds in the usage admin
  view; live sessions count and a kill switch per session.


---

## 6. Frontend (web, `ota-candidate`)

### 6.1 Button

In `section-chat-primary-actions`: `ModelDropdown` → microphone →
**conversation button** (`mdi:waveform`, 44×44, `icon-ghost !rounded-xl`,
`data-testid="btn-chat-live-voice"`) → send. Shown only when the module
is configured and (in the app) the native capability exists. An exhausted
budget does not hide the button: pressing it opens the same budget prompt
a typed message gets (top-up / plan on web, IAP in the app), so the user
learns why instead of finding a missing control. Tooltip and aria-label from
i18n (`chatInput.liveVoice`). Guests and the widget never see it.

### 6.2 Panel (replaces the composer row while live, not a new page)

```
┌──────────────────────────────────────────────────────────────┐
│  ◉  Listening…                       GPT-Live · Marin   ▾    │
│     ▁▃▅▇▅▃▁ (input level)       ~12 min left in your budget  │
│                                                              │
│  [ Mute ]   [ Captions on ]                     [ End ]      │
│  Your voice goes to OpenAI while you talk. Transcript saved  │
│  in this chat.                                               │
└──────────────────────────────────────────────────────────────┘
```

States: `connecting` → `listening` / `thinking` (delegation running,
"Checking your notes…") / `speaking` → `ending` → terminal. Live turns
appear in the message list as they close. Esc = End; Space = Mute when
focus is on the panel. 320 px: model chip collapses into a menu.

### 6.3 Five questions on the open surface (U7)

Owner: "You" (the chat). Who else: the provider named in one line. What it
touches: this chat, your memories and knowledge if Synaplan answers. How to
stop: End (and Mute). Where it came from: model + voice chip, link to
voice settings.

### 6.4 Terminal states (U8)

One sentence + one action each, five locales, e.g. "Conversation ended —
your usage budget for this month is used up. [Top up]"; "The voice
service did not answer. Nothing more was sent. [Use the microphone
instead]"; "Your connection dropped. The transcript up to here is saved.
[Talk again]". Never an HTTP code.

### 6.5 Settings

Settings → Voice: preferred live model (from `VOICE_LIVE` models the plan
allows), voice per provider (from catalog `json.voices`), captions default.
Consent can be withdrawn here.

### 6.6 Audio implementation

`getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true,
autoGainControl: true } })`, an `AudioWorklet` resampling to the adapter's
input rate and framing 20–40 ms PCM16 chunks, a playback worklet with a
jitter buffer that is flushed instantly on `interrupted`. Session state in a
Pinia setup store `liveVoice`. Listeners and worklets torn down in
`onUnmounted` and on route change.

### 6.7 i18n

`chat.json` → `chatInput.liveVoice.*` (button, consent, states, terminal
copy, banners), `settings` namespace for the voice settings, usage labels
where the statistics strings live. All five locales in the same PR; canonical
terms per `AGENTS.md`.

---

## 7. API (OpenAPI, Zod generated)

| Method + path | Body / query | Response |
| ------------- | ------------ | -------- |
| `GET /api/v1/voice/live/options` | — | `available`, `reason?`, `models[{id,label,provider,voices[]}]`, `defaultModelId`, `estimatedSecondsLeft` (from the cost budget; `null` when no budget applies), `budgetExhausted`, `consentGiven`, `maxSessionSeconds` |
| `POST /api/v1/voice/live/sessions` | `chatId?`, `modelId?`, `voice?`, `language`, `client` (`web/ios/android/carplay`), `incognito?` | `sessionId`, `chatId`, `ticket`, `wsUrl`, `transport` (`relay` or `webrtc`), `audio{inRate,outRate,format}`, `allowedSeconds`, `warnAtSeconds`, `provider`, `model`, `voice` — or the house budget payload (`COST_BUDGET_EXCEEDED`, `limit`, `used`, `remaining`, `topup_available`), identical to the one `/api/v1/messages/send` returns |
| `POST /api/v1/voice/live/sessions/{id}/end` | — | terminal summary |
| `GET /api/v1/voice/live/sessions/{id}` | — | `status`, `endReason`, `seconds`, `cost`, `chatId` |
| `PUT /api/v1/voice/live/consent` | `given: bool` | `consentGiven` |
| `GET /api/v1/config/runtime` | — | additive `voiceLive: { enabled }` only (details come from options) |

Gateway ⇄ client WebSocket protocol (JSON control + binary audio):
`ready`, `state`, `transcript{role,text,final}`, `interrupted`, `usage{seconds,secondsLeft}`,
`warn{secondsLeft}`, `wrap_up`, `ended{reason}`; client sends `mute`,
`unmute`, `end`, binary PCM. Documented in `docs/LIVE_VOICE.md`; a JSON
schema file in the sidecar is the single source for TS and Swift tests.

---

## 8. Mobile apps and CarPlay (`synaplan-apps`, `store-required`)

### 8.1 Apps (LV7)

- The SPA button needs a native capability flag `liveVoice` exposed by the
  new binary (MOBILE-APP SEAM in the native capability service). Older
  binaries: button absent.
- Transport: relay WebSocket. iOS: WKWebView `getUserMedia` (iOS 14.3+)
  works, but echo cancellation in WKWebView on the loudspeaker must be
  measured in LV0; if weak, the app routes audio through a small native
  plugin that reuses `VoiceAudioGraph` (voice processing) and only passes
  control events to the WebView. Android: `WebChromeClient`
  permission grant for `RESOURCE_AUDIO_CAPTURE`, or the same native plugin.
  A new native plugin is an ask-first item.
- Store work: App Store privacy labels (audio data shared with OpenAI /
  Google / xAI for app functionality), Play Data safety, `PrivacyInfo`
  review, microphone purpose string updated to mention live conversations,
  store review notes with a demo account that has budget left.
- IAP: no new products; live voice draws on the existing plan budget. Paywall in
  the app is IAP-only (anti-steering).

### 8.2 CarPlay (LV8)

- `VoiceConversationEngine` gains a `LiveVoiceSession` path: same voice
  template, same session store, same audio session rules (active only while
  voice is used). Audio: `VoiceAudioGraph` mic tap → PCM16 → gateway;
  provider audio → player node. Echo cancellation stays on; GPT-Live
  handles barge-in itself, so the local `BargeInDetector` is used only with
  turn-based providers.
- Choice per conversation: live when `options.available`, else the
  existing turn-based loop. Budget exhausted mid-drive ⇒ one spoken
  sentence and the conversation ends; the turn-based loop draws on the same
  budget, so it is not offered (decision 10). Live service busy or down ⇒
  one sentence, then the turn-based loop continues in the same chat. No
  message content on screen (CarPlay rules).
- Locked phone: the session store already mirrors tokens with
  AfterFirstUnlockThisDeviceOnly; the start call uses Bearer.
- Network loss in a tunnel: gateway keeps the provider session for 20 s
  (Gemini resumption, GPT-Live stays open); the app reconnects with a fresh
  ticket bound to the same session; after 20 s the session ends with
  `network_lost` and the app says so.
- Requires the CarPlay voice entitlement (already requested) and a real
  head-unit test of echo and barge-in before release.

---

## 9. Configuration

| Env / BCONFIG | Default | Purpose |
| ------------- | ------- | ------- |
| `LIVE_VOICE_GATEWAY_URL` | empty (module off) | internal URL of the sidecar |
| `LIVE_VOICE_GATEWAY_SECRET` | empty | HMAC for internal callbacks + ticket key |
| `LIVE_VOICE_PUBLIC_WS_PATH` | `/voice/ws` | Caddy route |
| `OPENAI_LIVE_REGION` | `us` | `eu` uses `eu.api.openai.com` |
| `LIVE_VOICE.MAX_SESSION_SECONDS` | 1800 | hard cap |
| `LIVE_VOICE.IDLE_SECONDS` | 120 | both sides silent |
| `LIVE_VOICE.WARN_SECONDS` | 60 | limit warning |
| `LIVE_VOICE.TICK_SECONDS` | 15 | metering interval |
| `LIVE_VOICE.CONCURRENCY.<provider>` | openai 50, google 100, xai 10 | provider caps (Redis semaphore) |

Compose: profile `voice` for `synaplan-voice`; `.env.minimal` and
`docker-compose.minimal.yml` list the decisive env (FeatureModule rule).
Production (`synaplan-platform`, private) adds the service and the Caddy
route; no private details go into this repository.

---

## 10. Tests and gates

| Layer | What |
| ----- | ---- |
| Backend unit | cost math for `per_second` and `per_audio_token`; budget → `allowedSeconds` (markup, top-ups, running sessions, no budget, gate off); answer cost added to the conversation row and no `MESSAGES` row written; end-reason mapping; ticket single-use; transcript segmentation; delegation option building |
| Backend integration | start → ticks → close with a fake gateway; budget crossing mid-session (by voice, by a long answer, by a chat in another tab); reaper; incognito; BYO key; admin kill |
| Characterization | none expected; if the delegation touches classifier input, re-record and review snapshots |
| Sidecar | adapter event mapping against recorded fixture streams for all four providers; relay backpressure; resumption on `GoAway`; wrap-up timing; HMAC; no audio/text in logs |
| Mock provider | `frontend/tests/e2e/stub-servers/voice/` speaking the OpenAI Live and Realtime dialects (and a Gemini dialect) with canned audio + transcripts + usage |
| Frontend unit | state machine, panel copy per terminal state, button visibility rules (flag, plan, native capability, guest) |
| Playwright `@ci` | Chromium with `--use-fake-device-for-media-stream --use-file-for-fake-audio-capture`: J-LV-1, J-LV-3 (limit in 40 s), J-LV-4 (mic denied, provider down), J-LV-8 (close tab); layout spec at 320 px; dark + V2 |
| Mobile impact | new paths classified in `.github/mobile-impact-policy.json`; `tests/mobile-impact.test.mjs` extended |
| Apps repo | contract test against the OpenAPI annotations (pattern of `carplay-contract.test.mjs`); CarPlay logic tests for live/fallback switching; device tests on iPhone + head unit; Maestro smoke for the button in the new binary |
| Load | 50 parallel relay sessions against the mock: gateway CPU, latency added by the relay (target < 40 ms p95) |
| Manual | real providers per adapter in DE/EN/ES/FR/TR; interruption; long session > 15 min on Gemini (compression + resumption); OpenAI EU host |

Full gates before every commit: `make ci-local`, `make test-e2e`,
`make -C frontend test-e2e-layout` for the panel; apps: `npm run ci-local`,
`npm run e2e`.

---

## 11. Steps

| Step | Scope | Class | Exit |
| ---- | ----- | ----- | ---- |
| **LV0** | Spike, no product code, for **GPT-Live and Gemini Live**: access on our accounts; first-audio latency via relay vs WebRTC (EU and US); delegation round trip through `MessageProcessor` with a 2-sentence answer (`client` delegation on GPT-Live, `consult_synaplan` on Gemini); WKWebView echo cancellation on the iPhone loudspeaker; cost per real minute incl. delegation tokens; what the seeded budgets buy in minutes; verify unverified rows in §3. | — | Done 2026-10-12: `STATUS.md` with numbers for both providers |
| **LV1** | Catalog rows (`gpt-live-1`, `gemini-3.8-live`), `VOICE_LIVE` capability + default key, `per_second` and `per_audio_token` pricing, `BVOICESESSIONS` migration, metering service that writes voice + answer cost into one `VOICE_LIVE` row of the existing budget, budget → `allowedSeconds`, `duration_seconds` on the activity / recent-usage API + CSV column, discovery rule removed, pricing docs. No limit keys, no seeder change. | backend-only | unit + integration green; with fake ticks the budget, the cost summary and the activity API show the conversation |
| **LV2** | `synaplan-voice` sidecar with `OpenAiLiveAdapter` **and** `GeminiLiveAdapter` (`consult_synaplan`, resumption on `GoAway`, `contextWindowCompression`), tickets, internal callbacks, delegation, transcript writer, reaper, per-provider concurrency, `LiveVoiceModule`, Caddy route, compose profile, mock provider (OpenAI Live + Gemini dialects). | backend-only / no-app-impact | J-LV-5 (backend part) and J-LV-8 via a scripted WS client, per provider |
| **LV3** | Button, consent sheet, panel, states, terminal copy, settings, five locales, icon map. | ota-candidate | J-LV-1, J-LV-2, J-LV-4, J-LV-5 walked; UX §6 five bullets |
| **LV4** | Mid-session budget end to end: warn, wrap-up, terminal panels, top-up / paywall wiring, taximeter, Recent Activity row (chip, duration, filter for `VOICE_LIVE` + `TRANSCRIPTION`), five locales, admin live view + kill. |
 ota-candidate + backend-only | J-LV-3 walked; Playwright limit spec green |
| **LV5** | `XaiVoiceAdapter`, `OpenAiRealtimeAdapter` (+ mini), `gemini-3.8-live-extended-thinking` row. | backend-only | fixture tests per adapter; manual DE/EN/ES/FR/TR pass per provider |
| **LV6** | Optional direct WebRTC for OpenAI in the browser (server SDP exchange, `allowed_client_events`, sideband in the gateway for delegation + usage + kill), admin setting. | ota-candidate + backend-only | latency gain measured; billing identical to relay on the same session |
| **LV7** | Apps: native capability seam, (if LV0 demands) native audio plugin, permissions, privacy labels, store notes. | store-required | J-LV-6 on iPhone and Android devices |
| **LV8** | CarPlay live path with fallback to the turn-based loop for capacity and provider errors; budget end without fallback. |
 store-required | J-LV-7 in CarPlay Simulator + a real head unit |
| **LV9** | Compatible-endpoint adapter (Azure, Qwen Cloud, vLLM-Omni Qwen3-Omni), sovereign recipe docs (Unmute), barge-in for the turn-based web voice as the no-GPU fallback. | backend-only + ota-candidate | one self-hosted model talks end to end on a GPU box |

`01_sprints.md` expands each row (files, vibe prompt, exit bullets) when
LV0 is done.

### LV3 exit (UX contract §6)

- **Journey.** J-LV-5 flag-off walked in the browser: signed-in chat shows the dictation microphone and no live-conversation button. `/settings/voice` falls through to profile. A live provider conversation (J-LV-1, J-LV-2, J-LV-4 with the gateway on) was not walked; the module is off without gateway URL, secret and a provider key.
- **Findability.** Closed turns are appended to the same chat as they finish. The cost row on `/statistics` is LV4.
- **Copy.** Consent, states, terminal sentences and the withdraw consequence are in de, en, es, fr and tr.
- **Empty, error, flag-off.** Flag off removes the button and the settings section. Terminal states name one recovery (talk again, microphone, top up, or close). Connecting times out into that sentence.
- **Theme and size.** The panel uses the shared surface and button classes (light, dark, design v2). At 320 px the model chip collapses into a menu. A visual pass of a live panel was not possible while the module is off.

### LV4 exit (UX contract §6)

- **Journey.** J-LV-3 (about 40 seconds of budget left, countdown, one-sentence goodbye, terminal panel with the top-up action) was not walked against a provider. The module is off without a gateway URL, secret and a provider key. `VoiceLiveUsageServiceTest` covers the warn at 40 seconds and the wrap-up then stop. The statistics filter that names "Live conversation" and "Transcription" is the Playwright check that can pass with the module off.
- **Findability.** A live-conversation row in Recent Activity shows the talk time under the chip and opens that chat when the row stored a chat id. The row is there while the conversation is still running and the cost grows on each tick. The taximeter reloads the charged total on each usage tick.
- **Copy.** The countdown banner, "Wrapping up…", the action labels, the duration and the admin end-conversation dialog are in de, en, es, fr and tr. Ending a conversation from admin says the microphone stops within a few seconds and the saved transcript stays.
- **Empty, error, flag-off.** With the module off the admin live section is absent. With it on and nobody talking, the section is one sentence. A missing session returns "not found". The kill confirm names the person and what stays saved.
- **Theme and size.** The new rows use the existing statistics table and the shared card, text and danger-button classes. A live countdown banner was not on screen while the module is off.

### LV5 exit (UX contract §6)

- **Journey.** No new screen. The four models are catalog rows (BIDs 391–394) and appear in the live-voice model list once that provider's key is configured. A spoken conversation in de, en, es, fr and tr was not walked; that pass needs a running gateway and a provider key for each adapter.
- **Findability.** The model name is the existing chip on the live panel and the model column on the usage row. Nothing new to hunt for.
- **Copy.** No new user-facing sentences. The Grok Voice catalog description says EU data handling for that model is not confirmed.
- **Empty, error, flag-off.** A model without its provider key stays off the list, same as GPT-Live and Gemini Live. Flag off still hides the button.
- **Theme and size.** Unchanged. The adapters were checked against local fixture sockets, not a rendered panel.

### LV6 exit (UX contract §6)

- **Journey.** No new chat screen. The admin live section gains one switch, default off. With the module off that section is absent, so the switch was not clicked in the browser. A spoken WebRTC conversation was not walked; that needs the gateway, a provider key, and the switch on.
- **Findability.** The switch sits on the same admin usage section as the live list. A conversation already running is unchanged when the switch moves.
- **Copy.** The sentence names what the next OpenAI conversation does, that Google and xAI stay on the server, and that turning it off does not move a conversation already running. de, en, es, fr, tr.
- **Empty, error, flag-off.** Default is off, so every conversation stays on the relay. Flag off hides the section. A failed save says the setting was not stored.
- **Theme and size.** The switch uses the shared secondary button. Latency against a live provider was not measured. The fixture shows the sideband usage meter matches the relay mapper, and a stop closes the sideband.

### LV7 exit (UX contract §6)

- **Journey.** J-LV-6 (waveform button on the phone loudspeaker, answer stops when the person talks over it) was not walked. It needs a new binary on an iPhone and an Android device, a gateway, a provider key, and budget left. The capability flag is the app-local `SynaplanLiveVoice` plugin. Older binaries do not register it, so the button stays absent.
- **Findability.** The button is the same waveform control as on the web, beside the microphone. The transcript lands in the same chat. The cost row is the existing live-conversation row on statistics.
- **Copy.** The iOS microphone purpose string names a live conversation. Store listing bullets and review notes are in de, en, es, and tr. No new in-app sentence: the panel copy is the web copy already in five locales.
- **Empty, error, flag-off.** No plugin, module off, or a guest account: the button is absent and dictation stays. A denied microphone uses the existing terminal sentence and offers the turn-based microphone. A spent budget opens the existing store subscription screen. There is no new product.
- **Theme and size.** Unchanged from the web panel. Audio stays in the WebView. A native audio plugin waits on the iPhone loudspeaker echo measurement.

### LV8 exit (UX contract §6)

- **Journey.** J-LV-7 (talk freely in CarPlay, one spoken sentence when the budget ends, one spoken sentence then the turn-based loop when live voice is busy or down) was not walked. It needs a new binary, a CarPlay Simulator or a head unit, a gateway, a provider key, and budget left. The route choice is covered by `LiveVoiceCarPlanTests`. Echo and barge-in on a real head unit are still open.
- **Findability.** The voice screen is the same CarPlay template. A live conversation that falls back stays in the chat the start call returned. The cost row is the existing live-conversation row on statistics, in the phone app.
- **Copy.** The four spoken sentences (budget used up, busy, unavailable, connection lost) are in de, en, es, fr, and tr. They do not tell the driver to use the iPhone. The car screen still shows no message text. A sentence that was already spoken does not add an alert.
- **Empty, error, flag-off.** No consent, or live voice not configured: the existing turn-based loop, with no extra sentence. A used-up budget ends the conversation. A relay the car cannot use is ended, then the turn-based loop. A drop longer than 20 seconds says the connection was lost and ends.
- **Theme and size.** No new CarPlay controls. Audio uses the existing voice graph. This was not played on a head unit.

---

## 12. Risks

| Risk | Mitigation |
| ---- | ---------- |
| Delegation latency feels slow with a reasoning chat model (every content answer goes through Synaplan, decision 4) | `session.thinking.append` progress ("checking your notes") on GPT-Live, `NON_BLOCKING` + `WHEN_IDLE` on Gemini, voice-mode prompt keeps answers short, admin may pick a fast delegation model per session; measure in LV0 |

| `commentary.append` ≤ 500 tokens | chunk long answers; the voice-mode prompt aims at ≤ 3 sentences and offers "shall I go on?" |
| Gemini 10-minute connection resets | resumption handle in the gateway; client never sees the reconnect |
| xAI 10 sessions per team | Redis semaphore + `capacity` end reason + turn-based offer |
| Runaway cost | per-tick budget gate, hard max length, idle end, admin kill, reaper |
| A level without a budget (0) is unlimited, as for chat today — live voice is ~$3/hour on GPT-Live | operators set a budget per level (also on free levels) when they enable the module; the module's admin page says so in one sentence; max length + idle end still apply |
| One user spends the whole month's budget on voice and then cannot chat | intended by decision 7; the panel shows "about N min left in your budget" and the 60 s warning, `/statistics` shows voice as its own line in the breakdown |
| Echo in cars and on loudspeakers | voice processing natively, WebRTC/`getUserMedia` AEC in browsers, head-unit test gate |
| Provider price or model churn | pinned versioned IDs, price-drift job covers the new modes, retirement registry |
| Privacy (third-party audio) | consent sheet per provider, ZDR/`store:false`, EU hosts, store privacy labels, incognito |
| Transcript not verbatim | labelled in the UI; never used as a legal record |
| Anthropic-only installs get nothing | button absent (U11); turn-based voice stays, and gets barge-in in LV9 |
