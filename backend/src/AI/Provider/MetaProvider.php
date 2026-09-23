<?php

declare(strict_types=1);

namespace App\AI\Provider;

/**
 * Meta Model API — first-party Muse models (Muse Spark).
 *
 * OpenAI-compatible Chat Completions at https://api.meta.ai/v1.
 * Auth is `Authorization: Bearer`. Meta's own SDKs read MODEL_API_KEY;
 * Synaplan stores the same secret as META_API_KEY.
 *
 * `reasoning_effort` is a top-level Chat Completions field. `none` is not
 * supported and returns HTTP 400. The contributor-tier model id is not
 * offered: that tier is used to improve Meta's products.
 *
 * @see https://dev.meta.ai/docs/authentication
 * @see https://dev.meta.ai/docs/api-reference/chat-completions/create-chat-completion
 * @see https://developer.meta.com/ai/models/muse-spark/
 */
class MetaProvider extends AbstractChatCompletionsCloudProvider
{
    private const PROVIDER_NAME = 'meta';
    private const BASE_URI = 'https://api.meta.ai/v1';
    private const DEFAULT_CHAT_MODEL = 'muse-spark-1.3';
    private const DEFAULT_VISION_MODEL = 'muse-spark-1.3';

    /** @var list<string> */
    private const REASONING_EFFORTS = ['minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getDisplayName(): string
    {
        return 'Meta';
    }

    public function getDescription(): string
    {
        return 'Meta Model API (Muse Spark). Prompts are processed by Meta. OpenAI-compatible API.';
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
        return 'META_API_KEY';
    }

    protected function envVarHint(): string
    {
        return 'Create an API key at https://dev.meta.ai/ (API keys → Create API key). Meta\'s own SDK calls this MODEL_API_KEY.';
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    protected function buildChatOptions(array $messages, array $options, bool $stream): array
    {
        $request = parent::buildChatOptions($messages, $options, $stream);

        $effort = $this->resolveReasoningEffort($options);
        if (null !== $effort) {
            $request['reasoning_effort'] = $effort;
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveReasoningEffort(array $options): ?string
    {
        $explicit = $options['reasoning_effort'] ?? null;
        if (is_string($explicit) && in_array(strtolower($explicit), self::REASONING_EFFORTS, true)) {
            return strtolower($explicit);
        }

        if (!array_key_exists('reasoning', $options)) {
            return null;
        }

        $features = $options['modelFeatures'] ?? null;
        if (is_array($features) && !in_array('reasoning', $features, true)) {
            return null;
        }

        if (!$this->isReasoningEnabled($options['reasoning'])) {
            return 'minimal';
        }

        $config = $options['modelConfig'] ?? null;
        $default = is_array($config) ? ($config['reasoning_effort_default'] ?? null) : null;
        if (is_string($default) && in_array($default, self::REASONING_EFFORTS, true)) {
            return $default;
        }

        return 'high';
    }

    private function isReasoningEnabled(mixed $reasoning): bool
    {
        if (is_array($reasoning)) {
            return [] !== $reasoning;
        }

        return (bool) $reasoning;
    }
}
