# Live voice — measurements

LV0 spike, 2026-10-12. Not product code. Command:

```bash
node _devextras/planning/20261008-live-voice/spike/measure.mjs
```

Raw JSON: `spike/results.json`. Keys are read from `backend/.env` and are not written here.
The spoken prompt was macOS `say -v Samantha`: "What is the capital of France?"
Audio was paced at the sample rate, then 1.5 s of silence. This is a direct
socket to the provider. The relay in `synaplan-voice` does not exist yet, so
these numbers are the floor, not the latency a user will see through the gateway.

## What was measured

| | GPT-Live `gpt-live-1` (US) | Gemini `gemini-3.8-live` |
| --- | --- | --- |
| Connect | 550 ms | 94 ms |
| Session ready | 1 348 ms | 424 ms |
| User finished speaking → model asks Synaplan | 762 ms | 1 066 ms |
| Synaplan answer (`MessageProcessor`, gpt-5.4-mini, two sentences) | 2 827 ms (first token 2 471 ms) | same pipeline |
| Answer handed back → first spoken audio | 643 ms | 582 ms |
| **Sum, after the user stops, until speech starts** | **about 4.2 s** | **about 4.5 s** |
| Heard the prompt | "What is the capital of France" | tool `consult_synaplan` fired |
| `store: false` | accepted | paid tier, no audio stored by us |
| Billed | 7 seconds (`usage.seconds`), not rounded up to a minute | token snapshot only; audio-out tokens were not in the snapshot (the socket closed as audio started) |

GPT-Live started producing audio 976 ms *before* the utterance ended. That is
the full-duplex behaviour: it talks while the person is still speaking.
Client delegation worked (`session.delegation.created`, target `client`),
and `session.thinking.append` / `session.commentary.append` were both accepted.
Closing the socket in the same moment as `commentary.append` fails with
`context_injection_incomplete`. The gateway has to wait for the spoken reply.

The chat pipeline was the real one: `POST /api/v1/messages/stream` as the demo
user, model id 232 (`gpt-5.4-mini`). The demo user's *default* model is the
canned test provider and answers in ~100 ms with "demo mode" — that number
is not the brain latency. The test chat was deleted afterwards.

## What the shared budget buys

Since 2026-10-12 there is no minutes quota (plan decision 7): live voice is
one more cost in the level's monthly budget, next to chat, images and video.
The table shows how many minutes of *voice alone* each seeded
`BCOST_BUDGET_MONTHLY` would buy at list price, before markup and before the
answers' chat tokens. Every chat message, image or video in the same month
lowers it. The seeded budgets are plain numbers; the provider prices are USD.

Seeded defaults (`SubscriptionPlanSeeder`):

| Level | Budget | GPT-Live at $0.05/min | Gemini at ~$0.023/min (in + out) |
| --- | --- | --- | --- |
| PRO | 10 | 200 min | 434 min |
| TEAM | 30 | 600 min | 1 304 min |
| BUSINESS | 60 | 1 200 min | 2 608 min |

Our production settings (operator-edited, 2026-10-12):

| Level | Budget | GPT-Live | Gemini |
| --- | --- | --- | --- |
| NEW | 1 | 20 min | 43 min |
| PRO | 15 | 300 min | 652 min |
| TEAM | 40 | 800 min | 1 739 min |
| BUSINESS | 80 | 1 600 min | 3 478 min |

The budget check compares the summed `BCOST` (provider prices, USD) times
the markup directly with the budget number; there is no currency
conversion today. Live voice follows the same rule. A level without a
budget row or with budget 0 (NEW on a fresh install) has no cap, the same
as chat today.

One measured GPT-Live exchange (one question, ~10 s on the socket) was billed
as 7 seconds, about $0.006 of voice, plus one normal chat completion.

## Checked, not a latency number

- **OpenAI EU** `wss://eu.api.openai.com/v1/live/sessions` → HTTP 401.
  EU residency is not enabled on this org. Matches decision 13: request it
  after launch, not a blocker.
- **Gemini price** for `gemini-3.8-live` and `gemini-3.8-live-extended-thinking`
  is one row on the pricing page (audio in $3/1M, audio out $12/1M). The
  "verify at LV0" cell is the same price.
- **xAI** list price is still $0.08/min plus $0.004 per text item. Team-level
  zero-data retention exists. The US regional endpoint does **not** serve
  voice. EU voice processing is not documented as a region. Still not a
  launch provider.

## Not measured

| Item | Why |
| --- | --- |
| Relay overhead (target < 40 ms p95) | The sidecar is in the working tree. A scripted client proved tab close for both mock dialects. No live-provider relay timing yet. |
| WebRTC vs this WebSocket | No WebRTC stack on the spike host. LV6, and only if we still want it. |
| WKWebView echo cancellation on an iPhone loudspeaker | Needs a phone in the hand. |
| CarPlay head-unit echo | Needs a car. Gate before LV8, not before LV1. |
| A long Gemini session (> 15 min) and resumption | Not part of a cost spike. The adapter reconnects on `GoAway`; a live session longer than 15 minutes is still LV5. |

## LV2

The gateway is in the working tree (`sidecars/synaplan-voice`). Dropping the
browser tab was checked with a scripted WebSocket client against the mock
OpenAI and Gemini dialects: the provider socket closes, the transcript is
stored, and the usage row reaches a terminal state. No new latency numbers.

## LV3

The chat button, consent sheet, live panel, voice settings and the five
locales are in the working tree. With the module off (`voiceLive.enabled`
false) a signed-in chat shows the dictation microphone and no live button,
and `/settings/voice` opens the profile page. A conversation against a real
provider was not walked. No new latency numbers.

## LV4

The budget warning, wrap-up, statistics row (talk time, chat link, Live
conversation and Transcription filters) and the admin live list are in the
working tree. With the module off the admin live section is absent and the
statistics filter still lists the two actions. A 40-second countdown against
a provider was not walked. The warn at 40 seconds is the existing usage
unit test. No new latency numbers.

## LV5

GPT Realtime 2.1, GPT Realtime 2.1 mini, Gemini 3.8 Live extended thinking
and Grok Voice are catalog rows (BIDs 391–394). The sidecar picks the
Realtime adapter for `gpt-realtime*`, the existing Gemini adapter for the
extended-thinking model, and the xAI adapter for Grok Voice. Fixture sockets
cover session setup, Synaplan tool calls, accumulated audio tokens, and xAI
text items. A live conversation in each language was not walked. No new
latency numbers.

## LV6

Direct OpenAI audio is an admin switch, `LIVE_VOICE` / `WEBRTC_OPENAI`,
default off. While it is off, every conversation stays on the relay. While
it is on, GPT-Live and GPT-Realtime exchange SDP on the server (the browser
never receives the provider key) and the sidecar keeps a sideband for
delegation, usage and kill. Google and xAI stay on the relay. The fixture
posts the same usage meter the relay mapper would, and a stop closes the
sideband. A live provider conversation was not walked. The latency gain
against a provider was not measured.

## LV7

The apps shell registers `SynaplanLiveVoice` on iOS and Android. The SPA
shows the waveform button only when that plugin is present, so an older
binary keeps it hidden. Audio stays in the WebView on the relay socket.
Capacitor already grants Android `RESOURCE_AUDIO_CAPTURE` from the existing
microphone permission. The iOS purpose string names a live conversation.
There is no new in-app product. The loudspeaker echo measurement is still
open, so there is no native audio plugin. J-LV-6 was not walked on a device.
No new latency numbers.

## LV8

CarPlay opens the live relay when the server says it is available, the driver
has allowed it, and the monthly budget is not used up. The server forces
`relay` for `client: carplay`. A dropped socket keeps the provider session
for 20 seconds; the app mints a fresh ticket for the same session. A used-up
budget is one spoken sentence and the conversation ends. A busy or down live
service is one spoken sentence, then the turn-based loop in the same chat.
The car screen still shows no message text. J-LV-7 was not walked. Echo and
barge-in on a head unit were not measured. No new latency numbers.
