<?php

declare(strict_types=1);

namespace App\AI\Messages\Translator;

use App\AI\Catalog\ProviderCatalog;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chat Completions hosts the Messages gateway can translate to.
 *
 * Desktop (and Claude Code aliases) send Anthropic-shaped `/v1/messages`.
 * Every catalog chat model whose provider descriptor declares a Chat
 * Completions endpoint is routed through {@see OpenAiMessagesTranslator} —
 * fixed cloud URLs from the descriptor, Ollama from `OLLAMA_BASE_URL`, and
 * admin-registered OpenAI-compatible endpoints from the registry. Anthropic
 * and Gemini are intentionally absent: Claude Code stays on
 * {@see AnthropicPassthroughTranslator} / {@see GeminiMessagesTranslator}.
 */
final readonly class ChatCompletionsUpstreams
{
    private const DEFAULT_UPSTREAM = 'https://api.openai.com';

    public function __construct(
        private ProviderCatalog $catalog,
        #[Autowire('%env(string:default::OLLAMA_BASE_URL)%')]
        private string $ollamaBaseUrl = '',
        private ?OpenAiCompatibleEndpointRegistry $openAiCompatibleEndpoints = null,
    ) {
    }

    public function supports(string $provider): bool
    {
        return null !== $this->descriptor($provider)?->chatCompletionsEndpoint();
    }

    /** The provider's descriptor, for the request rules the translator applies. */
    public function descriptor(string $provider): ?ProviderDescriptor
    {
        return $this->catalog->find($provider);
    }

    /** An install-configured host: Ollama or an admin-registered endpoint; no platform key. */
    public function isLocal(string $provider): bool
    {
        return true === $this->catalog->find($provider)?->isLocal();
    }

    public function needsProviderKey(string $provider): bool
    {
        return !$this->isLocal($provider);
    }

    /** The fixed `…/chat/completions` URL of a cloud provider, null for a local host. */
    public function fixedUrl(string $provider): ?string
    {
        return $this->catalog->find($provider)?->chatCompletionsUrl();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function completionsUrl(array $context): ?string
    {
        if (isset($context['openai_completions_url']) && \is_string($context['openai_completions_url']) && '' !== $context['openai_completions_url']) {
            return rtrim($context['openai_completions_url'], '/');
        }

        if (isset($context['openai_upstream_url']) && \is_string($context['openai_upstream_url']) && '' !== $context['openai_upstream_url']) {
            return rtrim($context['openai_upstream_url'], '/').'/v1/chat/completions';
        }

        $provider = strtolower((string) ($context['provider'] ?? 'openai'));
        $fixed = $this->fixedUrl($provider);
        if (null !== $fixed) {
            return $fixed;
        }

        if ('ollama' === $provider) {
            $base = trim($this->ollamaBaseUrl);
            if ('' === $base) {
                return null;
            }

            return rtrim($base, '/').'/v1/chat/completions';
        }

        if (OpenAiCompatibleEndpointRegistry::PROVIDER_NAME === $provider) {
            $endpoint = $this->compatibleEndpoint($context);
            if (null === $endpoint || '' === $endpoint['base_url']) {
                return null;
            }

            return rtrim($endpoint['base_url'], '/').'/chat/completions';
        }

        return self::DEFAULT_UPSTREAM.'/v1/chat/completions';
    }

    /**
     * @param array<string, mixed> $context
     */
    public function responsesUrl(array $context): string
    {
        if (isset($context['openai_responses_url']) && \is_string($context['openai_responses_url']) && '' !== $context['openai_responses_url']) {
            return rtrim($context['openai_responses_url'], '/');
        }
        if (isset($context['openai_upstream_url']) && \is_string($context['openai_upstream_url']) && '' !== $context['openai_upstream_url']) {
            return rtrim($context['openai_upstream_url'], '/').'/v1/responses';
        }

        return self::DEFAULT_UPSTREAM.'/v1/responses';
    }

    /**
     * The admin-registered endpoint an OpenAI-compatible model runs on.
     *
     * @param array<string, mixed> $context
     *
     * @return array{name: string, label: string, base_url: string, api_key: string, headers: array<string, string>, capabilities: string[]}|null
     */
    public function compatibleEndpoint(array $context): ?array
    {
        if (null === $this->openAiCompatibleEndpoints) {
            return null;
        }

        $provider = strtolower((string) ($context['provider'] ?? ''));
        if (OpenAiCompatibleEndpointRegistry::PROVIDER_NAME !== $provider) {
            return null;
        }

        $providerModelId = isset($context['provider_model_id']) && \is_string($context['provider_model_id'])
            ? $context['provider_model_id']
            : null;

        return $this->openAiCompatibleEndpoints->resolveForModel($providerModelId);
    }
}
