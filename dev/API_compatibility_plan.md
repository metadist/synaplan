## API Compatibility Plan (OpenAI + Ollama + Google + Anthropic)

### Objectives
- Add OpenAI-compatible endpoints (chat, chat stream, image generation, models list) on `public/api.php` without breaking current actions.
- Support provider selection via `model` request value, e.g. `"openai/gpt-5"`, `"ollama/llama3.3:70b"`, `"google/gemini-2.5-pro"`, `"anthropic/claude-opus-4-20250514"`.
- Allow listing models directly from `BMODELS` including all columns.
- Expose sound-to-text and pic2text via OpenAI-like paths where applicable.
- Keep current functionality and endpoints working.

### High-level approach
- Introduce routing-based separation in `public/api.php` for two surfaces:
  1) Existing Synaplan actions (unchanged)
  2) OpenAI-compatible API surface under distinct paths:
     - POST `/v1/chat/completions` (non-stream)
     - POST `/v1/chat/completions` with `stream=true` (SSE)
     - POST `/v1/images/generations`
     - GET `/v1/models`
     - POST `/v1/audio/transcriptions` (sound→text)
     - POST `/v1/images/analysis` (pic→text; OpenAI approximate)

- Implement a small dispatcher layer in `api.php` that detects the path-style OpenAI requests before `action` switch. If a match, delegate to a new internal controller class (no logic in this iteration; planning only).
- Use the API token identification with tokens the user created in @c_apikeys.php
- Alternatively allow the @_oidc.php methods for user identification on API calls

### Request model selection strategy
- Parse incoming `model` string:
  - If it contains a slash: `<provider>/<modelName>` → provider inferred from prefix: `ollama`, `openai`, `google`, `anthropic`.
  - If no slash: look up `BMODELS` where `BPROVID` or `BNAME` or `BTAG` matches the value; read `BSERVICE` to choose provider.
- Resolve to a canonical tuple: `{ service: AI<Provider>, providerModel: BPROVID, modelId: BID }` via `BasicAI::getModelDetails()` when coming from `BMODELS`, else direct mapping for `<provider>/<model>`.
- Fall back to current `$GLOBALS["AI_*"]` defaults if `model` is absent.

### Response shaping (OpenAI compatibility)
- Chat create: Return JSON compatible with OpenAI chat completions where feasible:
  - `id`, `object`, `created`, `model`, `choices: [{ index, message: { role: "assistant", content }, finish_reason }]`, `usage` (optional best-effort)
- Streaming: SSE with `data: {id, object: "chat.completion.chunk", ... , choices:[{ delta:{content:"..."}}] }` and `data: [DONE]` terminator.
- Images: Return `data: [{ b64_json }]` or URLs where available; we will base64 for local saves.
- Models list: Map each row from `BMODELS` to an OpenAI-like `Model` object while also including raw DB columns under an `metadata` key.
- Audio transcriptions: mimic OpenAI’s plain text (default) or JSON format; start with text.
- Pic2Text: respond with `{ text: "..." }` or embed into a chat completion-style output for uniformity.

### New internal controller (planned)
- Create `public/inc/_openaiapi.php` with a class `OpenAICompatController` exposing static methods:
  - `chatCompletions($req, $stream = false)`
  - `imageGenerations($req)`
  - `listModels()`
  - `audioTranscriptions($req)`
  - `imageAnalysis($req)`
- Responsibilities:
  - Validate and normalize payload to our internal shape.
  - Resolve model → provider using the strategy above.
  - Call provider adapters: `AIOpenAI`, `AIOllama`, `AIGoogle`, `AIAnthropic` using existing methods (`topicPrompt`, `picPrompt`, `mp3ToText`, `explainImage`).
  - Convert provider-specific responses to OpenAI-compatible JSON.
  - Annotate `BMESSAGEMETA` with `AISERVICE`, `AIMODEL`, `AIMODELID` like current flow (reuse `XSControl::storeAIDetails`).

### Routing changes (non-breaking)
- In `public/api.php` (before existing `action` switch):
  - Detect path by `$_SERVER["REQUEST_URI"]` and method:
    - `POST /v1/chat/completions` → `OpenAICompatController::chatCompletions(..., stream=false)`
    - `POST /v1/chat/completions` + `stream=true` header/param → stream mode
    - `POST /v1/images/generations` → `imageGenerations`
    - `GET /v1/models` → `listModels`
    - `POST /v1/audio/transcriptions` → `audioTranscriptions`
    - `POST /v1/images/analysis` → `imageAnalysis`
- If none matches, fall back to current `action` handling unchanged.

### Provider call mapping
- Chat (and stream): use `topicPrompt($msgArr, $threadArr, $stream)` in the resolved provider class.
- Image generation: `picPrompt($msgArr, $stream=false)` (OpenAI/Google supported; Ollama image gen not standard → skip unless configured).
- Sound→Text: `mp3ToText($msgArr)` where supported (`AIOpenAI` now, others TBD/limited).
- Pic→Text: `explainImage($msgArr)` across providers.

### Model listing endpoint
- `GET /v1/models`:
  - Query `BasicAI::getAllModels()`
  - For each row, return:
    - `id` = `BPROVID`
    - `object` = `model`
    - `owned_by` = `BSERVICE`
    - `created` = null or 0
    - `metadata` = full row including `BID`, `BNAME`, `BTAG`, `BSELECTABLE`, `BPRICEIN`, `BINUNIT`, `BPRICEOUT`, `BOUTUNIT`, `BQUALITY`, `BRATING`, `BJSON`

### Data flow and persistence
- Reuse `Frontend::saveWebMessages`, `ProcessMethods`, `XSControl` as-is for the web UI.
- For direct API calls (OpenAI surface), we will:
  - Authenticate via Bearer (already present) OR API key OR OIDC. All methods are prepared.
  - Use the existing methods for generation of the message.
  - Enhance the output handling to answer via API, not WhatsApp or Web Widget.
  - The logic for Webwidget or Mail is already there. Please keep the flow intact.

### Backward compatibility
- No changes to existing `action` routes and behavior.
- OpenAI-compatible routes live alongside and do not interfere.
- Reuse existing providers and utilities; no refactors required for first iteration.
- The App is not only accepting API calls, also WhatsApp messages or email prompts. Keep that in mind.

### Error handling
- Map internal errors to OpenAI-like error envelope:
  - `{ error: { message, type, code } }` with appropriate HTTP status.
- Rate limiting: reuse existing session-based limiter where applicable.

### Security
- Continue using Bearer key validation already present.
- Sanitize inputs; enforce max sizes for uploads.

### Open questions
1) For chat payloads, should we support tools/function-calling now or later? (initial: later)
2) For `usage` tokens in responses, do we estimate based on provider or defer to 0?
3) Should `/v1/models` include only `BSELECTABLE=1` by default or all rows?
4) For streaming, OK to use existing SSE helpers (`Frontend::statusToStream`) or craft strict OpenAI chunks? (plan: strict chunks for `/v1/chat/completions`)
5) Any preference to store API-surface traffic into `BMESSAGES` when requests are machine-to-machine?

### Step-by-step implementation plan
1) Add new file `public/inc/_openaiapi.php` (controller) with method stubs that transform request/response only.
2) Update `public/api.php` routing to detect OpenAI paths and delegate to controller, leaving action switch intact.
3) Implement model resolution helper in controller using:
   - `<provider>/<model>` fast-path
   - `BasicAI::getModelDetails()` and `getAllModels()` fallback.
4) Implement `listModels()` using `BasicAI::getAllModels()` mapping to OpenAI schema with `metadata`.
5) Implement `chatCompletions()` non-stream: map OpenAI messages → internal arrays; call provider `topicPrompt`; shape OpenAI response; write meta.
6) Implement `chatCompletions()` stream mode: call provider streaming variants; emit OpenAI-compliant SSE chunks and terminal `[DONE]`.
7) Implement `imageGenerations()`: call provider `picPrompt`; return base64 image data; store file and message.
8) Implement `audioTranscriptions()`: receive file; call `mp3ToText`; return text; store message.
9) Implement `imageAnalysis()`: receive file; call `explainImage`; return text; store message.
10) Add basic validation and error envelopes; keep responses minimal and compatible.

### Rollout
- Guard behind a feature flag if needed (config), default on.
- Add a minimal README note for API consumers.
