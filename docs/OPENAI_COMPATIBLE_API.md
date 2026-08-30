# OpenAI-Compatible API

Synaplan exposes an OpenAI-compatible API at `/v1/`. Any tool, SDK, or application that speaks the OpenAI protocol can use Synaplan as a drop-in replacement.

## Quick Start

### Python

```python
from openai import OpenAI

client = OpenAI(
    base_url="https://your-synaplan-instance.com/v1",
    api_key="sk-your-synaplan-api-key",
)

# Non-streaming
response = client.chat.completions.create(
    model="gpt-4o",
    messages=[{"role": "user", "content": "Hello!"}],
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

# List available models
for model in client.models.list():
    print(f"{model.id} ({model.owned_by})")
```

### Node.js / TypeScript

```typescript
import OpenAI from 'openai'

const client = new OpenAI({
  baseURL: 'https://your-synaplan-instance.com/v1',
  apiKey: 'sk-your-synaplan-api-key',
})

const response = await client.chat.completions.create({
  model: 'gpt-4o',
  messages: [{ role: 'user', content: 'Hello!' }],
})

console.log(response.choices[0].message.content)
```

### curl

```bash
# Non-streaming
curl https://your-synaplan-instance.com/v1/chat/completions \
  -H "Authorization: Bearer sk-your-synaplan-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-4o",
    "messages": [{"role": "user", "content": "Hello!"}]
  }'

# Streaming
curl https://your-synaplan-instance.com/v1/chat/completions \
  -H "Authorization: Bearer sk-your-synaplan-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-4o",
    "messages": [{"role": "user", "content": "Hello!"}],
    "stream": true
  }'

# List models
curl https://your-synaplan-instance.com/v1/models \
  -H "Authorization: Bearer sk-your-synaplan-api-key"
```

## Endpoints

### `POST /v1/chat/completions`

Creates a chat completion. Supports both streaming and non-streaming modes.

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `messages` | array | Yes | Array of message objects (`role` + `content`) |
| `model` | string | No | Model ID (e.g., `gpt-4o`, `llama3.1:8b`). Falls back to user default. |
| `temperature` | number | No | Sampling temperature (0-2). Default varies by model. |
| `max_tokens` | integer | No | Maximum tokens to generate. |
| `stream` | boolean | No | If `true`, returns SSE stream. Default `false`. |

**Model Resolution:**

The `model` field is matched against Synaplan's model registry in this order:
1. Exact match on `providerId` (e.g., `gpt-4o`, `llama3.1:8b`)
2. Exact match on model `name`
3. Falls back to the user's default chat model

Use `GET /v1/models` to see available model IDs.

### `POST /v1/audio/transcriptions`

One-shot speech-to-text. Same shape as OpenAI Whisper so local tools and the
OpenAI SDK work against Synaplan. The `model` field is resolved against the
user's **SOUND2TEXT** catalogue (local whisper.cpp, Groq Whisper, OpenAI
`whisper-1`, Mistral Voxtral, …). Omit it to use the user's default.

**Request:** `multipart/form-data` (`file` or `audio`) or a raw `audio/*` /
`application/octet-stream` body.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `file` | file | Yes* | Audio to transcribe (`wav`, `webm`, `ogg`, `mp3`, `m4a`, `flac`, or raw PCM). |
| `model` | string | No | STT model id (see `GET /v1/audio/models`). |
| `language` | string | No | ISO-639-1 hint. |
| `prompt` | string | No | Optional spelling / vocabulary hint. |
| `client_id` | string | No | Caller-chosen client tag (see sessions below). |
| `response_format` | string | No | `json` (default), `text`, or `verbose_json`. |
| `stream` | boolean | No | If `true`, returns SSE (`transcript` then `done`). |

```bash
curl https://your-synaplan-instance.com/v1/audio/transcriptions \
  -H "Authorization: Bearer sk-your-synaplan-api-key" \
  -F file=@clip.wav \
  -F model=whisper \
  -F client_id=123
```

```json
{
  "text": "hello from the microphone",
  "id": "transcribe_…",
  "model": "whisper",
  "language": "en",
  "duration": 1.4,
  "client_id": "123",
  "api_key_id": 1
}
```

### Streaming sessions (`client_id` on one API key)

One API key can transcribe many independent live streams at once. Each session
has its own id and a caller-chosen `client_id`, so "client 123 on API key 1"
never mixes with "client 321 on API key 1".

```
POST   /v1/audio/transcriptions/sessions
GET    /v1/audio/transcriptions/sessions?client_id=123
GET    /v1/audio/transcriptions/sessions/{id}
POST   /v1/audio/transcriptions/sessions/{id}/audio
POST   /v1/audio/transcriptions/sessions/{id}/commit
GET    /v1/audio/transcriptions/sessions/{id}/stream
DELETE /v1/audio/transcriptions/sessions/{id}
GET    /v1/audio/models
```

**Create a session**

```bash
curl -X POST https://your-synaplan-instance.com/v1/audio/transcriptions/sessions \
  -H "Authorization: Bearer sk-your-synaplan-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "client_id": "123",
    "model": "whisper",
    "language": "en",
    "encoding": "pcm_s16le",
    "sample_rate": 16000,
    "channels": 1
  }'
```

```json
{
  "id": "stt_sess_…",
  "object": "transcription.session",
  "client_id": "123",
  "api_key_id": 1,
  "user_id": 42,
  "model": "whisper",
  "status": "open"
}
```

| Field | Description |
|-------|-------------|
| `client_id` | Your local client tag (`[A-Za-z0-9._:-]{1,64}`). Defaults to `default`. |
| `reuse` | If `true`, return the existing open session for this `client_id` instead of opening another. |
| `encoding` | `auto` (default), `pcm_s16le`, `wav`, `webm`, `ogg`, `mp3`, `m4a`, `flac`. Bare PCM is wrapped as 16-bit WAV. |
| `commit_after_bytes` | Auto-transcribe when the pending buffer reaches this size (default 96000 ≈ 3s of 16 kHz mono PCM). |

**Send audio** — raw body, multipart `file`/`audio`, or JSON `audio_base64`.
Add `commit=true` to transcribe immediately.

```bash
# raw PCM / WAV / WebM chunk
curl -X POST https://your-synaplan-instance.com/v1/audio/transcriptions/sessions/stt_sess_…/audio \
  -H "Authorization: Bearer sk-your-synaplan-api-key" \
  -H "Content-Type: application/octet-stream" \
  --data-binary @chunk.pcm

# one recorded window as 16 kHz mono PCM (add commit=true to transcribe now)
ffmpeg -i input.wav -ac 1 -ar 16000 -f s16le pipe:1 \
  | curl -X POST \
      -H "Authorization: Bearer sk-your-synaplan-api-key" \
      -H "Content-Type: application/octet-stream" \
      --data-binary @- \
      "https://your-synaplan-instance.com/v1/audio/transcriptions/sessions/stt_sess_…/audio?commit=true"
```

**Read transcripts**

- Poll `GET /v1/audio/transcriptions/sessions/{id}?cursor=0` — `text`, `segments`, and new `events`.
- Or SSE `GET /v1/audio/transcriptions/sessions/{id}/stream?cursor=0` (`Authorization: Bearer` or `?api_key=`).

Events: `session.created`, `transcript` (final window), `heartbeat`, `done`, `error`.

```python
import json, time, requests

BASE = "https://your-synaplan-instance.com"
KEY = "sk-your-synaplan-api-key"
headers = {"Authorization": f"Bearer {KEY}"}

a = requests.post(f"{BASE}/v1/audio/transcriptions/sessions",
                  headers=headers, json={"client_id": "123", "model": "whisper"}).json()
b = requests.post(f"{BASE}/v1/audio/transcriptions/sessions",
                  headers=headers, json={"client_id": "321", "model": "whisper"}).json()
# a["id"] and b["id"] are distinct; both carry api_key_id of this key.

with open("chunk.wav", "rb") as fh:
    requests.post(f"{BASE}/v1/audio/transcriptions/sessions/{a['id']}/audio",
                  headers={**headers, "Content-Type": "application/octet-stream"},
                  data=fh, params={"commit": "true"})

print(requests.get(f"{BASE}/v1/audio/transcriptions/sessions/{a['id']}",
                   headers=headers).json()["text"])
```

Sessions expire after two hours of inactivity (TTL refreshes on every write).
A key may have at most 32 open sessions. Pending audio is transcribed through
the same `AiFacade::transcribe()` path as chat uploads, so usage and SOUND2TEXT
model choice stay consistent.

### `GET /v1/models`

Returns all available models in OpenAI format.

**Response:**

```json
{
  "object": "list",
  "data": [
    {"id": "gpt-4o", "object": "model", "created": 1700000000, "owned_by": "openai"},
    {"id": "llama3.1:8b", "object": "model", "created": 1700000000, "owned_by": "ollama"}
  ]
}
```

## Authentication

Two methods are supported:

| Method | Header | Example |
|--------|--------|---------|
| Bearer token (OpenAI-standard) | `Authorization: Bearer sk-xxx` | Works on `/v1/` routes |
| API Key header (Synaplan-native) | `X-API-Key: sk-xxx` | Works everywhere |

Both use the same Synaplan API keys. Create keys at **Settings > API Keys** in the Synaplan UI.

### Scoped vs. legacy keys

Synaplan API keys can carry **scopes** that limit what a key may do. This does
**not** change existing keys: a key with no scopes (every key created before
scopes existed, and any key you create without choosing scopes) keeps **full
access**, exactly as before, and so does a webhook-only key. Your current
integrations are unaffected — nothing that worked yesterday stops working.

The only keys that are restricted today are the ones minted when a user pairs a
[Synaplan Desktop](./DESKTOP.md) computer: those are limited to a small
`desktop:*` scope set (chat, MCP, files, jobs) so a lost laptop can be revoked
without exposing the whole account. If you never pair a desktop computer, you
never see a scoped key.

## Error Format

Errors follow the OpenAI error format:

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

| HTTP Status | Meaning |
|-------------|---------|
| 400 | Bad request (invalid JSON, missing messages or audio) |
| 401 | Authentication required or invalid API key |
| 404 | Model or session not found |
| 409 | Transcription session is closed |
| 429 | Rate limit exceeded |
| 500 | Server error |

## Compatibility Notes

- **Synaplan routes to the correct AI provider automatically.** When you request `model: "gpt-4o"`, Synaplan uses the OpenAI provider. When you request `model: "llama3.1:8b"`, it uses Ollama. This is transparent to the caller.
- **Token usage** is not always reported (depends on the provider). The `usage` field may contain zeros.
- **Function calling / tools** are not yet supported through this endpoint.
- **Image inputs** (vision) are not yet supported through this endpoint.
- **Speech-to-text** is supported at `/v1/audio/transcriptions` (one-shot) and `/v1/audio/transcriptions/sessions` (streaming, with `client_id` + `api_key_id`).
- The existing Synaplan API at `/api/v1/` is unchanged and fully functional.

## Tools & Integrations

Any tool that supports custom OpenAI endpoints works with Synaplan:

- **Cursor IDE**: Settings > Models > OpenAI API Key + Base URL
- **Continue.dev**: Set `apiBase` in config
- **LangChain**: Use `ChatOpenAI(base_url="...")`
- **LlamaIndex**: Use `OpenAI(api_base="...")`
