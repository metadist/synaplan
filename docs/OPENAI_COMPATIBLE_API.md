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
| 400 | Bad request (invalid JSON, missing messages) |
| 401 | Authentication required or invalid API key |
| 404 | Model not found |
| 429 | Rate limit exceeded |
| 500 | Server error |

## Compatibility Notes

- **Synaplan routes to the correct AI provider automatically.** When you request `model: "gpt-4o"`, Synaplan uses the OpenAI provider. When you request `model: "llama3.1:8b"`, it uses Ollama. This is transparent to the caller.
- **Token usage** is not always reported (depends on the provider). The `usage` field may contain zeros.
- **Function calling / tools** are not yet supported through this endpoint.
- **Image inputs** (vision) are not yet supported through this endpoint.
- The existing Synaplan API at `/api/v1/` is unchanged and fully functional.

## Tools & Integrations

Any tool that supports custom OpenAI endpoints works with Synaplan:

- **Cursor IDE**: Settings > Models > OpenAI API Key + Base URL
- **Continue.dev**: Set `apiBase` in config
- **LangChain**: Use `ChatOpenAI(base_url="...")`
- **LlamaIndex**: Use `OpenAI(api_base="...")`
