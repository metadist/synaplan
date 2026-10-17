<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig\Tab;

use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The AI Services tab: the local Ollama server, the cloud provider keys,
 * self-hosted Triton, image and video providers, Cloudflare embeddings and
 * text-to-speech.
 *
 * Every key whose env var ProviderKeyCatalog knows is stored encrypted in
 * BCONFIG by ProviderKeyStore and applies without a restart; the schema marks
 * it `managedBy`, so its status comes from the store and a write here is
 * refused with a pointer to Providers & keys.
 */
#[AsTaggedItem(priority: 130)]
final readonly class AiTab implements SystemConfigTab
{
    public function __construct(
        #[Autowire('%synaplan_tts_url_default%')]
        private string $defaultTtsUrl,
    ) {
    }

    public function id(): string
    {
        return 'ai';
    }

    public function label(): string
    {
        return 'AI Services';
    }

    public function sections(): array
    {
        return [
            'ollama' => $this->ollama(),
            'cloud' => $this->cloud(),
            'selfhosted' => $this->selfhosted(),
            'media' => $this->media(),
            'embeddings' => $this->embeddings(),
            'tts' => $this->tts(),
        ];
    }

    private function ollama(): ConfigSection
    {
        return new ConfigSection('Local AI (Ollama)', [
            'OLLAMA_BASE_URL' => new FieldDefinition(
                type: 'url',
                description: 'Ollama server URL',
                default: 'http://ollama:11434',
            ),
        ]);
    }

    private function cloud(): ConfigSection
    {
        return new ConfigSection('Cloud AI Providers', [
            'OPENAI_API_KEY' => new FieldDefinition(
                type: 'password',
                description: 'OpenAI API key',
                sensitive: true,
            ),
            'ANTHROPIC_API_KEY' => new FieldDefinition(
                type: 'password',
                description: 'Anthropic (Claude) API key',
                sensitive: true,
            ),
            'GROQ_API_KEY' => new FieldDefinition(
                type: 'password',
                description: 'Groq API key (free tier available)',
                sensitive: true,
            ),
            'GOOGLE_GEMINI_API_KEY' => new FieldDefinition(
                type: 'password',
                description: 'Google Gemini API key — also unlocks Imagen, Nano Banana, Veo and Gemini TTS',
                sensitive: true,
            ),
            'MISTRAL_API_KEY' => new FieldDefinition(
                type: 'password',
                description: 'Mistral API key — chat, vision and the Voxtral audio pair',
                sensitive: true,
            ),
            'XAI_API_KEY' => new FieldDefinition(
                type: 'password',
                description: 'xAI (Grok) API key — chat, image understanding, Grok Imagine media, and Grok voice',
                sensitive: true,
            ),
            'META_API_KEY' => new FieldDefinition(
                type: 'password',
                description: 'Meta Model API key — Muse Spark, Muse Wonder, Muse Image, and Muse Voice Transcribe. Create it at https://dev.meta.ai/ (API keys)',
                sensitive: true,
            ),
            'CEREBRAS_API_KEY' => new FieldDefinition(
                type: 'password',
                description: 'Cerebras Inference API key — GPT OSS 120B and Qwen 3.8 27B. Create it at https://cloud.cerebras.ai/ (API Keys)',
                sensitive: true,
            ),
            'TRUSTEDTOKENS_API_KEY' => new FieldDefinition(
                type: 'password',
                description: 'TrustedTokens API key — sovereign inference on German GPUs (GLM, DeepSeek, Qwen, GPT OSS)',
                sensitive: true,
            ),
            'A2AGENT_API_KEY' => new FieldDefinition(
                type: 'password',
                description: 'A2Agent API key — Qwen, DeepSeek and MiniMax via the A2Agent gateway (Chinese model vendors)',
                sensitive: true,
            ),
            'HUGGINGFACE_API_KEY' => new FieldDefinition(
                type: 'password',
                description: 'HuggingFace API token — routes the Kimi models through HF Inference',
                sensitive: true,
            ),
            'GOOGLE_VERTEX_ACCESS_TOKEN' => new FieldDefinition(
                type: 'password',
                description: 'Optional OAuth bearer for Vertex AI Imagen; leave empty to use Gemini API (Imagen 4) with the key above',
                sensitive: true,
            ),
        ]);
    }

    private function selfhosted(): ConfigSection
    {
        return new ConfigSection('Self-Hosted AI', [
            'TRITON_SERVER_URL' => new FieldDefinition(
                type: 'url',
                description: 'NVIDIA Triton gRPC endpoint',
            ),
        ]);
    }

    private function media(): ConfigSection
    {
        return new ConfigSection('Image & Video Generation', [
            'THEHIVE_API_KEY' => new FieldDefinition(
                type: 'password',
                description: 'TheHive API key — Flux Schnell and SDXL image generation',
                sensitive: true,
            ),
            'HIGGSFIELD_API_KEY' => new FieldDefinition(
                type: 'password',
                description: 'Higgsfield API key — Soul/Reve images, DoP and Kling video. Both halves are required',
                sensitive: true,
            ),
            'HIGGSFIELD_API_SECRET' => new FieldDefinition(
                type: 'password',
                description: 'Higgsfield API secret — the key alone will not authenticate',
                sensitive: true,
            ),
        ]);
    }

    private function embeddings(): ConfigSection
    {
        return new ConfigSection('Embeddings (Cloudflare Workers AI)', [
            'CLOUDFLARE_ACCOUNT_ID' => new FieldDefinition(
                type: 'text',
                description: 'Cloudflare account ID for Workers AI embeddings (bge-m3)',
            ),
            'CLOUDFLARE_API_TOKEN' => new FieldDefinition(
                type: 'password',
                description: 'Cloudflare API token with Workers AI access',
                sensitive: true,
            ),
            'EMBEDDING_FALLBACK_PROVIDER' => new FieldDefinition(
                type: 'text',
                description: 'Provider to try when the primary embedding fails (e.g. "cloudflare"); empty disables fallback',
            ),
        ]);
    }

    private function tts(): ConfigSection
    {
        return new ConfigSection('Text-to-Speech', [
            'SYNAPLAN_TTS_URL' => new FieldDefinition(
                type: 'url',
                description: 'Synaplan TTS service URL (self-hosted, Piper-based)',
                default: $this->defaultTtsUrl,
            ),
            'ELEVENLABS_API_KEY' => new FieldDefinition(
                type: 'password',
                description: 'ElevenLabs TTS API key',
                sensitive: true,
            ),
        ]);
    }
}
