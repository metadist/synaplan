# HuggingFace API Reference

> Technical reference for HuggingFace Inference Providers integration

## Single API Architecture

**One token, one endpoint, all providers.** HuggingFace acts as a unified proxy.

```
┌──────────────────────────────────────────────────────────────┐
│                    YOUR APPLICATION                          │
│                          │                                   │
│              HUGGINGFACE_API_KEY=hf_****                     │
└──────────────────────────────────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────┐
│              router.huggingface.co                           │
│              (Single Entry Point)                            │
│                                                              │
│   Automatic routing to: Fal AI, SambaNova, Groq, Together,   │
│   Replicate, Cerebras, Cohere, Fireworks, Hyperbolic, etc.   │
└──────────────────────────────────────────────────────────────┘
```

## Base URLs

| Service | URL |
|---------|-----|
| **Chat Completions (OpenAI-compatible)** | `https://router.huggingface.co/v1/chat/completions` |
| **Model List** | `https://router.huggingface.co/v1/models` |
| **Task-specific** | `https://router.huggingface.co/{provider}/{model}` |

## Authentication

**Single token for everything:**

```http
Authorization: Bearer hf_xxxxxxxxxxxxxxxxxxxx
```

Generate tokens at: https://huggingface.co/settings/tokens

Required permissions: `Make calls to Inference Providers` (fine-grained token)

**No other API keys needed.** This single token authenticates with all backend providers.

---

## Task Mapping: Synaplan → HuggingFace

| Synaplan Tag | HuggingFace Task | Endpoint Type |
|--------------|------------------|---------------|
| `chat` | `chat-completion` | OpenAI-compatible |
| `pic2text` | `image-text-to-text` | OpenAI-compatible (VLM) |
| `text2pic` | `text-to-image` | Native |
| `text2vid` | `text-to-video` | Native |
| `text2sound` | *Not available* | Use existing providers |
| `sound2text` | `automatic-speech-recognition` | Native |
| `vectorize` | `feature-extraction` | Native |
| `pic2pic` (NEW) | `image-to-image` | Native |
| `pic2vid` (NEW) | `image-to-video` | Native (limited) |

---

## Chat Completion API

### Request

```http
POST https://router.huggingface.co/v1/chat/completions
Authorization: Bearer hf_****
Content-Type: application/json
```

```json
{
  "model": "openai/gpt-oss-120b",
  "messages": [
    {"role": "system", "content": "You are a helpful assistant."},
    {"role": "user", "content": "Hello!"}
  ],
  "temperature": 0.7,
  "max_tokens": 4096,
  "stream": false
}
```

### Model Selection Suffixes

| Suffix | Description |
|--------|-------------|
| `:fastest` | Highest throughput provider |
| `:cheapest` | Lowest cost per output token |
| `:provider-name` | Specific provider (e.g., `:sambanova`, `:groq`) |

```json
{
  "model": "deepseek-ai/DeepSeek-R1:fastest"
}
```

### Response

```json
{
  "id": "chatcmpl-xxx",
  "object": "chat.completion",
  "created": 1707000000,
  "model": "openai/gpt-oss-120b",
  "choices": [
    {
      "index": 0,
      "message": {
        "role": "assistant",
        "content": "Hello! How can I help you today?"
      },
      "finish_reason": "stop"
    }
  ],
  "usage": {
    "prompt_tokens": 10,
    "completion_tokens": 15,
    "total_tokens": 25
  }
}
```

### Streaming

```json
{
  "model": "openai/gpt-oss-120b",
  "messages": [...],
  "stream": true,
  "stream_options": {
    "include_usage": true
  }
}
```

Response: Server-Sent Events (SSE)

```
data: {"id":"chatcmpl-xxx","choices":[{"delta":{"content":"Hello"}}]}

data: {"id":"chatcmpl-xxx","choices":[{"delta":{"content":"!"}}]}

data: [DONE]
```

---

## Vision (VLM) API

### Request

```json
{
  "model": "zai-org/GLM-4.5V:cohere",
  "messages": [
    {
      "role": "user",
      "content": [
        {"type": "text", "text": "Describe this image."},
        {
          "type": "image_url",
          "image_url": {
            "url": "https://example.com/image.jpg"
          }
        }
      ]
    }
  ]
}
```

**Image URL formats:**
- HTTPS URL: `https://example.com/image.jpg`
- Base64: `data:image/jpeg;base64,/9j/4AAQ...`

---

## Text-to-Image API

### Request

```http
POST https://router.huggingface.co/fal-ai/black-forest-labs/FLUX.1-dev
Authorization: Bearer hf_****
Content-Type: application/json
```

```json
{
  "inputs": "A serene lake surrounded by mountains at sunset",
  "parameters": {
    "width": 1024,
    "height": 1024,
    "guidance_scale": 7.5,
    "num_inference_steps": 30,
    "negative_prompt": "blurry, low quality",
    "seed": 42
  }
}
```

### Response

Binary image data (PNG/JPEG)

```
Content-Type: image/png
```

### Available Providers for text2image

| Provider | Models |
|----------|--------|
| fal-ai | FLUX.1-dev, FLUX.1-Krea-dev, Qwen-Image |
| hf-inference | FLUX.1-dev (limited) |
| nscale | FLUX.1-dev |
| replicate | SDXL, various LoRAs |
| together | FLUX.1, SDXL-Lightning |
| wavespeed | Fast generation models |

---

## Text-to-Video API

### Request

```http
POST https://router.huggingface.co/fal-ai/tencent/HunyuanVideo
Authorization: Bearer hf_****
Content-Type: application/json
```

```json
{
  "inputs": "A young man walking on the street",
  "parameters": {
    "num_frames": 48,
    "guidance_scale": 7.0,
    "num_inference_steps": 50,
    "negative_prompt": ["blurry", "low quality"],
    "seed": 42
  }
}
```

### Response

Binary video data (MP4)

### Recommended Models

| Model | Provider | Notes |
|-------|----------|-------|
| `tencent/HunyuanVideo` | fal-ai, novita | Consistent generation |
| `tencent/HunyuanVideo-1.5` | fal-ai | Latest version |
| `Lightricks/LTX-Video` | fal-ai, novita | High fidelity |
| `Lightricks/LTX-Video-0.9.8-13B-distilled` | fal-ai | Very fast |

---

## Image-to-Image API

### Request

```http
POST https://router.huggingface.co/fal-ai/black-forest-labs/FLUX.1-Kontext-dev
Authorization: Bearer hf_****
Content-Type: application/json
```

```json
{
  "inputs": "<base64-encoded-image>",
  "parameters": {
    "prompt": "Turn the cat into a tiger",
    "guidance_scale": 7.5,
    "num_inference_steps": 30,
    "target_size": {
      "width": 1024,
      "height": 1024
    }
  }
}
```

### Response

Binary image data

### Recommended Models

| Model | Use Case |
|-------|----------|
| `black-forest-labs/FLUX.1-Kontext-dev` | Powerful editing |
| `kontext-community/relighting-kontext-dev-lora-v3` | Re-lighting |
| `fal/Qwen-Image-Edit-2511-Multiple-Angles-LoRA` | Multi-angle edits |

---

## Feature Extraction (Embeddings) API

### Request

```http
POST https://router.huggingface.co/hf-inference/intfloat/multilingual-e5-large
Authorization: Bearer hf_****
Content-Type: application/json
```

```json
{
  "inputs": "Today is a sunny day and I will get some ice cream.",
  "normalize": true,
  "truncate": true,
  "truncation_direction": "right"
}
```

### Batch Request

```json
{
  "inputs": [
    "First document to embed",
    "Second document to embed",
    "Third document to embed"
  ],
  "normalize": true
}
```

### Response

```json
[
  [0.123, -0.456, 0.789, ...]
]
```

Or for batch:
```json
[
  [0.123, -0.456, ...],
  [0.234, -0.567, ...],
  [0.345, -0.678, ...]
]
```

### Recommended Models

| Model | Dimensions | Languages |
|-------|------------|-----------|
| `intfloat/multilingual-e5-large` | 1024 | 100+ |
| `thenlper/gte-large` | 1024 | English |
| `BAAI/bge-m3` | 1024 | Multilingual |

---

## Automatic Speech Recognition API

### Request

```http
POST https://router.huggingface.co/fal-ai/openai/whisper-large-v3
Authorization: Bearer hf_****
Content-Type: application/json
```

```json
{
  "inputs": "<base64-encoded-audio>",
  "parameters": {
    "return_timestamps": true,
    "generation_parameters": {
      "max_new_tokens": 4096,
      "temperature": 0.0
    }
  }
}
```

### Response

```json
{
  "text": "Hello, this is a transcription test.",
  "chunks": [
    {
      "text": "Hello, this is a transcription test.",
      "timestamp": [0.0, 3.5]
    }
  ]
}
```

---

## Tool Calling (Function Calling)

### Request

```json
{
  "model": "openai/gpt-oss-120b",
  "messages": [
    {"role": "user", "content": "What's the weather in Paris?"}
  ],
  "tools": [
    {
      "type": "function",
      "function": {
        "name": "get_weather",
        "description": "Get current weather for a location",
        "parameters": {
          "type": "object",
          "properties": {
            "location": {
              "type": "string",
              "description": "City name"
            }
          },
          "required": ["location"]
        }
      }
    }
  ],
  "tool_choice": "auto"
}
```

### Response with Tool Call

```json
{
  "choices": [
    {
      "message": {
        "role": "assistant",
        "tool_calls": [
          {
            "id": "call_xxx",
            "type": "function",
            "function": {
              "name": "get_weather",
              "arguments": "{\"location\": \"Paris\"}"
            }
          }
        ]
      },
      "finish_reason": "tool_calls"
    }
  ]
}
```

---

## JSON Mode / Structured Output

### Request

```json
{
  "model": "openai/gpt-oss-120b",
  "messages": [...],
  "response_format": {
    "type": "json_schema",
    "json_schema": {
      "name": "person",
      "strict": true,
      "schema": {
        "type": "object",
        "properties": {
          "name": {"type": "string"},
          "age": {"type": "integer"}
        },
        "required": ["name", "age"]
      }
    }
  }
}
```

---

## Error Handling

### Common Error Responses

```json
{
  "error": {
    "message": "Model not found",
    "type": "invalid_request_error",
    "code": "model_not_found"
  }
}
```

### Rate Limiting

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 60
```

### Provider Unavailable

```json
{
  "error": {
    "message": "Provider temporarily unavailable",
    "type": "provider_error",
    "code": "provider_unavailable"
  }
}
```

---

## PHP Implementation Examples

### Using Symfony HttpClient

```php
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HuggingFaceClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
    ) {}

    public function chatCompletion(array $messages, string $model, array $options = []): array
    {
        $response = $this->httpClient->request('POST',
            'https://router.huggingface.co/v1/chat/completions',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $options['temperature'] ?? 0.7,
                    'max_tokens' => $options['max_tokens'] ?? 4096,
                    'stream' => false,
                ],
            ]
        );

        return $response->toArray();
    }

    public function textToImage(string $prompt, string $model, array $options = []): string
    {
        $provider = $options['provider'] ?? 'fal-ai';

        $response = $this->httpClient->request('POST',
            "https://router.huggingface.co/{$provider}/{$model}",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'inputs' => $prompt,
                    'parameters' => [
                        'width' => $options['width'] ?? 1024,
                        'height' => $options['height'] ?? 1024,
                        'guidance_scale' => $options['guidance_scale'] ?? 7.5,
                    ],
                ],
            ]
        );

        // Returns raw image bytes
        return $response->getContent();
    }

    public function embed(string|array $texts, string $model): array
    {
        $response = $this->httpClient->request('POST',
            'https://router.huggingface.co/hf-inference/' . $model,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'inputs' => $texts,
                    'normalize' => true,
                ],
            ]
        );

        return $response->toArray();
    }
}
```

### Streaming Chat

```php
public function chatStream(array $messages, string $model, callable $onChunk): void
{
    $response = $this->httpClient->request('POST',
        'https://router.huggingface.co/v1/chat/completions',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
            ],
            'json' => [
                'model' => $model,
                'messages' => $messages,
                'stream' => true,
            ],
        ]
    );

    foreach ($this->httpClient->stream($response) as $chunk) {
        $content = $chunk->getContent();

        foreach (explode("\n", $content) as $line) {
            if (str_starts_with($line, 'data: ')) {
                $data = substr($line, 6);

                if ($data === '[DONE]') {
                    return;
                }

                $json = json_decode($data, true);
                $delta = $json['choices'][0]['delta']['content'] ?? '';

                if ($delta) {
                    $onChunk($delta);
                }
            }
        }
    }
}
```

---

## Provider-Specific Notes

### fal-ai
- Best for: text-to-image, text-to-video, image-to-image
- Very fast inference
- Good FLUX.1 support

### Groq (via HuggingFace)
- Best for: LLM chat
- Extremely fast inference
- Limited to specific models

### SambaNova
- Best for: LLM chat, embeddings
- High throughput
- Good for production workloads

### Together
- Best for: LLM chat, text-to-image
- Good model variety
- Competitive pricing

### Replicate
- Best for: text-to-image, text-to-video, speech-to-text
- Custom LoRA support
- Pay-per-prediction

---

## Rate Limits

HuggingFace Inference Providers inherits rate limits from underlying providers:

| Account Type | Limits |
|--------------|--------|
| Free | ~10 requests/minute |
| PRO | Provider-dependent |
| Enterprise | Configurable per org |

Implement exponential backoff for production:

```php
public function withRetry(callable $request, int $maxRetries = 3): mixed
{
    $attempt = 0;

    while (true) {
        try {
            return $request();
        } catch (HttpException $e) {
            if ($e->getCode() !== 429 || $attempt >= $maxRetries) {
                throw $e;
            }

            $retryAfter = $e->getHeaders()['retry-after'][0] ?? (2 ** $attempt);
            sleep((int) $retryAfter);
            $attempt++;
        }
    }
}
```
