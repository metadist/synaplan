<?php

declare(strict_types=1);

namespace App\Service\Message\Chat;

use App\AI\Provider\ReasoningLevelCatalog;
use App\AI\Stream\VisibleAnswer;
use App\AI\StructuredOutput\Schema\FileGenerationSchema;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\Entity\Model;
use App\Service\RateLimitService;
use App\Service\Runtime\RuntimeProfile;

/**
 * The provider options of a chat turn, apart from its messages: provider and
 * model, structured output for documents, the routing tools of a deferred
 * hand-off, the output-token ceiling, the assistant's sampling settings, the
 * reasoning level the person picked, and the thinking budget of local models.
 */
final readonly class ChatRequestOptions
{
    /**
     * The live voice speaks this answer aloud while the person waits, so the
     * whole reply has to be generated before a single word is heard.
     */
    private const LIVE_VOICE_MAX_TOKENS = 600;

    private const DEFAULT_TEMPERATURE = 0.7;

    public function __construct(
        private StructuredOutputConfig $structuredOutputConfig,
        private RateLimitService $rateLimitService,
    ) {
    }

    /**
     * @param array<string, mixed>      $options      the turn options after the system prompt took its parts
     * @param array<string, mixed>|null $routingTools tool definitions of a deferred routing decision
     *
     * @return array<string, mixed>
     */
    public function forBufferedTurn(ChatTurn $turn, ChatModelChoice $model, array $options, ?array $routingTools): array
    {
        $aiOptions = [
            'provider' => $model->provider,
            'model' => $model->modelName,
            'stream' => false,
            'temperature' => self::DEFAULT_TEMPERATURE,
        ];
        $aiOptions = $this->withDocumentSchema($aiOptions, $turn, $routingTools);

        // Clamp max_tokens to min(plan_limit, model_max). plan_limit is only
        // set for ANONYMOUS (the only tier with a hard MAX_OUTPUT_TOKENS cap);
        // for all authenticated tiers getMaxOutputTokens() returns null, so the
        // model's full max_tokens is used. Spend for those tiers is bounded by
        // the cost-budget gate / message-count limits instead.
        $aiOptions = self::withTokenCeiling($aiOptions, [
            $this->planMaxTokens($turn),
            $model->maxTokens(),
            $turn->isLiveVoice() ? self::LIVE_VOICE_MAX_TOKENS : null,
        ]);

        return $this->tuned($aiOptions, $turn, $model, $options);
    }

    /**
     * The streamed web chat forwards every request option to the provider
     * call; the turn's own settings are layered on top of them.
     *
     * @param array<string, mixed>      $options      the turn options after messages were built
     * @param array<string, mixed>|null $routingTools tool definitions of a deferred routing decision
     *
     * @return array<string, mixed>
     */
    public function forStreamedTurn(ChatTurn $turn, ChatModelChoice $model, array $options, ?array $routingTools): array
    {
        $aiOptions = array_merge([
            'provider' => $model->provider,
            'model' => $model->modelName,
            'temperature' => self::DEFAULT_TEMPERATURE,
            'modelFeatures' => $model->features(),
        ], $options);
        $aiOptions = $this->withDocumentSchema($aiOptions, $turn, $routingTools);

        // Clamp max_tokens to min(requested, plan_limit, model_max).
        // plan_limit is only set for ANONYMOUS; authenticated tiers get the
        // model's full max_tokens (getMaxOutputTokens() returns null for them).
        $aiOptions = self::withTokenCeiling($aiOptions, [
            $aiOptions['max_tokens'] ?? null,
            $this->planMaxTokens($turn),
            $model->maxTokens(),
        ]);

        return $this->tuned($aiOptions, $turn, $model, $options);
    }

    /**
     * officemaker is the only topic whose reply IS the machine-readable
     * envelope {"BFILEPATH":…,"BFILETEXT":…} — every other topic keeps its
     * free-form chat completion untouched. A deferred routing decision adds
     * its tools after that.
     *
     * @param array<string, mixed>      $aiOptions
     * @param array<string, mixed>|null $routingTools
     *
     * @return array<string, mixed>
     */
    private function withDocumentSchema(array $aiOptions, ChatTurn $turn, ?array $routingTools): array
    {
        if ('officemaker' === $turn->topic() && $this->structuredOutputConfig->isEnabled($turn->message->getUserId())) {
            $aiOptions['structured_output'] = FileGenerationSchema::build();
        }

        if (null !== $routingTools) {
            $aiOptions = array_merge($aiOptions, $routingTools);
        }

        return $aiOptions;
    }

    /**
     * The person's plan limit, read from the user the turn already loaded so
     * the repository is not hit twice per request.
     */
    private function planMaxTokens(ChatTurn $turn): ?int
    {
        return null !== $turn->personal->user ? $this->rateLimitService->getMaxOutputTokens($turn->personal->user) : null;
    }

    /**
     * @param array<string, mixed> $aiOptions
     * @param array<int, mixed>    $limits    candidate ceilings; anything but a positive int is ignored
     *
     * @return array<string, mixed>
     */
    private static function withTokenCeiling(array $aiOptions, array $limits): array
    {
        $limits = array_filter($limits, static fn ($v) => is_int($v) && $v > 0);
        if ([] !== $limits) {
            $aiOptions['max_tokens'] = min($limits);
        }

        return $aiOptions;
    }

    /**
     * @param array<string, mixed> $aiOptions
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function tuned(array $aiOptions, ChatTurn $turn, ChatModelChoice $model, array $options): array
    {
        $aiOptions = $this->applyProfileGenerationOptions($aiOptions, $turn->profile, $turn->message->getUserId());
        $aiOptions = $this->applyReasoningLevel($aiOptions, $model->model, $options);

        return $this->applyLocalThinkingBudget($aiOptions);
    }

    /**
     * Copy assistant Advanced settings (creativity, length, response format)
     * onto the generation call. Unsupported structured output is ignored.
     *
     * @param array<string, mixed> $aiOptions
     *
     * @return array<string, mixed>
     */
    private function applyProfileGenerationOptions(array $aiOptions, ?RuntimeProfile $profile, int $userId): array
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
    private function applyReasoningLevel(array $aiOptions, ?Model $model, array $requestOptions): array
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
    private function applyLocalThinkingBudget(array $aiOptions): array
    {
        $provider = strtolower((string) ($aiOptions['provider'] ?? ''));
        $model = (string) ($aiOptions['model'] ?? '');
        if ('openaicompatible' === $provider && VisibleAnswer::modelHidesAnswerBehindThinking($model)) {
            $aiOptions['disable_thinking'] = true;
        }

        return $aiOptions;
    }
}
