# 01 — Synaplan Exposes an OpenAI-Compatible API

## Goal

Developers can use **any OpenAI SDK** (Python `openai`, Node `openai`, curl, etc.) to talk to Synaplan by simply changing the base URL and API key. Synaplan becomes a **drop-in replacement** for the OpenAI API.

```python
from openai import OpenAI

client = OpenAI(
    base_url="https://web.synaplan.com/v1",
    api_key="sk-my-synaplan-key",
)

response = client.chat.completions.create(
    model="gpt-4o",        # or any model configured in Synaplan
    messages=[{"role": "user", "content": "Hello!"}],
    stream=True,
)
```

## What Already Exists

Internally, Synaplan already uses OpenAI message formats (`[['role' => 'user', 'content' => '...']]`), and `AiFacade` routes to the correct provider based on the model. The gap is only the **HTTP interface**: Synaplan's existing endpoints use a custom format, not the OpenAI wire protocol.

| What | Status |
|------|--------|
| Internal message format | Already OpenAI format |
| `AiFacade::chat()` / `chatStream()` | Already accepts `model`, `temperature`, `max_tokens` |
| Provider routing by model | Already works (`ModelConfigService` → `ProviderRegistry`) |
| API key auth (`X-API-Key`) | Already works |
| Streaming (SSE) | Works, but custom format — needs translation |
| `POST /v1/chat/completions` | **Missing** |
| `GET /v1/models` | **Missing** |
| OpenAI-format SSE chunks | **Missing** |

## What Changes

### Step 1.1 — `POST /v1/chat/completions` (Non-Streaming)

**New controller:** `backend/src/Controller/OpenAICompatibleController.php`

Accept the standard OpenAI request format:
```json
POST /v1/chat/completions
Authorization: Bearer sk-my-synaplan-key
Content-Type: application/json

{
  "model": "gpt-4o",
  "messages": [
    {"role": "system", "content": "You are a helpful assistant."},
    {"role": "user", "content": "Hello!"}
  ],
  "temperature": 0.7,
  "max_tokens": 4096,
  "stream": false
}
```

Return the standard OpenAI response format:
```json
{
  "id": "chatcmpl-synaplan-abc123",
  "object": "chat.completion",
  "created": 1677858242,
  "model": "gpt-4o",
  "choices": [{
    "index": 0,
    "message": {"role": "assistant", "content": "Hello! How can I help?"},
    "finish_reason": "stop"
  }],
  "usage": {
    "prompt_tokens": 0,
    "completion_tokens": 0,
    "total_tokens": 0
  }
}
```

**Implementation:**
1. Parse request body (standard OpenAI fields)
2. Resolve `model` string to a Synaplan model ID via `ModelRepository::findByServiceAndProviderId()` or by name — fall back to user's default chat model if no match
3. Authenticate via `Authorization: Bearer <key>` (map to existing `X-API-Key` auth) **and** `X-API-Key` header (existing)
4. Call `AiFacade::chat($messages, $userId, $options)` — all provider routing already works
5. Wrap the response string in the OpenAI response envelope

**Files to create:**
- `backend/src/Controller/OpenAICompatibleController.php`

**Files to change:**
- `backend/config/routes.yaml` — add `/v1/chat/completions` route
- `backend/config/packages/security.yaml` — allow API key auth on `/v1/` routes

**Auth note:** OpenAI SDKs send `Authorization: Bearer <key>`. Synaplan's `ApiKeyAuthenticator` currently checks `X-API-Key` header and `api_key` query param. We need to also check the `Authorization: Bearer` header — small change to `ApiKeyAuthenticator`.

**Test:** Send OpenAI-format request → get OpenAI-format response. Auth works with both `Authorization: Bearer` and `X-API-Key`. Invalid model → 404. No auth → 401.

### Step 1.2 — `POST /v1/chat/completions` (Streaming)

When `"stream": true`, return SSE in OpenAI's chunk format:

```
data: {"id":"chatcmpl-synaplan-abc123","object":"chat.completion.chunk","created":1677858242,"model":"gpt-4o","choices":[{"index":0,"delta":{"role":"assistant"},"finish_reason":null}]}

data: {"id":"chatcmpl-synaplan-abc123","object":"chat.completion.chunk","created":1677858242,"model":"gpt-4o","choices":[{"index":0,"delta":{"content":"Hello"},"finish_reason":null}]}

data: {"id":"chatcmpl-synaplan-abc123","object":"chat.completion.chunk","created":1677858242,"model":"gpt-4o","choices":[{"index":0,"delta":{},"finish_reason":"stop"}]}

data: [DONE]
```

**Implementation:**
1. Same request parsing as Step 1.1
2. Return `StreamedResponse` with `Content-Type: text/event-stream`
3. Call `AiFacade::chatStream($messages, $callback, $userId, $options)`
4. In the callback, wrap each chunk in the OpenAI delta format and write `data: {json}\n\n`
5. Write `data: [DONE]\n\n` at the end

**Files to change:**
- `backend/src/Controller/OpenAICompatibleController.php` — add streaming branch

**Test:** SSE stream matches OpenAI format. `data: [DONE]` sent at end. Client can parse it with any OpenAI SDK.

### Step 1.3 — `GET /v1/models`

Return available models in OpenAI's list format:

```json
{
  "object": "list",
  "data": [
    {
      "id": "gpt-4o",
      "object": "model",
      "created": 1700000000,
      "owned_by": "openai"
    },
    {
      "id": "llama3.1:8b",
      "object": "model",
      "created": 1700000000,
      "owned_by": "ollama"
    }
  ]
}
```

**Implementation:**
- Query `ModelRepository` for all active models
- Map each model: `id` = `providerId`, `owned_by` = lowercase `service`
- Auth required (same as chat completions)

**Files to change:**
- `backend/src/Controller/OpenAICompatibleController.php` — add `listModels()` method

**Test:** Returns all active models in OpenAI format. Auth required.

### Step 1.4 — `Authorization: Bearer` Support

OpenAI SDKs send `Authorization: Bearer sk-xxx`. Synaplan's `ApiKeyAuthenticator` currently only checks `X-API-Key` header and `api_key` query param.

Add `Authorization: Bearer` as a third source in `ApiKeyAuthenticator`:

```php
// Existing:
$apiKey = $request->headers->get('X-API-Key') ?? $request->query->get('api_key');

// New:
$apiKey = $request->headers->get('X-API-Key')
    ?? $request->query->get('api_key');
if (!$apiKey) {
    $authHeader = $request->headers->get('Authorization', '');
    if (str_starts_with($authHeader, 'Bearer ')) {
        $apiKey = substr($authHeader, 7);
    }
}
```

**Files to change:**
- `backend/src/Security/ApiKeyAuthenticator.php`

**Test:** Auth works with `Authorization: Bearer sk-xxx`. Existing `X-API-Key` auth still works (no regression).

### Step 1.5 — OpenAI-Compatible Error Responses

OpenAI returns errors in a specific format:
```json
{
  "error": {
    "message": "Invalid API key",
    "type": "invalid_request_error",
    "param": null,
    "code": "invalid_api_key"
  }
}
```

The new controller should return errors in this format (only on `/v1/` routes — existing Synaplan error format is untouched).

**Files to change:**
- `backend/src/Controller/OpenAICompatibleController.php` — error handling

**Test:** Auth failure returns OpenAI error format. Invalid model returns OpenAI error format.

## Implementation Order

```
1.4 (Bearer auth, 15 min) → 1.1 (non-streaming, 1-2 hours) → 1.2 (streaming, 1-2 hours) → 1.3 (models list, 30 min) → 1.5 (error format, 30 min)
```

## What Is NOT in This Plan

| Feature | Why Not Now |
|---------|-------------|
| Per-user API keys to external providers | Multi-tenancy, not API compatibility |
| Custom `OPENAI_BASE_URL` wiring | Trivial admin config, can be added as a one-liner anytime |
| `/v1/embeddings` endpoint | Can be added later if demand exists |
| `/v1/images/generations` endpoint | Can be added later if demand exists |
| `/v1/audio/transcriptions` endpoint | Can be added later if demand exists |
| `promptTopic` / `promptId` in OpenAI endpoint | Start simple, can add as extension later |

## Developer Experience

After implementation, this works:

```bash
# Python
pip install openai
```

```python
from openai import OpenAI

client = OpenAI(
    base_url="https://web.synaplan.com/v1",
    api_key="sk-my-synaplan-key",
)

# Non-streaming
response = client.chat.completions.create(
    model="gpt-4o",
    messages=[{"role": "user", "content": "What is Synaplan?"}],
)
print(response.choices[0].message.content)

# Streaming
stream = client.chat.completions.create(
    model="gpt-4o",
    messages=[{"role": "user", "content": "Tell me a story"}],
    stream=True,
)
for chunk in stream:
    if chunk.choices[0].delta.content:
        print(chunk.choices[0].delta.content, end="")

# List models
models = client.models.list()
for model in models:
    print(f"{model.id} ({model.owned_by})")
```

```bash
# curl
curl https://web.synaplan.com/v1/chat/completions \
  -H "Authorization: Bearer sk-my-synaplan-key" \
  -H "Content-Type: application/json" \
  -d '{"model":"gpt-4o","messages":[{"role":"user","content":"Hello"}]}'
```

## Security

- Uses existing API key authentication — no new auth mechanism
- Existing rate limiting applies via `RateLimitService`
- Model access: users can only use models available on the platform
- No new secrets or credentials needed
