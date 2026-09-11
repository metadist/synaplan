<?php

declare(strict_types=1);

namespace App\AI\Messages\Translator;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;

/**
 * Chat Completions hosts the Messages gateway can translate to.
 *
 * Desktop (and Claude Code aliases) send Anthropic-shaped `/v1/messages`.
 * Every catalog chat model that already speaks OpenAI Chat Completions is
 * routed through {@see OpenAiMessagesTranslator} — not only `openai`.
 * Anthropic and Gemini are intentionally absent: Claude Code stays on
 * {@see AnthropicPassthroughTranslator} / {@see GeminiMessagesTranslator}.
 */
final class ChatCompletionsUpstreams
{
    /**
     * Fixed cloud hosts. Value is the full `…/chat/completions` URL.
     *
     * @var array<string, string>
     */
    public const URLS = [
        'openai' => 'https://api.openai.com/v1/chat/completions',
        'groq' => 'https://api.groq.com/openai/v1/chat/completions',
        'mistral' => 'https://api.mistral.ai/v1/chat/completions',
        'xai' => 'https://api.x.ai/v1/chat/completions',
        'huggingface' => 'https://router.huggingface.co/v1/chat/completions',
        'trustedtokens' => 'https://api.trustedtokens.eu/v1/chat/completions',
        'a2agent' => 'https://a2agent.me/v1/chat/completions',
        'perplexity' => 'https://api.perplexity.ai/chat/completions',
    ];

    /**
     * Local / install-configured hosts: no ProviderKeyStore entry required.
     *
     * @var list<string>
     */
    public const LOCAL_PROVIDERS = ['ollama', OpenAiCompatibleEndpointRegistry::PROVIDER_NAME];

    public static function supports(string $provider): bool
    {
        $provider = strtolower($provider);

        return isset(self::URLS[$provider]) || self::isLocal($provider);
    }

    public static function isLocal(string $provider): bool
    {
        return \in_array(strtolower($provider), self::LOCAL_PROVIDERS, true);
    }

    public static function needsProviderKey(string $provider): bool
    {
        return !self::isLocal($provider);
    }

    public static function fixedUrl(string $provider): ?string
    {
        return self::URLS[strtolower($provider)] ?? null;
    }
}
