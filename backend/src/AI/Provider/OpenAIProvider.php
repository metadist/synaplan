<?php

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\Service\File\FileHelper;
use OpenAI;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAIProvider implements ChatProviderInterface, EmbeddingProviderInterface, ImageGenerationProviderInterface, VisionProviderInterface, SpeechToTextProviderInterface, TextToSpeechProviderInterface
{
    private const DEFAULT_MAX_TOKENS = 4096;

    private $client;
    private array $modelCapabilities = [];

    /**
     * Per-model cache of "this model rejects the reasoning.effort tier we
     * sent" outcomes. Populated by {@see self::executeResponsesCreate()} /
     * {@see self::executeResponsesCreateStreamed()} on the first HTTP 400 of
     * the form "Unsupported value: 'X' is not supported with the 'Y' model".
     * Subsequent requests for the same model skip the reasoning block
     * pre-emptively so we don't keep paying the round-trip-then-retry cost.
     *
     * @var array<string, true>
     */
    private array $reasoningRejectionCache = [];

    public function __construct(
        private LoggerInterface $logger,
        private HttpClientInterface $httpClient,
        private ?string $apiKey = null,
        private string $uploadDir = '/var/www/backend/var/uploads',
        private bool $storeResponses = false,
    ) {
        if (!empty($apiKey)) {
            $this->client = \OpenAI::client($apiKey);
        }
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function getDisplayName(): string
    {
        return 'OpenAI';
    }

    public function getDescription(): string
    {
        return 'GPT models for chat, vision, and content generation';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'embedding', 'vision', 'image_generation', 'speech_to_text', 'text_to_speech'];
    }

    public function getDefaultModels(): array
    {
        return [];
    }

    public function getStatus(): array
    {
        if (!$this->client) {
            return [
                'healthy' => false,
                'error' => 'API key not configured',
            ];
        }

        return [
            'healthy' => true,
            'latency_ms' => 50,
            'error_rate' => 0.0,
            'active_connections' => 0,
        ];
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey) && null !== $this->client;
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'OPENAI_API_KEY' => [
                'required' => true,
                'hint' => 'Get your API key from https://platform.openai.com/',
            ],
        ];
    }

    /**
     * Check if model uses max_completion_tokens instead of max_tokens
     * Based on OpenAI API model capabilities (reasoning models use max_completion_tokens).
     *
     * @param string $model Model name
     *
     * @return bool True if model uses max_completion_tokens
     */
    private function usesCompletionTokens(string $model): bool
    {
        // Check cache first
        if (isset($this->modelCapabilities[$model])) {
            return $this->modelCapabilities[$model];
        }

        // Try to fetch model details from OpenAI API
        try {
            $modelInfo = $this->client->models()->retrieve($model);

            // Check if model has reasoning capabilities or is o-series/gpt-5
            // Reasoning models (o1, o3, gpt-5) use max_completion_tokens
            $isReasoningModel = isset($modelInfo->capabilities['reasoning'])
                               || str_starts_with($model, 'o1')
                               || str_starts_with($model, 'o3')
                               || str_starts_with($model, 'gpt-5');

            $this->modelCapabilities[$model] = $isReasoningModel;

            return $isReasoningModel;
        } catch (\Exception $e) {
            // If API call fails, use heuristic fallback
            $this->logger->warning('Failed to fetch model capabilities from OpenAI, using heuristic', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            // Heuristic: o-series and gpt-5 models use max_completion_tokens
            $usesCompletionTokens = str_starts_with($model, 'o1')
                                   || str_starts_with($model, 'o3')
                                   || str_starts_with($model, 'gpt-5');

            $this->modelCapabilities[$model] = $usesCompletionTokens;

            return $usesCompletionTokens;
        }
    }

    // ==================== CHAT (Responses API) ====================

    public function chat(array $messages, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'openai');
        }

        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'];
            $isReasoningModel = $this->usesCompletionTokens($model);

            $requestOptions = $this->buildResponsesRequest($messages, $model, $isReasoningModel, $options);

            $response = $this->executeResponsesCreate($requestOptions);
            $responseArray = $response->toArray();

            $usage = $this->normalizeResponsesUsage($responseArray);

            $this->logger->info('OpenAI: Chat completed via Responses API', [
                'model' => $model,
                'response_id' => $response->id,
                'usage' => $usage,
            ]);

            return [
                'content' => $response->outputText ?? '',
                'response_id' => $this->storeResponses ? $response->id : null,
                'usage' => $usage,
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI chat error: '.$e->getMessage(), 'openai');
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'openai');
        }

        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'];
            $isReasoningModel = $this->usesCompletionTokens($model);

            $requestOptions = $this->buildResponsesRequest($messages, $model, $isReasoningModel, $options);

            $stream = $this->executeResponsesCreateStreamed($requestOptions);

            $usage = [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'cached_tokens' => 0,
                'cache_creation_tokens' => 0,
            ];

            $responseId = null;
            $finishReason = null;

            foreach ($stream as $event) {
                $eventType = $event->event;
                $eventData = $event->response->toArray();

                switch ($eventType) {
                    case 'response.created':
                        $responseId = $eventData['response']['id'] ?? null;
                        break;

                    case 'response.output_text.delta':
                        $content = $eventData['delta'] ?? '';
                        if ('' !== $content) {
                            $callback(['type' => 'content', 'content' => $content]);
                        }
                        break;

                    case 'response.refusal.delta':
                        $refusal = $eventData['delta'] ?? '';
                        if ('' !== $refusal) {
                            $callback(['type' => 'content', 'content' => $refusal]);
                        }
                        break;

                    case 'response.reasoning_summary_text.delta':
                        $reasoningContent = $eventData['delta'] ?? '';
                        if ('' !== $reasoningContent) {
                            $callback(['type' => 'reasoning', 'content' => $reasoningContent]);
                        }
                        break;

                    case 'response.completed':
                        $usage = $this->normalizeResponsesUsage($eventData['response'] ?? []);
                        $responseId = $eventData['response']['id'] ?? $responseId;
                        $status = $eventData['response']['status'] ?? 'completed';
                        $finishReason = ('completed' === $status) ? 'stop' : 'length';
                        break;

                    case 'response.failed':
                        $error = $eventData['response']['error']['message'] ?? 'Unknown error';
                        throw new ProviderException('OpenAI Responses API failed: '.$error, 'openai');
                    case 'response.incomplete':
                        $usage = $this->normalizeResponsesUsage($eventData['response'] ?? []);
                        $responseId = $eventData['response']['id'] ?? $responseId;
                        $finishReason = 'length';
                        break;
                }
            }

            if (null !== $finishReason) {
                $callback(['type' => 'finish', 'finish_reason' => $finishReason]);
            }

            return [
                'usage' => $usage,
                'response_id' => $this->storeResponses ? $responseId : null,
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI streaming error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Build the Responses API request from the standard messages array.
     *
     * Extracts the system message into `instructions` and converts
     * user/assistant messages into the `input` field.
     */
    private function buildResponsesRequest(array $messages, string $model, bool $isReasoningModel, array $options): array
    {
        $systemMessage = $this->extractSystemMessage($messages);
        $input = $this->convertToResponsesFormat($this->removeSystemMessages($messages));

        if (empty($input)) {
            $input = [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Start']]]];
        }

        $requestOptions = [
            'model' => $model,
            'input' => $input,
            'store' => $this->storeResponses,
            'max_output_tokens' => $options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS,
        ];

        if (null !== $systemMessage) {
            $requestOptions['instructions'] = $systemMessage;
        }

        if (!$isReasoningModel) {
            $requestOptions['temperature'] = $options['temperature'] ?? 0.7;
        }

        if ($isReasoningModel) {
            $reasoningConfig = $this->resolveReasoningConfig($model, $isReasoningModel, $options);
            if (null !== $reasoningConfig) {
                $requestOptions['reasoning'] = $reasoningConfig;
            }
        }

        if (isset($options['previous_response_id'])) {
            $requestOptions['previous_response_id'] = $options['previous_response_id'];
        }

        return $requestOptions;
    }

    /**
     * Translate the cross-provider `reasoning_effort` knob into OpenAI's
     * Responses API `reasoning` config.
     *
     * Mirrors the logic in {@see GoogleProvider::resolveThinkingConfig()} so the
     * "default chat = no thinking, near-instant TTFT" behaviour is identical
     * across providers. The headline Phase 1e win on Gemini Pro
     * (`thinkingBudget=0` for default chat) translates to OpenAI as the
     * model family's lowest reasoning tier — `'none'` on gpt-5.5+, `'minimal'`
     * on the original gpt-5, `'low'` on the o-series. Without this, the model
     * falls back to OpenAI's server-side default of `medium` and burns 1-3 s
     * of chain-of-thought before emitting the first visible token, even on a
     * "Hi, how are you?" style chat where the user did not enable the
     * Thinking toggle.
     *
     * Resolution order:
     *
     * 1. Native passthrough — if `options['reasoning']` is already a fully
     *    formed array, send it verbatim (advanced override path).
     * 2. Cross-provider `options['reasoning_effort']` wins over the legacy
     *    boolean flag.
     * 3. Legacy `options['reasoning']` boolean (the user's "Thinking" UI
     *    toggle) maps to:
     *    - `true`  → `'medium'` (preserves prior behaviour)
     *    - `false` → lowest available tier (NEW — the Phase 1e parallel)
     * 4. No signal at all → return `null` so no reasoning block is sent and
     *    the server applies its own default (preserves prior behaviour for
     *    callers that pass empty options, e.g. unit tests).
     *
     * `summary => 'auto'` is included only when the resolved effort is
     * `medium`, `high` or `xhigh` — that's where chain-of-thought is long
     * enough for the SSE reasoning-summary stream to be useful.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, string>|null
     */
    private function resolveReasoningConfig(string $model, bool $isReasoningModel, array $options): ?array
    {
        if (!$isReasoningModel) {
            return null;
        }

        if (isset($options['reasoning']) && is_array($options['reasoning'])) {
            /** @var array<string, string> $explicit */
            $explicit = $options['reasoning'];

            return $explicit;
        }

        $effort = isset($options['reasoning_effort']) ? (string) $options['reasoning_effort'] : null;

        if (null === $effort && array_key_exists('reasoning', $options)) {
            $effort = ((bool) $options['reasoning']) ? 'medium' : 'lowest';
        }

        if (null === $effort) {
            return null;
        }

        $lowestTier = $this->lowestEffortTier($model);
        $supportsXHigh = $this->modelSupportsXHighEffort($model);

        $resolvedEffort = match ($effort) {
            // 'lowest' is our internal sentinel for "lowest tier this model
            // accepts" (= the Phase 1e fast-default path). 'minimal' / 'none'
            // / 'off' / 'disabled' all map to the same intent: skip reasoning.
            'lowest', 'off', 'none', 'disabled', 'minimal' => $lowestTier,
            'low' => 'low',
            'medium' => 'medium',
            'high' => 'high',
            // gpt-5.5+ exposes an 'xhigh' tier above 'high'. On older models
            // it isn't accepted — clamp down to 'high' to avoid an HTTP 400.
            'xhigh', 'extra-high', 'extreme' => $supportsXHigh ? 'xhigh' : 'high',
            default => null,
        };

        if (null === $resolvedEffort) {
            return null;
        }

        $config = ['effort' => $resolvedEffort];

        if (in_array($resolvedEffort, ['medium', 'high', 'xhigh'], true)) {
            $config['summary'] = 'auto';
        }

        return $config;
    }

    /**
     * Lowest `reasoning.effort` tier the given model accepts.
     *
     * The Responses-API vocabulary is per family:
     *
     * - `gpt-5.5*` (gpt-5.5, gpt-5.5-pro, …): `none`, `low`, `medium`, `high`,
     *   `xhigh`. Skip-reasoning tier is `none`. (Sending `minimal` here
     *   returns HTTP 400 with "Unsupported value: 'minimal' is not supported
     *   with the 'gpt-5.5' model".)
     * - `gpt-5` (original): `minimal`, `low`, `medium`, `high`. Skip tier is
     *   `minimal`.
     * - o-series (`o1`, `o3`, `o4`): `low`, `medium`, `high`. Skip tier is
     *   `low` — these models don't accept `minimal`/`none`.
     */
    private function lowestEffortTier(string $model): string
    {
        if (str_starts_with($model, 'gpt-5.5')) {
            return 'none';
        }
        if (str_starts_with($model, 'gpt-5')) {
            return 'minimal';
        }

        return 'low';
    }

    /**
     * Whether the model accepts `reasoning.effort = 'xhigh'`.
     *
     * Currently only the `gpt-5.5` family. Original gpt-5 + o-series cap at
     * `'high'` and reject `xhigh` with HTTP 400.
     */
    private function modelSupportsXHighEffort(string $model): bool
    {
        return str_starts_with($model, 'gpt-5.5');
    }

    /**
     * Execute responses()->create() with fallback when previous_response_id
     * is invalid OR when the chosen reasoning.effort tier is rejected by the
     * model (tier vocabulary differs across the gpt-5 family — see
     * {@see self::lowestEffortTier()}).
     *
     * When previous_response_id is present, the input is reduced to just the
     * last user message (the API already has prior context). On fallback the
     * full input is restored.
     */
    private function executeResponsesCreate(array $requestOptions): mixed
    {
        $requestOptions = $this->applyReasoningRejectionCache($requestOptions);

        $fullInput = $requestOptions['input'] ?? [];
        $hasPreviousResponse = isset($requestOptions['previous_response_id']);
        $hasReasoning = isset($requestOptions['reasoning']);
        $model = (string) ($requestOptions['model'] ?? '');

        if ($hasPreviousResponse) {
            $requestOptions['input'] = $this->reduceToLastUserMessage($fullInput);
        }

        try {
            return $this->client->responses()->create($requestOptions);
        } catch (\Exception $e) {
            if ($hasReasoning && $this->isReasoningEffortRejectedError($e)) {
                $this->rememberReasoningRejection($model, $requestOptions['reasoning'] ?? [], $e);
                unset($requestOptions['reasoning']);

                return $this->client->responses()->create($requestOptions);
            }

            if ($hasPreviousResponse && $this->isPreviousResponseError($e)) {
                $this->logger->warning('OpenAI: previous_response_id invalid, retrying with full context', [
                    'previous_response_id' => $requestOptions['previous_response_id'],
                    'error' => $e->getMessage(),
                ]);
                unset($requestOptions['previous_response_id']);
                $requestOptions['input'] = $fullInput;

                return $this->client->responses()->create($requestOptions);
            }

            throw $e;
        }
    }

    /**
     * Execute responses()->createStreamed() with the same two fallbacks as
     * {@see self::executeResponsesCreate()}: invalid `previous_response_id`,
     * and an unsupported `reasoning.effort` value (per-model tier vocab).
     */
    private function executeResponsesCreateStreamed(array $requestOptions): mixed
    {
        $requestOptions = $this->applyReasoningRejectionCache($requestOptions);

        $fullInput = $requestOptions['input'] ?? [];
        $hasPreviousResponse = isset($requestOptions['previous_response_id']);
        $hasReasoning = isset($requestOptions['reasoning']);
        $model = (string) ($requestOptions['model'] ?? '');

        if ($hasPreviousResponse) {
            $requestOptions['input'] = $this->reduceToLastUserMessage($fullInput);
        }

        try {
            return $this->client->responses()->createStreamed($requestOptions);
        } catch (\Exception $e) {
            if ($hasReasoning && $this->isReasoningEffortRejectedError($e)) {
                $this->rememberReasoningRejection($model, $requestOptions['reasoning'] ?? [], $e);
                unset($requestOptions['reasoning']);

                return $this->client->responses()->createStreamed($requestOptions);
            }

            if ($hasPreviousResponse && $this->isPreviousResponseError($e)) {
                $this->logger->warning('OpenAI: previous_response_id invalid for stream, retrying with full context', [
                    'previous_response_id' => $requestOptions['previous_response_id'],
                    'error' => $e->getMessage(),
                ]);
                unset($requestOptions['previous_response_id']);
                $requestOptions['input'] = $fullInput;

                return $this->client->responses()->createStreamed($requestOptions);
            }

            throw $e;
        }
    }

    /**
     * Strip `reasoning` from the request when this model has previously
     * rejected our chosen tier. Cheap pre-flight check that avoids a
     * round-trip + retry on every subsequent request after the first 400.
     *
     * @param array<string, mixed> $requestOptions
     *
     * @return array<string, mixed>
     */
    private function applyReasoningRejectionCache(array $requestOptions): array
    {
        $model = (string) ($requestOptions['model'] ?? '');
        if ('' !== $model && isset($this->reasoningRejectionCache[$model], $requestOptions['reasoning'])) {
            unset($requestOptions['reasoning']);
        }

        return $requestOptions;
    }

    /**
     * Cache the fact that this model rejected our reasoning config + log it
     * once so ops can spot model-vocab drift (e.g. a future gpt-5.6 with a
     * tier name we don't know about).
     *
     * @param array<string, mixed> $reasoning
     */
    private function rememberReasoningRejection(string $model, array $reasoning, \Throwable $e): void
    {
        if ('' !== $model) {
            $this->reasoningRejectionCache[$model] = true;
        }

        $this->logger->warning('OpenAI: reasoning.effort rejected by model, retrying without reasoning block', [
            'model' => $model,
            'rejected_effort' => $reasoning['effort'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Detect HTTP 400 errors caused by an `reasoning.effort` value the model
     * doesn't accept.
     *
     * The user-visible OpenAI message is e.g.:
     *   "Unsupported value: 'minimal' is not supported with the 'gpt-5.5'
     *    model. Supported values are: 'none', 'low', 'medium', 'high', and
     *    'xhigh'."
     *
     * The match is intentionally loose ("unsupported value" + at least one
     * effort-tier word) so it survives small wording changes from OpenAI.
     */
    private function isReasoningEffortRejectedError(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());

        if (!str_contains($message, 'unsupported value')) {
            return false;
        }

        foreach (['minimal', 'none', 'low', 'medium', 'high', 'xhigh'] as $tier) {
            if (str_contains($message, "'".$tier."'")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce input to just the last user message for stateful conversations.
     *
     * When previous_response_id is used, the API already has the conversation context.
     * Only the new user message needs to be sent.
     */
    private function reduceToLastUserMessage(array $input): array
    {
        foreach (array_reverse($input) as $msg) {
            if ('user' === ($msg['role'] ?? '')) {
                return [$msg];
            }
        }

        return $input;
    }

    /**
     * Check if exception is caused by an invalid/expired previous_response_id.
     */
    private function isPreviousResponseError(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'previous_response_id')
            || str_contains($message, 'previous response')
            || str_contains($message, 'invalid_response_id');
    }

    /**
     * Extract the first system message content from a messages array.
     */
    private function extractSystemMessage(array $messages): ?string
    {
        foreach ($messages as $message) {
            if ('system' === ($message['role'] ?? '')) {
                return \is_string($message['content']) ? $message['content'] : null;
            }
        }

        return null;
    }

    /**
     * Remove all system messages from a messages array.
     */
    private function removeSystemMessages(array $messages): array
    {
        return array_values(array_filter(
            $messages,
            static fn (array $msg) => 'system' !== ($msg['role'] ?? '')
        ));
    }

    /**
     * Convert Chat Completions message format to Responses API format.
     *
     * Chat Completions uses: {type: "text", text: "..."} and {type: "image_url", image_url: {url: "..."}}
     * Responses API uses:    {type: "input_text"/"output_text", text: "..."} and {type: "input_image", image_url: "..."}
     */
    private function convertToResponsesFormat(array $messages): array
    {
        foreach ($messages as &$message) {
            $role = $message['role'] ?? 'user';
            $isAssistant = 'assistant' === $role;
            $textType = $isAssistant ? 'output_text' : 'input_text';

            if (!is_array($message['content'] ?? null)) {
                $text = $message['content'] ?? '';
                $message['content'] = [
                    [
                        'type' => $textType,
                        'text' => $text,
                    ],
                ];
                continue;
            }

            $converted = [];
            foreach ($message['content'] as $part) {
                $type = $part['type'] ?? '';
                if ('text' === $type) {
                    $converted[] = [
                        'type' => $textType,
                        'text' => $part['text'] ?? '',
                    ];
                } elseif ('image_url' === $type) {
                    $url = $part['image_url']['url'] ?? ($part['image_url'] ?? '');
                    $converted[] = [
                        'type' => 'input_image',
                        'image_url' => $url,
                    ];
                } else {
                    $converted[] = $part;
                }
            }
            $message['content'] = $converted;
        }

        return $messages;
    }

    /**
     * Normalize Responses API usage into the internal token format.
     *
     * Responses API uses input_tokens/output_tokens vs Chat Completions prompt_tokens/completion_tokens.
     */
    private function normalizeResponsesUsage(array $responseData): array
    {
        $usage = $responseData['usage'] ?? [];

        return [
            'prompt_tokens' => $usage['input_tokens'] ?? 0,
            'completion_tokens' => $usage['output_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? 0,
            'cached_tokens' => $usage['input_tokens_details']['cached_tokens'] ?? 0,
            'cache_creation_tokens' => $usage['input_tokens_details']['cache_creation_tokens'] ?? 0,
        ];
    }

    // ==================== EMBEDDING ====================

    public function embed(string $text, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'openai');
        }

        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $params = $this->buildEmbeddingParams($options['model'], $text, $options);

            $response = $this->client->embeddings()->create($params);
            $usage = $response->usage;

            return [
                'embedding' => $response['data'][0]['embedding'] ?? [],
                'usage' => [
                    'prompt_tokens' => null !== $usage ? $usage->promptTokens : 0,
                    'total_tokens' => null !== $usage ? $usage->totalTokens : 0,
                ],
            ];
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI embedding error: '.$e->getMessage(), 'openai');
        }
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Embedding model must be specified in options', 'openai');
        }

        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $params = $this->buildEmbeddingParams($options['model'], $texts, $options);

            $response = $this->client->embeddings()->create($params);
            $usage = $response->usage;

            return [
                'embeddings' => array_map(fn ($item) => $item['embedding'], $response['data']),
                'usage' => [
                    'prompt_tokens' => null !== $usage ? $usage->promptTokens : 0,
                    'total_tokens' => null !== $usage ? $usage->totalTokens : 0,
                ],
            ];
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI batch embedding error: '.$e->getMessage(), 'openai');
        }
    }

    public function getDimensions(string $model): int
    {
        // Native output dimensions per the OpenAI embedding catalog. The
        // previous implementation lied here for `text-embedding-3-large`
        // (claimed 1536) because `embed()` used to coerce v3 outputs to
        // 1536 via the API `dimensions` parameter. That coercion is gone
        // (issue #985 — it caused the documents/memories pipeline to
        // recreate Qdrant collections at the WRONG dim because the
        // catalog metadata said 3072 while the actual vectors came back
        // as 1536, resulting in HTTP 400 on every upsert), so this
        // method now reports the true model output.
        return match (true) {
            str_contains($model, 'text-embedding-3-small') => 1536,
            str_contains($model, 'text-embedding-3-large') => 3072,
            str_contains($model, 'text-embedding-ada-002') => 1536,
            default => 1536,
        };
    }

    /**
     * Build the params for an OpenAI embeddings call.
     *
     * Caller may pass `dimensions` in `$options` to opt-in to the v3
     * truncation feature (e.g. shrink `text-embedding-3-large` from
     * 3072 → 1024 to match a fixed collection width). The provider
     * never injects a default truncation on its own — that historical
     * behaviour silently desynchronised catalog `vector_dim` metadata
     * from the actual vector width and corrupted re-vectorize runs.
     * Caller-provided values are forwarded verbatim; an empty/zero
     * value is treated as "no override" so test fixtures stay simple.
     *
     * @param string|list<string>  $input
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildEmbeddingParams(string $model, string|array $input, array $options): array
    {
        $params = [
            'model' => $model,
            'input' => $input,
        ];

        $explicitDimensions = $options['dimensions'] ?? null;
        if (is_int($explicitDimensions) && $explicitDimensions > 0
            && str_contains($model, 'text-embedding-3')) {
            $params['dimensions'] = $explicitDimensions;
        }

        return $params;
    }

    // ==================== IMAGE GENERATION ====================

    public function generateImage(string $prompt, array $options = []): array
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'dall-e-3';
            $inputImages = $options['images'] ?? [];

            switch ($this->selectImageApi($model, $inputImages)) {
                case 'responses':
                    return $this->generateImageWithResponsesApi($prompt, $inputImages, $options);
                case 'gpt_image':
                    return $this->generateImageWithGptImage1($prompt, $options, $model);
            }

            // DALL-E models use Images API
            $requestOptions = [
                'model' => $model,
                'prompt' => $prompt,
                'n' => $options['n'] ?? 1,
                'size' => $options['size'] ?? '1024x1024',
            ];

            // DALL-E 3 specific options
            if ('dall-e-3' === $model) {
                $requestOptions['quality'] = $options['quality'] ?? 'standard'; // standard or hd
                $requestOptions['style'] = $options['style'] ?? 'vivid'; // vivid or natural
            }

            $this->logger->info('OpenAI: Generating image', [
                'model' => $model,
                'prompt_length' => strlen($prompt),
            ]);

            $response = $this->client->images()->create($requestOptions);

            $images = [];
            foreach ($response['data'] as $image) {
                $images[] = [
                    'url' => $image['url'] ?? null,
                    'b64_json' => $image['b64_json'] ?? null,
                    'revised_prompt' => $image['revised_prompt'] ?? null,
                ];
            }

            return $images;
        } catch (\Exception $e) {
            // Check for content policy violations
            if (false !== stripos($e->getMessage(), 'content_policy')
                || false !== stripos($e->getMessage(), 'safety')) {
                throw new ProviderException('Content policy violation: The prompt was rejected by OpenAI safety system', 'openai', ['prompt' => substr($prompt, 0, 100)]);
            }

            throw new ProviderException('OpenAI image generation error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Generate image using gpt-image-* family via Image Generations API.
     *
     * @see https://platform.openai.com/docs/guides/image-generation
     */
    private function generateImageWithGptImage1(string $prompt, array $options, string $model): array
    {
        try {
            $this->logger->info('OpenAI: Generating image with '.$model, [
                'prompt_length' => strlen($prompt),
            ]);

            $requestBody = [
                'model' => $model,
                'prompt' => $prompt,
                'n' => $options['n'] ?? 1,
                'size' => $options['size'] ?? '1024x1024',
            ];

            if (isset($options['quality'])) {
                $quality = $options['quality'];

                // Map legacy values to supported ones
                $qualityMap = [
                    'standard' => 'medium',
                    'hd' => 'high',
                ];
                if (isset($qualityMap[strtolower((string) $quality)])) {
                    $quality = $qualityMap[strtolower((string) $quality)];
                }

                $quality = strtolower((string) $quality);
                $allowedQualities = ['low', 'medium', 'high', 'auto'];
                if (!in_array($quality, $allowedQualities, true)) {
                    $this->logger->warning('OpenAI '.$model.': Unsupported quality value, defaulting to high', [
                        'provided' => $options['quality'],
                    ]);
                    $quality = 'high';
                }

                $requestBody['quality'] = $quality;
            }
            if (isset($options['background'])) {
                $requestBody['background'] = $options['background'];
            }

            $ch = curl_init('https://api.openai.com/v1/images/generations');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$this->apiKey,
                ],
                CURLOPT_POSTFIELDS => json_encode($requestBody),
                CURLOPT_TIMEOUT => 120,
            ]);

            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new \Exception('cURL error: '.$curlError);
            }

            if (200 !== $httpCode) {
                $this->logger->error('OpenAI '.$model.': HTTP error', [
                    'http_code' => $httpCode,
                    'response' => substr((string) $responseBody, 0, 500),
                ]);
                throw new \Exception('HTTP '.$httpCode.': '.$responseBody);
            }

            $response = json_decode((string) $responseBody, true);
            if (!$response || !isset($response['data'])) {
                throw new \Exception('Failed to parse JSON response');
            }

            $images = [];
            foreach ($response['data'] as $item) {
                $base64 = $item['b64_json'] ?? null;
                $url = $item['url'] ?? null;

                if (!$url && $base64) {
                    $url = 'data:image/png;base64,'.$base64;
                }

                $images[] = [
                    'url' => $url,
                    'b64_json' => $base64,
                    'revised_prompt' => $item['revised_prompt'] ?? null,
                ];
            }

            if (empty($images)) {
                $this->logger->error('OpenAI '.$model.': No images in response', [
                    'response' => $responseBody,
                ]);
                throw new ProviderException($model.' returned no images. Response format may have changed.', 'openai');
            }

            return $images;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI '.$model.' error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Decide which OpenAI image endpoint to dispatch a request to.
     *
     * - `responses`  : Responses API with the `image_generation` tool (pic2pic with reference images).
     * - `gpt_image`  : Images Generations API for the gpt-image-* family (1, 1.5, 2, …).
     * - `dalle`      : Legacy Images API via the OpenAI PHP client (dall-e-2, dall-e-3).
     *
     * @param string[] $inputImages Absolute paths to reference images, empty for text-to-image
     */
    private function selectImageApi(string $model, array $inputImages): string
    {
        if (!empty($inputImages) && $this->supportsResponsesApi($model)) {
            return 'responses';
        }

        if (str_starts_with($model, 'gpt-image-')) {
            return 'gpt_image';
        }

        return 'dalle';
    }

    private function supportsResponsesApi(string $model): bool
    {
        if (str_starts_with($model, 'gpt-image-')) {
            return true;
        }

        $responsesModels = ['gpt-5', 'gpt-5-mini', 'gpt-5.2', 'gpt-4.1', 'gpt-4o'];

        foreach ($responsesModels as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate image using OpenAI Responses API with input images (pic2pic).
     *
     * @param string   $prompt     Text instruction
     * @param string[] $imagePaths Absolute paths to input images
     *
     * @see https://developers.openai.com/api/docs/guides/image-generation
     */
    private function generateImageWithResponsesApi(string $prompt, array $imagePaths, array $options = []): array
    {
        try {
            $model = $options['model'] ?? 'gpt-image-1.5';
            $responsesModel = $this->pickResponsesModel($model);

            $this->logger->info('OpenAI: Pic2pic via Responses API', [
                'model' => $responsesModel,
                'image_model' => $model,
                'image_count' => \count($imagePaths),
                'prompt_length' => \strlen($prompt),
            ]);

            $contentParts = [['type' => 'input_text', 'text' => $prompt]];
            foreach ($imagePaths as $imgPath) {
                $data = file_get_contents($imgPath);
                if (false === $data) {
                    throw new \Exception('Failed to read image: '.basename($imgPath));
                }
                $mime = mime_content_type($imgPath) ?: 'image/png';
                $contentParts[] = [
                    'type' => 'input_image',
                    'image_url' => 'data:'.$mime.';base64,'.base64_encode($data),
                ];
            }

            $requestBody = [
                'model' => $responsesModel,
                'input' => [['role' => 'user', 'content' => $contentParts]],
                'tools' => [['type' => 'image_generation']],
            ];

            $ch = curl_init('https://api.openai.com/v1/responses');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$this->apiKey,
                ],
                CURLOPT_POSTFIELDS => json_encode($requestBody),
                CURLOPT_TIMEOUT => 180,
            ]);

            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new \Exception('cURL error: '.$curlError);
            }

            if (200 !== $httpCode) {
                $this->logger->error('OpenAI Responses API: HTTP error', [
                    'http_code' => $httpCode,
                    'response' => substr((string) $responseBody, 0, 500),
                ]);
                throw new \Exception('HTTP '.$httpCode.': '.$responseBody);
            }

            $response = json_decode((string) $responseBody, true);
            if (!$response || !isset($response['output'])) {
                throw new \Exception('Failed to parse Responses API response');
            }

            $images = [];
            foreach ($response['output'] as $output) {
                if ('image_generation_call' === ($output['type'] ?? null) && !empty($output['result'])) {
                    $images[] = [
                        'url' => 'data:image/png;base64,'.$output['result'],
                        'b64_json' => $output['result'],
                        'revised_prompt' => $output['revised_prompt'] ?? $prompt,
                    ];
                }
            }

            if (empty($images)) {
                $this->logger->error('OpenAI Responses API: No images in output', [
                    'output_types' => array_column($response['output'] ?? [], 'type'),
                ]);
                throw new ProviderException('Responses API returned no generated images', 'openai');
            }

            return $images;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI Responses API pic2pic error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Pick the mainline model to use with the Responses API image generation tool.
     * Image-specific models (gpt-image-*) need a mainline model wrapper.
     */
    private function pickResponsesModel(string $imageModel): string
    {
        if (str_starts_with($imageModel, 'gpt-image-')) {
            return 'gpt-4.1';
        }

        return $imageModel;
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            // Convert URL to file resource
            $imageContent = file_get_contents($imageUrl);
            if (false === $imageContent) {
                throw new \Exception('Failed to download image from URL');
            }

            $tmpFile = tmpfile();
            fwrite($tmpFile, $imageContent);
            $tmpPath = stream_get_meta_data($tmpFile)['uri'];

            $response = $this->client->images()->variation([
                'image' => fopen($tmpPath, 'r'),
                'n' => $count,
                'size' => '1024x1024',
            ]);

            fclose($tmpFile);

            $variations = [];
            foreach ($response['data'] as $image) {
                $variations[] = [
                    'url' => $image['url'] ?? null,
                    'b64_json' => $image['b64_json'] ?? null,
                ];
            }

            return $variations;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI image variations error: '.$e->getMessage(), 'openai');
        }
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            // Download images
            $imageContent = file_get_contents($imageUrl);
            $maskContent = file_get_contents($maskUrl);

            if (false === $imageContent || false === $maskContent) {
                throw new \Exception('Failed to download image or mask');
            }

            // Create temp files
            $tmpImage = tmpfile();
            $tmpMask = tmpfile();
            fwrite($tmpImage, $imageContent);
            fwrite($tmpMask, $maskContent);
            $tmpImagePath = stream_get_meta_data($tmpImage)['uri'];
            $tmpMaskPath = stream_get_meta_data($tmpMask)['uri'];

            $response = $this->client->images()->edit([
                'image' => fopen($tmpImagePath, 'r'),
                'mask' => fopen($tmpMaskPath, 'r'),
                'prompt' => $prompt,
                'n' => 1,
                'size' => '1024x1024',
            ]);

            fclose($tmpImage);
            fclose($tmpMask);

            return $response['data'][0]['url'] ?? '';
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI image edit error: '.$e->getMessage(), 'openai');
        }
    }

    // ==================== VISION ====================

    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        // Use analyzeImage internally
        $defaultPrompt = 'Describe what you see in this image in detail.';

        return $this->analyzeImage($imageUrl, $prompt ?: $defaultPrompt, $options);
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return $this->analyzeImage($imageUrl, 'Extract all text from this image. Return only the text, nothing else.');
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            // Build full paths
            $fullPath1 = $this->uploadDir.'/'.ltrim($imageUrl1, '/');
            $fullPath2 = $this->uploadDir.'/'.ltrim($imageUrl2, '/');

            if (!file_exists($fullPath1) || !file_exists($fullPath2)) {
                throw new \Exception('One or both images not found');
            }

            // Read images and convert to base64
            $imageData1 = file_get_contents($fullPath1);
            $imageData2 = file_get_contents($fullPath2);
            $base64Image1 = base64_encode($imageData1);
            $base64Image2 = base64_encode($imageData2);
            $mimeType1 = mime_content_type($fullPath1);
            $mimeType2 = mime_content_type($fullPath2);

            $response = $this->client->chat()->create([
                'model' => 'gpt-4o',
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Compare these two images and describe the similarities and differences.',
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType1};base64,{$base64Image1}",
                            ],
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType2};base64,{$base64Image2}",
                            ],
                        ],
                    ],
                ]],
                'max_tokens' => 1000,
            ]);

            $comparison = $response['choices'][0]['message']['content'] ?? '';

            return [
                'comparison' => $comparison,
                'image1' => basename($imageUrl1),
                'image2' => basename($imageUrl2),
            ];
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI image comparison error: '.$e->getMessage(), 'openai');
        }
    }

    public function analyzeImage(string $imagePath, string $prompt, array $options = []): string
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        $model = $options['model'] ?? 'gpt-4o';

        // Build full path
        $fullPath = $this->uploadDir.'/'.ltrim($imagePath, '/');

        // Check if file exists
        if (!file_exists($fullPath)) {
            throw new ProviderException('OpenAI vision error: Image file not found: '.$fullPath, 'openai');
        }

        // Read image and convert to a data URL
        $imageData = file_get_contents($fullPath);
        $base64Image = base64_encode($imageData);
        $mimeType = mime_content_type($fullPath);
        $dataUrl = "data:{$mimeType};base64,{$base64Image}";

        $this->logger->info('OpenAI: Analyzing image', [
            'model' => $model,
            'image' => basename($imagePath),
            'prompt_length' => strlen($prompt),
        ]);

        // Reasoning-tier models (o-series, gpt-5+) are served by the Responses
        // API. The "pro" tiers in particular (gpt-5-pro, gpt-5.5-pro, o*-pro)
        // are ONLY available there and reject v1/chat/completions with
        // "This is not a chat model and thus not supported in the
        // v1/chat/completions endpoint" — which silently produced empty OCR.
        // Route them through the Responses API exactly like chat()/chatStream()
        // already do; older multimodal chat models (gpt-4o, gpt-4.1, …) keep
        // the Chat Completions vision path.
        if ($this->usesCompletionTokens($model)) {
            return $this->analyzeImageViaResponses($model, $prompt, $dataUrl, $options);
        }

        try {
            $payload = [
                'model' => $model,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $dataUrl,
                            ],
                        ],
                    ],
                ]],
                'max_tokens' => $options['max_tokens'] ?? 1000,
            ];

            $response = $this->client->chat()->create($payload);

            return $response['choices'][0]['message']['content'] ?? '';
        } catch (\Exception $e) {
            // Safety net: if a model we treated as chat-compatible turns out to
            // be Responses-only, retry there instead of failing the OCR.
            if ($this->isNotAChatModelError($e)) {
                $this->logger->warning('OpenAI: model rejected by v1/chat/completions, retrying image analysis via Responses API', [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);

                return $this->analyzeImageViaResponses($model, $prompt, $dataUrl, $options);
            }

            throw new ProviderException('OpenAI vision error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Vision via the Responses API — the path for reasoning models (o-series,
     * gpt-5+) and the Responses-only "pro" tiers that v1/chat/completions
     * rejects.
     *
     * Mirrors the request shaping in {@see self::chat()} (input turns,
     * per-model reasoning config, the invalid-reasoning-tier fallback in
     * {@see self::executeResponsesCreate()}) but with a single user turn that
     * carries the prompt plus the image as an `input_image` part.
     */
    private function analyzeImageViaResponses(string $model, string $prompt, string $dataUrl, array $options): string
    {
        try {
            // Default OCR/vision to the model's lowest reasoning tier so the
            // output-token budget isn't consumed by chain-of-thought (and
            // latency stays low). Callers may override via reasoning_effort.
            $options['reasoning_effort'] ??= 'lowest';

            $requestOptions = [
                'model' => $model,
                'input' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $prompt],
                        ['type' => 'input_image', 'image_url' => $dataUrl],
                    ],
                ]],
                'store' => $this->storeResponses,
                // Reasoning tokens count against this budget, so give OCR of a
                // full page enough headroom regardless of the caller's hint.
                'max_output_tokens' => max((int) ($options['max_tokens'] ?? 0), self::DEFAULT_MAX_TOKENS),
            ];

            $reasoningConfig = $this->resolveReasoningConfig($model, true, $options);
            if (null !== $reasoningConfig) {
                $requestOptions['reasoning'] = $reasoningConfig;
            }

            $response = $this->executeResponsesCreate($requestOptions);
            $text = $response->outputText ?? '';

            if ('' === trim($text)) {
                // A reasoning model can spend the whole output-token budget on
                // chain-of-thought and return no visible text (status
                // "incomplete", reason "max_output_tokens"). The "pro" tiers
                // force at least `medium` effort and can't be dialed down, so
                // they routinely truncate or time out on synchronous OCR.
                // Surface this as a failure so the caller falls back to another
                // vision provider instead of silently accepting an empty result.
                $arr = $response->toArray();
                $status = (string) ($arr['status'] ?? 'unknown');
                $reason = $arr['incomplete_details']['reason'] ?? null;

                throw new ProviderException(sprintf('OpenAI vision returned no text (model=%s, status=%s%s). Reasoning-heavy models such as the "pro" tiers are a poor fit for synchronous OCR; select a non-pro vision model (e.g. gpt-5.5).', $model, $status, null !== $reason ? ', reason='.$reason : ''), 'openai');
            }

            return $text;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI vision error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Detect the HTTP 400 OpenAI returns when a Responses-only model (e.g. the
     * "pro" reasoning tiers) is called on v1/chat/completions:
     *   "This is not a chat model and thus not supported in the
     *    v1/chat/completions endpoint. Did you mean to use v1/completions?".
     */
    private function isNotAChatModelError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'not a chat model')
            || str_contains($message, 'v1/chat/completions');
    }

    // ==================== SPEECH TO TEXT (Whisper) ====================

    public function transcribe(string $audioPath, array $options = []): array
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'whisper-1';

            // Handle both absolute and relative paths
            $fullPath = str_starts_with($audioPath, '/')
                ? $audioPath
                : $this->uploadDir.'/'.ltrim($audioPath, '/');

            if (!file_exists($fullPath)) {
                throw new \Exception("Audio file not found: {$fullPath}");
            }

            $this->logger->info('OpenAI: Transcribing audio', [
                'model' => $model,
                'file' => basename($audioPath),
                'path' => $fullPath,
            ]);

            // Open file and ensure it's a valid resource
            $fileHandle = fopen($fullPath, 'r');
            if (!$fileHandle) {
                throw new \Exception("Failed to open audio file: {$fullPath}");
            }

            try {
                // Build request params - only include language if provided
                $requestParams = [
                    'model' => $model,
                    'file' => $fileHandle,
                    'response_format' => 'verbose_json',
                ];

                if (!empty($options['language'])) {
                    $requestParams['language'] = $options['language'];
                }

                $response = $this->client->audio()->transcribe($requestParams);

                return [
                    'text' => $response['text'] ?? '',
                    'language' => $response['language'] ?? 'unknown',
                    'duration' => $response['duration'] ?? 0,
                    'segments' => $response['segments'] ?? [],
                ];
            } finally {
                if (is_resource($fileHandle)) {
                    fclose($fileHandle);
                }
            }
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI transcription error: '.$e->getMessage(), 'openai');
        }
    }

    public function translateAudio(string $audioPath, string $targetLang): string
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            // Handle both absolute and relative paths
            $fullPath = str_starts_with($audioPath, '/')
                ? $audioPath
                : $this->uploadDir.'/'.ltrim($audioPath, '/');

            if (!file_exists($fullPath)) {
                throw new \Exception("Audio file not found: {$fullPath}");
            }

            $this->logger->info('OpenAI: Translating audio', [
                'file' => basename($audioPath),
                'target_lang' => $targetLang,
            ]);

            // Open file and ensure it's a valid resource
            $fileHandle = fopen($fullPath, 'r');
            if (!$fileHandle) {
                throw new \Exception("Failed to open audio file: {$fullPath}");
            }

            try {
                // Whisper's translate endpoint translates to English only
                $response = $this->client->audio()->translate([
                    'model' => 'whisper-1',
                    'file' => $fileHandle,
                ]);

                return $response['text'] ?? '';
            } finally {
                if (is_resource($fileHandle)) {
                    fclose($fileHandle);
                }
            }
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI audio translation error: '.$e->getMessage(), 'openai');
        }
    }

    // ==================== TEXT TO SPEECH ====================

    public function synthesize(string $text, array $options = []): string
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'tts-1';
            $voice = $options['voice'] ?? 'alloy'; // alloy, echo, fable, onyx, nova, shimmer

            $this->logger->info('OpenAI: Synthesizing speech', [
                'model' => $model,
                'voice' => $voice,
                'text_length' => strlen($text),
            ]);

            $response = $this->client->audio()->speech([
                'model' => $model,
                'voice' => $voice,
                'input' => $text,
                'response_format' => $options['format'] ?? 'mp3', // mp3, opus, aac, flac
                'speed' => $options['speed'] ?? 1.0,
            ]);

            // Save to temporary file with proper permissions
            $filename = 'tts_'.uniqid().'.mp3';
            $outputPath = $this->uploadDir.'/'.$filename;

            if (!FileHelper::createDirectory($this->uploadDir)) {
                throw new \RuntimeException('Unable to create upload directory: '.$this->uploadDir);
            }

            if (!is_writable($this->uploadDir)) {
                throw new \RuntimeException('Upload directory is not writable: '.$this->uploadDir);
            }

            FileHelper::writeFile($outputPath, $response);

            return $filename;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI TTS error: '.$e->getMessage(), 'openai');
        }
    }

    public function synthesizeStream(string $text, array $options = []): \Generator
    {
        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        $model = $options['model'] ?? 'tts-1';
        $voice = $options['voice'] ?? 'alloy';
        $format = $options['format'] ?? 'mp3';
        $speed = $options['speed'] ?? 1.0;

        $this->logger->info('OpenAI: Streaming TTS', [
            'model' => $model,
            'voice' => $voice,
            'format' => $format,
            'text_length' => strlen($text),
        ]);

        $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/audio/speech', [
            'headers' => [
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'voice' => $voice,
                'input' => $text,
                'response_format' => $format,
                'speed' => $speed,
            ],
            'buffer' => false,
            'timeout' => 120,
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new ProviderException('OpenAI TTS stream HTTP '.$response->getStatusCode().': '.substr($response->getContent(false), 0, 500), 'openai');
        }

        foreach ($this->httpClient->stream($response) as $chunk) {
            $content = $chunk->getContent();
            if ('' !== $content) {
                yield $content;
            }
        }
    }

    public function getStreamContentType(array $options = []): string
    {
        $format = $options['format'] ?? 'mp3';

        return match ($format) {
            'opus' => 'audio/ogg',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            default => 'audio/mpeg',
        };
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function getVoices(): array
    {
        // OpenAI TTS voices (static list)
        return [
            [
                'id' => 'alloy',
                'name' => 'Alloy',
                'description' => 'Neutral and balanced voice',
            ],
            [
                'id' => 'echo',
                'name' => 'Echo',
                'description' => 'Male voice',
            ],
            [
                'id' => 'fable',
                'name' => 'Fable',
                'description' => 'British accent',
            ],
            [
                'id' => 'onyx',
                'name' => 'Onyx',
                'description' => 'Deep male voice',
            ],
            [
                'id' => 'nova',
                'name' => 'Nova',
                'description' => 'Female voice',
            ],
            [
                'id' => 'shimmer',
                'name' => 'Shimmer',
                'description' => 'Warm female voice',
            ],
        ];
    }
}
