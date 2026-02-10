# HuggingFace Integration Plan

> **Status**: Planning
> **Created**: 2026-02-04
> **Priority**: High
> **Dependencies**: None (new provider)

## Executive Summary

Integrate HuggingFace's Inference Providers API to expand Synaplan's AI capabilities with access to **200k+ models** from 18+ inference providers through a **single unified API and single API token**. This enables new features like **image-to-video**, **image-to-image editing**, and access to cutting-edge open-source models.

## Key Simplification: Single API Token

**You only need ONE HuggingFace API token.** HuggingFace acts as a proxy/router to all backend providers:

```
HUGGINGFACE_API_KEY=hf_xxxx  →  Routes to: Fal AI, SambaNova, Groq, Together, Replicate, etc.
```

**No separate accounts needed** for any backend provider. All billing goes through your HuggingFace account.

### Setup Requirements

1. HuggingFace account (free or PRO)
2. API token from https://huggingface.co/settings/tokens (with "Inference Providers" permission)
3. Add to `.env`: `HUGGINGFACE_API_KEY=hf_your_token_here`

### Pricing (Single Bill)

| Account | Monthly Credits | Pay-as-you-go |
|---------|----------------|---------------|
| Free | $0.10 | No |
| PRO ($9/mo) | $2.00 | Yes |
| Enterprise | $2.00/seat | Yes |

All provider costs are passed through at cost - no HuggingFace markup.

### Feature Availability

| Task | Provider | Free Tier | Notes |
|------|----------|-----------|-------|
| **Chat (LLM)** | OpenAI-compatible | ✅ Yes | DeepSeek R1, Qwen via `router.huggingface.co/v1` |
| **Embeddings** | hf-inference | ✅ Yes | E5 Large (1024 dims) via `hf-inference/models/` |
| **Image Generation** | hf-inference | ✅ Yes | Stable Diffusion XL via `hf-inference/models/` |
| **Video Generation** | fal-ai | ⚠️ Credits | LTX-Video via `fal-ai/fal-ai/ltx-video` |

**Note:** Video generation requires HuggingFace prepaid credits (~$0.25/video).
Add credits at: https://huggingface.co/settings/billing

### API Endpoint Formats

Different providers use different URL formats:

```plaintext
# Chat (OpenAI-compatible)
POST https://router.huggingface.co/v1/chat/completions
Body: { "model": "deepseek-ai/DeepSeek-R1:fastest", "messages": [...] }

# Embeddings & Images (hf-inference)
POST https://router.huggingface.co/hf-inference/models/{owner}/{model}
Body: { "inputs": "..." }

# Video (fal-ai)
POST https://router.huggingface.co/fal-ai/fal-ai/{model-name}
Body: { "prompt": "...", "num_frames": 65 }
```

## HuggingFace Inference Options

### Option 1: Inference Providers (Recommended)

A unified proxy API that routes requests to multiple backend providers:

| Provider | Chat (LLM) | Chat (VLM) | Embeddings | Text2Image | Text2Video | Sound2Text |
|----------|:----------:|:----------:|:----------:|:----------:|:----------:|:----------:|
| Cerebras | ✓ | | | | | |
| Cohere | ✓ | ✓ | | | | |
| Fal AI | | | | ✓ | ✓ | ✓ |
| Featherless AI | ✓ | ✓ | | | | |
| Fireworks | ✓ | ✓ | | | | |
| Groq | ✓ | ✓ | | | | |
| HF Inference | ✓ | ✓ | ✓ | ✓ | | ✓ |
| Hyperbolic | ✓ | ✓ | | | | |
| Novita | ✓ | ✓ | | | ✓ | |
| Nscale | ✓ | ✓ | | ✓ | | |
| Replicate | | | | ✓ | ✓ | ✓ |
| SambaNova | ✓ | | ✓ | | | |
| Together | ✓ | ✓ | | ✓ | | |
| WaveSpeedAI | | | | ✓ | ✓ | |

**Pros:**
- Single API token for all providers
- Automatic provider selection (`:fastest`, `:cheapest`)
- OpenAI-compatible chat endpoint (drop-in replacement)
- No markup on provider costs
- Monthly free credits ($0.10 free, $2.00 PRO)
- Failover between providers

**Cons:**
- Less control over specific provider features
- Some tasks limited to specific providers

### Option 2: Inference Endpoints (Dedicated)

Deploy custom models on dedicated GPU infrastructure.

**Use Case:** Custom fine-tuned models, high-volume production, guaranteed availability.

**Not recommended for initial integration** - higher complexity, higher cost.

---

## Current Synaplan Architecture

### Existing Services (BMODELS.BSERVICE)
- `Ollama` - Local inference
- `Groq` - Cloud inference
- `OpenAI` - OpenAI API
- `Google` - Gemini API
- `Anthropic` - Claude API

### Existing Capabilities (BMODELS.BTAG)
| Tag | Description | Current Providers |
|-----|-------------|-------------------|
| `chat` | Text chat/completion | Ollama, Groq, OpenAI, Google, Anthropic |
| `pic2text` | Image analysis/OCR | Groq, OpenAI, Google, Anthropic |
| `text2pic` | Image generation | OpenAI |
| `text2vid` | Video generation | Google (Veo 3.1) |
| `text2sound` | Text-to-speech | OpenAI, Google |
| `sound2text` | Speech-to-text | Groq, OpenAI |
| `vectorize` | Text embeddings | Ollama, OpenAI |

### Provider Architecture
```
AiFacade (Entry Point)
    ├── ModelConfigService (Model/Provider Selection)
    ├── ProviderRegistry (Auto-discovery via tags)
    └── Provider Interfaces:
        ├── ChatProviderInterface
        ├── VisionProviderInterface
        ├── EmbeddingProviderInterface
        ├── ImageGenerationProviderInterface
        ├── VideoGenerationProviderInterface
        ├── SpeechToTextProviderInterface
        └── TextToSpeechProviderInterface
```

---

## Integration Strategy

### Phase 1: Core Provider Implementation

Create a new `HuggingFaceProvider` that implements multiple capability interfaces.

#### New Files
```
backend/src/AI/Provider/HuggingFaceProvider.php
backend/src/AI/Client/HuggingFaceClient.php (optional helper)
```

#### Interface Implementation
```php
final readonly class HuggingFaceProvider implements
    ChatProviderInterface,
    VisionProviderInterface,
    EmbeddingProviderInterface,
    ImageGenerationProviderInterface,
    VideoGenerationProviderInterface,
    SpeechToTextProviderInterface
{
    public function getName(): string { return 'huggingface'; }
    public function getDisplayName(): string { return 'HuggingFace'; }

    // Use OpenAI-compatible endpoint for chat
    public function chat(array $messages, array $options = []): string { ... }
    public function chatStream(array $messages, callable $callback, array $options = []): void { ... }

    // Use native HuggingFace client for other tasks
    public function generateImage(string $prompt, array $options = []): string { ... }
    public function generateVideo(string $prompt, array $options = []): string { ... }
    // ...
}
```

#### API Endpoints

**Chat Completion (OpenAI-compatible):**
```
POST https://router.huggingface.co/v1/chat/completions
Authorization: Bearer hf_****
```

**Other Tasks:**
```
POST https://router.huggingface.co/models/{model-id}
Authorization: Bearer hf_****
```

### Phase 2: New Capabilities

#### Add New Tags to BMODELS

| New Tag | Description | HF Task |
|---------|-------------|---------|
| `pic2vid` | Image to Video | `image-to-video` |
| `pic2pic` | Image to Image (editing) | `image-to-image` |

#### Interface Additions

```php
// New interface for Image-to-Video
interface ImageToVideoProviderInterface extends ProviderMetadataInterface
{
    public function imageToVideo(string $imagePath, string $prompt, array $options = []): string;
}

// New interface for Image-to-Image
interface ImageToImageProviderInterface extends ProviderMetadataInterface
{
    public function editImage(string $imagePath, string $prompt, array $options = []): string;
}
```

### Phase 3: Model Configuration (BMODELS.sql)

We will add 5 high-value models from HuggingFace to the `BMODELS` table. These models introduce new capabilities or offer significant performance/cost benefits.

**Provider Name (BSERVICE)**: `HuggingFace`

```sql
-- 1. DeepSeek R1 (Reasoning LLM) - Top-tier reasoning model
INSERT INTO BMODELS (BSERVICE, BNAME, BTAG, BSELECTABLE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BACTIVE, BJSON) VALUES
('HuggingFace', 'DeepSeek R1', 'chat', 1, 'deepseek-ai/DeepSeek-R1', 0.55, 'per1M', 2.19, 'per1M', 10, 1, 0, 1, '{"description":"DeepSeek R1 reasoning model via HuggingFace. Excellent for logic, math, and coding.","params":{"model":"deepseek-ai/DeepSeek-R1","provider_strategy":"fastest"},"features":["reasoning"]}');

-- 2. FLUX.1 Dev (Text-to-Image) - State-of-the-art open image generation
INSERT INTO BMODELS (BSERVICE, BNAME, BTAG, BSELECTABLE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BACTIVE, BJSON) VALUES
('HuggingFace', 'FLUX.1 Dev', 'text2pic', 1, 'black-forest-labs/FLUX.1-dev', 0, '-', 0.035, 'perpic', 10, 1, 0, 1, '{"description":"FLUX.1 Dev - State-of-the-art image generation with excellent prompt adherence.","params":{"model":"black-forest-labs/FLUX.1-dev"}}');

-- 3. HunyuanVideo (Text-to-Video) - Leading open video generation
INSERT INTO BMODELS (BSERVICE, BNAME, BTAG, BSELECTABLE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BACTIVE, BJSON) VALUES
('HuggingFace', 'HunyuanVideo', 'text2vid', 1, 'tencent/HunyuanVideo', 0, '-', 0.50, 'pervid', 10, 1, 0, 1, '{"description":"Tencent HunyuanVideo - Consistent and high-quality video generation (5s-10s).","params":{"model":"tencent/HunyuanVideo"}}');

-- 4. FLUX.1 Kontext (Image-to-Image) - NEW CAPABILITY: pic2pic
INSERT INTO BMODELS (BSERVICE, BNAME, BTAG, BSELECTABLE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BACTIVE, BJSON) VALUES
('HuggingFace', 'FLUX.1 Kontext', 'pic2pic', 1, 'black-forest-labs/FLUX.1-Kontext-dev', 0, '-', 0.04, 'perpic', 10, 1, 1, 1, '{"description":"FLUX Kontext - Powerful AI image editing and transformation.","params":{"model":"black-forest-labs/FLUX.1-Kontext-dev"}}');

-- 5. Qwen2.5 Coder 32B (Coding LLM) - Specialized for code
INSERT INTO BMODELS (BSERVICE, BNAME, BTAG, BSELECTABLE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BACTIVE, BJSON) VALUES
('HuggingFace', 'Qwen2.5 Coder 32B', 'chat', 1, 'Qwen/Qwen2.5-Coder-32B-Instruct', 0.20, 'per1M', 0.80, 'per1M', 9, 1, 0, 1, '{"description":"Qwen2.5 Coder - Specialized model for code generation and debugging.","params":{"model":"Qwen/Qwen2.5-Coder-32B-Instruct"}}');
```

---

## Implementation Details

### Authentication (Single Token)

```php
// ONE environment variable - that's it!
HUGGINGFACE_API_KEY=hf_****

// All requests use this single token
Authorization: Bearer hf_****
```

**No need for:**
- ❌ `FAL_API_KEY`
- ❌ `SAMBANOVA_API_KEY`
- ❌ `REPLICATE_API_TOKEN`
- ❌ `TOGETHER_API_KEY`
- ❌ Any other provider keys

HuggingFace handles all provider authentication internally.

### Provider Selection Strategies

HuggingFace automatically routes to the best provider, or you can control it:

```php
// Auto (default) - first available provider based on your HF settings
$model = 'openai/gpt-oss-120b';

// Fastest - highest throughput (HF picks the fastest provider)
$model = 'openai/gpt-oss-120b:fastest';

// Cheapest - lowest cost per token (HF picks the cheapest provider)
$model = 'openai/gpt-oss-120b:cheapest';

// Specific provider (if you want to force one)
$model = 'openai/gpt-oss-120b:sambanova';
```

**Recommended**: Use `:fastest` or `:cheapest` and let HuggingFace optimize routing.

**Implementation in BJSON:**
```json
{
  "params": {
    "model": "openai/gpt-oss-120b",
    "provider_strategy": "fastest"
  }
}
```

### Chat Implementation (OpenAI-compatible)

```php
public function chat(array $messages, array $options = []): string
{
    $model = $options['model'] ?? 'openai/gpt-oss-120b';
    $strategy = $options['provider_strategy'] ?? null;

    if ($strategy) {
        $model .= ':' . $strategy;
    }

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

    $data = json_decode($response->getContent(), true);
    return $data['choices'][0]['message']['content'];
}
```

### Text-to-Image Implementation

```php
public function generateImage(string $prompt, array $options = []): string
{
    $model = $options['model'] ?? 'black-forest-labs/FLUX.1-dev';
    $provider = $options['provider'] ?? 'fal-ai';

    // Use huggingface_hub-style endpoint
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
                    'num_inference_steps' => $options['steps'] ?? 30,
                ],
            ],
        ]
    );

    // Response is raw image bytes
    return $this->saveImage($response->getContent());
}
```

### Text-to-Video Implementation

```php
public function generateVideo(string $prompt, array $options = []): string
{
    $model = $options['model'] ?? 'tencent/HunyuanVideo';

    $response = $this->httpClient->request('POST',
        'https://router.huggingface.co/fal-ai/' . $model,
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'inputs' => $prompt,
                'parameters' => [
                    'num_frames' => $options['frames'] ?? 48,
                    'guidance_scale' => $options['guidance_scale'] ?? 7.0,
                ],
            ],
        ]
    );

    return $this->saveVideo($response->getContent());
}
```

---

## Service Registration

### services.yaml

```yaml
App\AI\Provider\HuggingFaceProvider:
    arguments:
        $apiKey: '%env(HUGGINGFACE_API_KEY)%'  # Single token!
        $httpClient: '@http_client'
        $uploadDir: '%kernel.project_dir%/var/uploads'
    tags:
        - { name: 'app.ai.chat' }
        - { name: 'app.ai.vision' }
        - { name: 'app.ai.embedding' }
        - { name: 'app.ai.image_generation' }
        - { name: 'app.ai.video_generation' }
        - { name: 'app.ai.speech_to_text' }
        - { name: 'app.ai.image_to_image' }
        - { name: 'app.ai.image_to_video' }
```

### Environment Configuration

```env
# .env.example - ONLY ONE KEY NEEDED
###> HuggingFace Inference Providers ###
# Get your token from: https://huggingface.co/settings/tokens
# Required permission: "Make calls to Inference Providers"
HUGGINGFACE_API_KEY=hf_your_token_here
###< HuggingFace Inference Providers ###
```

**That's it!** One token unlocks access to:
- 200k+ models
- 18+ inference providers
- All task types (chat, vision, image gen, video gen, embeddings, STT)

---

## Pricing & Cost Management

### HuggingFace Pricing Model
- **No markup** from HuggingFace on provider costs
- **Free tier**: $0.10/month (free accounts), $2.00/month (PRO)
- **Pay-as-you-go** for PRO users after credits

### Cost Tracking
Store provider-reported costs in BJSON for transparency:
```json
{
  "pricing": {
    "source": "provider_passthrough",
    "updated": "2026-02-04"
  }
}
```

---

## Migration & Compatibility

### TheHive Integration
HuggingFace can complement or partially replace TheHive for:
- Text-to-Image: FLUX.1, SDXL Lightning
- Text-to-Video: HunyuanVideo, LTX-Video

**Keep TheHive for:**
- Specialized LoRA models
- Custom pipelines

### Groq Models via HuggingFace
Several Groq models are also available via HuggingFace:
- `openai/gpt-oss-120b:groq` (GPT-OSS via Groq hardware)

Consider using HuggingFace as a **unified fallback** for Groq rate limits.

---

## Testing Plan

### Unit Tests
```php
class HuggingFaceProviderTest extends TestCase
{
    public function testChatCompletion(): void { ... }
    public function testChatWithVision(): void { ... }
    public function testImageGeneration(): void { ... }
    public function testVideoGeneration(): void { ... }
    public function testEmbedding(): void { ... }
    public function testProviderSelection(): void { ... }
}
```

### Integration Tests
1. Verify API key authentication
2. Test provider selection strategies (`:fastest`, `:cheapest`)
3. Test fallback behavior when provider unavailable
4. Verify cost tracking accuracy

---

## Rollout Plan

### Step 1: Foundation
- [ ] Create `HuggingFaceProvider` class
- [ ] Implement `ChatProviderInterface`
- [ ] Add environment configuration
- [ ] Register in services.yaml

### Step 2: Chat & Vision
- [ ] Implement OpenAI-compatible chat endpoint
- [ ] Add VLM support for pic2text
- [ ] Add HuggingFace chat models to BMODELS
- [ ] Test streaming support

### Step 3: Image Generation
- [ ] Implement `ImageGenerationProviderInterface`
- [ ] Add FLUX.1, SDXL models
- [ ] Test image quality and parameters

### Step 4: Video Generation
- [ ] Implement `VideoGenerationProviderInterface`
- [ ] Add HunyuanVideo, LTX-Video models
- [ ] Handle async video generation if needed

### Step 5: New Capabilities
- [ ] Add `ImageToImageProviderInterface`
- [ ] Add `ImageToVideoProviderInterface`
- [ ] Add `pic2pic`, `pic2vid` tags
- [ ] Create AiFacade methods for new capabilities

### Step 6: Embeddings & STT
- [ ] Implement `EmbeddingProviderInterface`
- [ ] Implement `SpeechToTextProviderInterface`
- [ ] Add multilingual embedding models

### Step 7: Admin UI
- [ ] Add HuggingFace API key field in settings
- [ ] Add provider selection strategy option
- [ ] Display HuggingFace models in model picker

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Provider availability | High | Implement fallback to direct provider APIs |
| Rate limiting | Medium | Use multiple provider strategies |
| Cost spikes | Medium | Implement usage monitoring, alerts |
| API changes | Low | Version lock HuggingFace client |

---

## Related Documents

- [TheHive Integration Plan](./thehive-integration-plan.md)
- [Provider Architecture](./plugin-architecture.md)
- [Admin AI Models Changelog](./admin-ai-models-changelog.md)

---

## References

- [HuggingFace Inference Providers Docs](https://huggingface.co/docs/inference-providers/index)
- [HuggingFace Inference Endpoints Docs](https://huggingface.co/docs/inference-endpoints/index)
- [HuggingFace Hub Python Library](https://huggingface.co/docs/huggingface_hub/guides/inference)
- [HuggingFace.js Documentation](https://huggingface.co/docs/huggingface.js/index)
- [API Playground](https://huggingface.co/playground)
