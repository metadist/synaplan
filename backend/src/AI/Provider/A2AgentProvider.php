<?php

declare(strict_types=1);

namespace App\AI\Provider;

/**
 * A2Agent — OpenAI-compatible gateway (Omnimodel Technology Limited) for
 * Chinese frontier models (Qwen, DeepSeek, MiniMax).
 *
 * Prompts are processed by mainland-China model vendors through this reseller.
 * A2Agent serves users and entities outside mainland China. Model ids are
 * case-sensitive; MiniMax uses the mixed-case id `MiniMax-M3`.
 *
 * @see https://a2agent.me/integrations
 * @see https://a2agent.me/v1
 */
class A2AgentProvider extends AbstractChatCompletionsCloudProvider
{
    private const PROVIDER_NAME = 'a2agent';
    private const BASE_URI = 'https://a2agent.me/v1';
    private const DEFAULT_CHAT_MODEL = 'deepseek-v4-pro';
    private const DEFAULT_VISION_MODEL = 'qwen3.8-flash';

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getDisplayName(): string
    {
        return 'A2Agent';
    }

    public function getDescription(): string
    {
        return 'Chinese frontier models (Qwen, DeepSeek, MiniMax) via the A2Agent gateway. Prompts are processed by mainland-China model vendors. OpenAI-compatible API.';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'vision'];
    }

    public function getDefaultModels(): array
    {
        return [
            'chat' => self::DEFAULT_CHAT_MODEL,
            'vision' => self::DEFAULT_VISION_MODEL,
        ];
    }

    protected function baseUri(): string
    {
        return self::BASE_URI;
    }

    protected function envVarName(): string
    {
        return 'A2AGENT_API_KEY';
    }

    protected function envVarHint(): string
    {
        return 'Get your API key from https://a2agent.me/ (dashboard → API keys)';
    }

    /**
     * A2Agent reasoning models think by default. ChatHandler's Thinking toggle
     * arrives as `$options['reasoning']`; the gateway only honours
     * `thinking: {type: "disabled"}` (not `enable_thinking`). TrustedTokens
     * does not accept this field — keep the mapping off the shared builder.
     *
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    protected function buildChatOptions(array $messages, array $options, bool $stream): array
    {
        $request = parent::buildChatOptions($messages, $options, $stream);

        if (!array_key_exists('reasoning', $options)) {
            return $request;
        }

        $features = $options['modelFeatures'] ?? null;
        if (is_array($features) && !in_array('reasoning', $features, true)) {
            return $request;
        }

        if (!$this->isReasoningEnabled($options['reasoning'])) {
            $request['thinking'] = ['type' => 'disabled'];
        }

        return $request;
    }

    private function isReasoningEnabled(mixed $reasoning): bool
    {
        if (is_array($reasoning)) {
            return [] !== $reasoning;
        }

        return (bool) $reasoning;
    }
}
