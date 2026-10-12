# Live voice

Full-duplex speech. The browser, the app, or CarPlay opens one WebSocket to Synaplan. A sidecar holds the provider socket. Synaplan's own chat model answers factual questions. The voice model only speaks that answer.

The feature stays off until both `LIVE_VOICE_GATEWAY_URL` and `LIVE_VOICE_GATEWAY_SECRET` are set and at least one active `voice2voice` model has a provider key. The secret is at least 32 characters. Runtime config then reports `voiceLive.enabled: true`.

## Start

`POST /api/v1/voice/live/sessions` with a signed-in cookie or bearer token.

The body may include `modelId`, `chatId`, `client` (`web`, `ios`, `android`, `carplay`) and `incognito`. Consent must already be stored with `PUT /api/v1/voice/live/consent` and `{ "given": true }`.

The response carries `ticket`, `wsUrl`, `transport` (`relay` or `webrtc`), `audio` (`format`, `inRate`, `outRate`), `allowedSeconds` and `warnAtSeconds`. The ticket is a 30-second single-use JWT. It does not contain a provider key. `transport` is `relay` unless an admin has turned on the direct OpenAI connection and the chosen model is GPT-Live or GPT-Realtime. Google and xAI stay on `relay`.

A second open conversation for the same user is `409`. A used-up monthly budget is `429` with `code: COST_BUDGET_EXCEEDED`, the same payload as a typed message. Provider capacity is `503` with `code: VOICE_CAPACITY`.

## Socket

Connect to `wsUrl`. Send PCM16 mono as binary frames at `audio.inRate`. Send text frames for control:

| Client | Meaning |
| --- | --- |
| `{ "type": "mute" }` | Stop sending audio to the provider |
| `{ "type": "unmute" }` | Send audio again |
| `{ "type": "interrupt" }` | Barge in |
| `{ "type": "end" }` | The person hung up |

The server sends:

| Server | Meaning |
| --- | --- |
| `{ "type": "ready", "audio", "allowedSeconds" }` | The provider session is up |
| `{ "type": "transcript", "role", "text", "final" }` | A closed caption |
| binary | PCM16 from the provider at `audio.outRate` |
| `{ "type": "interrupted" }` | The model stopped speaking |
| `{ "type": "usage", "seconds", "secondsLeft" }` | Cumulative time |
| `{ "type": "warn", "secondsLeft" }` | About a minute of budget left |
| `{ "type": "wrap_up" }` | One goodbye sentence, then the socket closes |
| `{ "type": "ended", "reason" }` | The conversation is over. A dropped tab may close before this frame arrives |

Closing the socket without `end` is a dropped tab. The sidecar still closes the provider and posts `network_lost`. Reasons are `user_end`, `idle`, `max_duration`, `limit_budget`, `provider_error`, `network_lost`, `capacity`, `admin_disabled`.

GPT-Live uses client delegation. Gemini Live uses the tool `consult_synaplan`. Both come back through `POST /internal/voice/sessions/{id}/delegate`. The answer is spoken. It is not a separate `MESSAGES` usage row. Its cost is added to the one `VOICE_LIVE` row.

## Direct connection

`BCONFIG` group `LIVE_VOICE`, setting `WEBRTC_OPENAI`, default `0`. An admin turns it on from the live-conversations section. The next OpenAI conversation then uses WebRTC. A conversation already running stays on the transport it started with. Turning the switch off does not move it.

The browser still opens `wsUrl`. The sidecar asks for an SDP offer (`offer_required`). The browser creates the peer connection, sends `{ "type": "offer", "sdp" }`, and applies the `{ "type": "answer", "sdp" }` frame. Audio is Opus on that peer connection. PCM is not sent on the socket. Captions, mute, interrupt, end, usage, warn, wrap-up and ended stay on the socket.

The sidecar exchanges the SDP with the provider key. GPT-Live is `POST /v1/live/sessions` with `allowed_client_events` set to an empty list, so the browser data channel cannot change instructions, tools or context. The sidecar then opens the Live sideband for delegation, usage and kill. GPT-Realtime is `POST /v1/realtime/calls`. That API has no client-event allow-list. The app does not send control events on the data channel; the sideband is `wss://api.openai.com/v1/realtime?call_id=…`. The provider key never goes to the browser.

Billing uses the same usage mapping as the relay. A stop from the budget or an admin kill closes the sideband, and the browser closes the peer connection when `ended` arrives.

## Gateway callbacks

The sidecar signs every callback. Headers are `X-Voice-Timestamp` (unix seconds) and `X-Voice-Signature` (`hex(hmac_sha256(secret, timestamp + "\n" + rawBody))`). A timestamp older than a minute is rejected. The routes are:

- `POST /internal/voice/tickets/redeem`
- `POST /internal/voice/sessions/{id}/usage`
- `POST /internal/voice/sessions/{id}/delegate`
- `POST /internal/voice/sessions/{id}/transcript`
- `POST /internal/voice/sessions/{id}/closed`

They are not in the public OpenAPI document. Provider keys stay in the sidecar environment (`OPENAI_API_KEY`, `GOOGLE_GEMINI_API_KEY`). The gateway log line is the session id, the provider, and the end reason. It does not include audio or transcript text.

`OPENAI_LIVE_REGION=eu` points GPT-Live at `eu.api.openai.com`. The measured EU socket returned HTTP 401, so `us` remains the default until residency is confirmed.

## Operations

Start the sidecar with the compose profile `voice` and set:

```
LIVE_VOICE_GATEWAY_URL=http://voice:8096
LIVE_VOICE_GATEWAY_SECRET=<shared secret>
```

Caddy proxies `/voice/ws` to the sidecar and sends `/internal/voice` to PHP. A missing profile makes the socket path fail, which matches the feature being off.

Usage is ticked about every 15 seconds. A session with no tick for 45 seconds is closed by `app:voice:reap-sessions` on the scheduler tick (about once a minute) and billed up to the last tick. Idle silence of two minutes ends the conversation from the sidecar. The admin max length is `BCONFIG` `LIVE_VOICE` / `MAX_SESSION_SECONDS` (default 1800, floor 60).

Per-provider caps default to OpenAI 50, Google 100, xAI 10. Override with `LIVE_VOICE` / `CONCURRENCY_OPENAI` (and `CONCURRENCY_GOOGLE`, `CONCURRENCY_XAI`). Redis down refuses a new conversation, because a ticket cannot be made single-use without it.

Gemini sessions send `contextWindowCompression` and reconnect with the resumption handle on `GoAway`. That reconnect does not open a second usage row.
