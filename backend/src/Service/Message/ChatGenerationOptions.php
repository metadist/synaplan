<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\AI\Provider\ReasoningLevelCatalog;
use App\AI\Stream\VisibleAnswer;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\Entity\Model;
use App\Service\Runtime\RuntimeProfile;

/**
 * How a chat turn is generated, apart from its messages: the identity a
 * runtime profile (assistant) gives it, the profile's sampling options,
 * the reasoning level the person picked, and the thinking budget of
 * local models.
 */
final readonly class ChatGenerationOptions
{
    public function __construct(
        private StructuredOutputConfig $structuredOutputConfig,
    ) {
    }

    /**
     * Pick up the RuntimeProfile the classifier resolved (if any) and let it
     * own the prompt identity. Absent profile ⇒ byte-identical classification.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array{0: array<string, mixed>, 1: RuntimeProfile|null}
     */
    public function applyRuntimeProfile(array $classification, array $options): array
    {
        $profile = $options['runtime_profile'] ?? $classification['runtime_profile'] ?? null;
        if (!$profile instanceof RuntimeProfile) {
            return [$classification, null];
        }

        if ('' !== $profile->promptTopic) {
            $classification['topic'] = $profile->promptTopic;
        }
        if (null !== $profile->promptId) {
            $classification['prompt_id'] = $profile->promptId;
        }

        return [$classification, $profile];
    }

    /**
     * Copy assistant Advanced settings (creativity, length, response format)
     * onto the generation call. Unsupported structured output is ignored.
     *
     * @param array<string, mixed> $aiOptions
     *
     * @return array<string, mixed>
     */
    public function applyProfileGenerationOptions(array $aiOptions, ?RuntimeProfile $profile, int $userId): array
    {
        if (!$profile instanceof RuntimeProfile) {
            return $aiOptions;
        }

        $params = $profile->parameters;
        if (isset($params['temperature']) && is_numeric($params['temperature'])) {
            $aiOptions['temperature'] = max(0.0, min(2.0, (float) $params['temperature']));
        }
        if (isset($params['maxTokens']) && is_numeric($params['maxTokens'])) {
            $requested = max(1, (int) $params['maxTokens']);
            $existing = $aiOptions['max_tokens'] ?? null;
            $aiOptions['max_tokens'] = is_int($existing) && $existing > 0 ? min($existing, $requested) : $requested;
        }
        $schema = $params['responseSchema'] ?? null;
        if (is_array($schema) && !array_is_list($schema) && $this->structuredOutputConfig->isEnabled($userId)) {
            $aiOptions['structured_output'] = $schema;
        }

        return $aiOptions;
    }

    /**
     * Forward a chosen reasoning level when this model publishes one.
     *
     * The streaming path already merged the request options, including the
     * Thinking boolean. The non-streaming path only gains a level when the
     * caller sent one. When thinking is on and no level was sent, the model's
     * catalog default is applied. The model JSON is also forwarded as
     * modelConfig so providers that read reasoning_effort_default see it.
     *
     * @param array<string, mixed> $aiOptions
     * @param array<string, mixed> $requestOptions
     *
     * @return array<string, mixed>
     */
    public function applyReasoningLevel(array $aiOptions, ?Model $model, array $requestOptions): array
    {
        if (!isset($aiOptions['reasoning_effort']) && isset($requestOptions['reasoning_effort']) && is_string($requestOptions['reasoning_effort'])) {
            $aiOptions['reasoning_effort'] = $requestOptions['reasoning_effort'];
        }

        if (null === $model) {
            unset($aiOptions['reasoning_effort']);

            return $aiOptions;
        }

        $modelJson = $model->getJson();
        if (!isset($aiOptions['modelConfig']) || !is_array($aiOptions['modelConfig'])) {
            $aiOptions['modelConfig'] = $modelJson;
        }

        return ReasoningLevelCatalog::apply(
            $aiOptions,
            $model->getService(),
            $model->getProviderId(),
            $model->getFeatures(),
            $modelJson,
        );
    }

    /**
     * Qwen3-class models on an OpenAI-compatible gateway (self-hosted Ollama)
     * spend max_tokens on a thinking channel and cut the JSON answer off
     * (#2264). Ask the gateway to skip that channel. Other providers are left
     * alone — a cloud API that does not understand the flag must not see it.
     *
     * @param array<string, mixed> $aiOptions
     *
     * @return array<string, mixed>
     */
    public function applyLocalThinkingBudget(array $aiOptions): array
    {
        $provider = strtolower((string) ($aiOptions['provider'] ?? ''));
        $model = (string) ($aiOptions['model'] ?? '');
        if ('openaicompatible' === $provider && VisibleAnswer::modelHidesAnswerBehindThinking($model)) {
            $aiOptions['disable_thinking'] = true;
        }

        return $aiOptions;
    }
}
