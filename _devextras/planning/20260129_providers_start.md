# Synaplan AI Providers Analysis

**Date:** 2026-01-29  
**Purpose:** Document current state of supported AI providers

---

## Supported AI Providers (6 production + 1 test)

### 1. OpenAI
- **Location:** `backend/src/AI/Provider/OpenAIProvider.php`
- **Environment Variable:** `OPENAI_API_KEY`
- **Capabilities:**
  - **Chat:** GPT-4, GPT-5, o1, o3 (reasoning models), streaming and non-streaming
  - **Embeddings:** text-embedding-3-small, text-embedding-3-large, text-embedding-ada-002
  - **Vision:** GPT-4o image analysis
  - **Image Generation:** DALL-E 3, gpt-image-1
  - **Speech-to-Text:** Whisper
  - **Text-to-Speech:** TTS models with multiple voices

### 2. Google (Gemini API)
- **Location:** `backend/src/AI/Provider/GoogleProvider.php`
- **Environment Variables:** `GOOGLE_GEMINI_API_KEY` (required), `GOOGLE_CLOUD_PROJECT_ID` (optional, for Imagen)
- **API Type:** 
  - **Standard Gemini API** (`generativelanguage.googleapis.com`) for most operations
  - **Vertex AI** (`{region}-aiplatform.googleapis.com`) only for Imagen 3.0 image generation
- **Capabilities:**
  - **Chat:** Gemini 2.0 Flash, Gemini 2.5 Pro
  - **Vision:** Multimodal Gemini models
  - **Image Generation:** Imagen 3.0 (Vertex AI), Gemini native (gemini-2.5-flash-image)
  - **Video Generation:** Veo 2.0/3.1
  - **Text-to-Speech:** Gemini 2.5 Flash/Pro TTS models

### 3. Anthropic (Claude)
- **Location:** `backend/src/AI/Provider/AnthropicProvider.php`
- **Environment Variable:** `ANTHROPIC_API_KEY`
- **Capabilities:**
  - **Chat:** Claude 3.5 Sonnet, Claude Sonnet 4, Claude Opus 4 (streaming and non-streaming)
  - Extended Thinking support
  - **Vision:** Image analysis with Claude models

### 4. Groq
- **Location:** `backend/src/AI/Provider/GroqProvider.php`
- **Environment Variable:** `GROQ_API_KEY`
- **Capabilities:**
  - **Chat:** OpenAI-compatible API, fast inference
  - **Vision:** llama-4-scout, llama-4-maverick vision models

### 5. Ollama (Local/Self-hosted)
- **Location:** `backend/src/AI/Provider/OllamaProvider.php`
- **Environment Variable:** `OLLAMA_BASE_URL` (e.g., `http://ollama:11434`)
- **Capabilities:**
  - **Chat:** Local models via Ollama server
  - **Embeddings:** Local embedding models (bge-m3, nomic-embed-text)

### 6. NVIDIA Triton Inference Server
- **Location:** `backend/src/AI/Provider/TritonProvider.php`
- **Environment Variable:** `TRITON_SERVER_URL` (optional, e.g., `triton-server:8001`)
- **Capabilities:**
  - **Chat:** gRPC-based high-performance inference
  - For self-hosted/enterprise deployments

### 7. TestProvider (Mock/Development)
- **Location:** `backend/src/AI/Provider/TestProvider.php`
- **Purpose:** Mock provider for testing and development
- **Capabilities:** All capabilities (chat, embeddings, vision, image generation, STT, TTS, file analysis)

---

## Capabilities Matrix

| Provider | Chat | Embeddings | Vision | Image Gen | Video Gen | STT | TTS |
|----------|:----:|:----------:|:------:|:---------:|:---------:|:---:|:---:|
| OpenAI | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
| Google | ✅ | ❌ | ✅ | ✅ | ✅ | ❌ | ✅ |
| Anthropic | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Groq | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ |
| Ollama | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Triton | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

---

## Provider Registry System

All providers are registered via `ProviderRegistry` class:
- **Location:** `backend/src/AI/Service/ProviderRegistry.php`
- Uses Symfony's tagged iterator pattern
- Supported capability tags: `chat`, `embedding`, `vision`, `image_generation`, `video_generation`, `speech_to_text`, `text_to_speech`, `file_analysis`
- Checks database-driven capabilities (BMODELS.BTAG) to enable/disable provider features per user
- Provides fallback logic for vision providers

---

## Notes

- **No "TheHive" provider exists** - Image generation is handled by OpenAI (DALL-E 3, gpt-image-1) and Google (Imagen 3.0, Gemini native)
- Google primarily uses standard Gemini API; Vertex AI is only required for Imagen image generation
- Ollama and Triton support self-hosted/local deployments for privacy-conscious users

---

## Future Considerations

Potential providers to evaluate for future integration:
- [ ] Mistral AI
- [ ] Cohere
- [ ] Replicate (various image/video models)
- [ ] Stability AI (Stable Diffusion)
- [ ] ElevenLabs (TTS)
- [ ] Deepgram (STT)
