<?php

namespace App\AI\Provider\OpenAI;

use App\AI\Provider\OpenAiReasoningEffort;
use App\Service\Chat\ProviderTranscriptCapture;
use Psr\Log\LoggerInterface;

/**
 * The Responses API for OpenAIChat and OpenAIVision: which models are
 * reasoning models (and so take a reasoning tier), the tier a request
 * resolves to, and the call itself with its two recoveries: a model that
 * rejects the tier is retried without it (and remembered), and an expired
 * previous response is retried with the full conversation.
 */
final class OpenAIResponses
{
    public const DEFAULT_MAX_TOKENS = 4096;

    private array $modelCapabilities = [];

    /**
     * Per-model cache of "this model rejects the reasoning.effort tier we
     * sent" outcomes. Populated by {@see self::create()} /
     * {@see self::createStreamed()} on the first HTTP 400 of
     * the form "Unsupported value: 'X' is not supported with the 'Y' model".
     * Subsequent requests for the same model skip the reasoning block
     * pre-emptively so we don't keep paying the round-trip-then-retry cost.
     *
     * @var array<string, true>
     */
    private array $reasoningRejectionCache = [];

    public function __construct(
        private readonly OpenAIApiClient $api,
        private readonly LoggerInterface $logger,
        private readonly ?ProviderTranscriptCapture $transcripts = null,
    ) {
    }

    /**
     * Check if model uses max_completion_tokens instead of max_tokens
     * Based on OpenAI API model capabilities (reasoning models use max_completion_tokens).
     *
     * @param string $model Model name
     *
     * @return bool True if model uses max_completion_tokens
     */
    public function usesCompletionTokens(string $model): bool
    {
        // Check cache first
        if (isset($this->modelCapabilities[$model])) {
            return $this->modelCapabilities[$model];
        }

        // Try to fetch model details from OpenAI API
        try {
            $modelInfo = $this->api->sdk()->models()->retrieve($model);
            // The SDK's sealed RetrieveResponse shape has no capabilities key,
            // but some gateways add one — inspect the raw payload instead.
            /** @var array<string, mixed> $modelData */
            $modelData = $modelInfo->toArray();
            $capabilities = is_array($modelData['capabilities'] ?? null) ? $modelData['capabilities'] : [];

            // Check if model has reasoning capabilities or is o-series/gpt-5+
            // Reasoning models (o1, o3, gpt-5, gpt-6) use max_completion_tokens
            $isReasoningModel = isset($capabilities['reasoning'])
                               || str_starts_with($model, 'o1')
                               || str_starts_with($model, 'o3')
                               || $this->isGptReasoningFamily($model);

            $this->modelCapabilities[$model] = $isReasoningModel;

            return $isReasoningModel;
        } catch (\Exception $e) {
            // If API call fails, use heuristic fallback
            $this->logger->warning('Failed to fetch model capabilities from OpenAI, using heuristic', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            // Heuristic: o-series and gpt-5+ models use max_completion_tokens
            $usesCompletionTokens = str_starts_with($model, 'o1')
                                   || str_starts_with($model, 'o3')
                                   || $this->isGptReasoningFamily($model);

            $this->modelCapabilities[$model] = $usesCompletionTokens;

            return $usesCompletionTokens;
        }
    }

    /**
     * Translate the cross-provider `reasoning_effort` knob into OpenAI's
     * Responses API `reasoning` config.
     *
     * Mirrors the logic in {@see \App\AI\Provider\Google\GoogleChat::resolveThinkingConfig()} so the
     * "default chat = no thinking, near-instant TTFT" behaviour is identical
     * across providers. The headline Phase 1e win on Gemini Pro
     * (`thinkingBudget=0` for default chat) translates to OpenAI as the
     * model family's lowest reasoning tier (see {@see OpenAiReasoningEffort}).
     * Without this, the model falls back to OpenAI's server-side default of `medium` and
     * burns 1-3 s of chain-of-thought before emitting the first visible token,
     * even on a "Hi, how are you?" style chat where the user did not enable
     * the Thinking toggle.
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
     * `medium`, `high`, `xhigh` or `max` — that's where chain-of-thought is
     * long enough for the SSE reasoning-summary stream to be useful.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, string>|null
     */
    public function resolveReasoningConfig(string $model, bool $isReasoningModel, array $options): ?array
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

        $resolvedEffort = match ($effort) {
            // 'lowest' is our internal sentinel for "lowest tier this model
            // accepts" (= the Phase 1e fast-default path). 'minimal' / 'none'
            // / 'off' / 'disabled' all map to the same intent: skip reasoning.
            'lowest', 'off', 'none', 'disabled', 'minimal' => $this->lowestEffortTier($model),
            // 'low' is absent on gpt-5.5-pro, so even this needs clamping.
            'low' => $this->clampEffort($model, ['low', 'medium']),
            'medium' => 'medium',
            'high' => 'high',
            // Most of the gpt-5.x line and gpt-6 expose 'xhigh' above 'high';
            // the original gpt-5 and the o-series cap at 'high'.
            'xhigh', 'extra-high', 'extreme' => $this->clampEffort($model, ['xhigh', 'high']),
            // gpt-5.6 and gpt-6 add 'max' above 'xhigh'.
            'max', 'maximum' => $this->clampEffort($model, ['max', 'xhigh', 'high']),
            default => null,
        };

        if (null === $resolvedEffort) {
            return null;
        }

        $config = ['effort' => $resolvedEffort];

        if (in_array($resolvedEffort, ['medium', 'high', 'xhigh', 'max'], true)) {
            $config['summary'] = 'auto';
        }

        return $config;
    }

    /**
     * Tiers the given model accepts, cheapest first.
     *
     * @return list<string>
     */
    private function reasoningEffortTiers(string $model): array
    {
        return OpenAiReasoningEffort::tiers($model);
    }

    /**
     * Cheapest `reasoning.effort` tier the given model accepts — `'none'` on
     * most of the gpt-5.x line and on gpt-6-sol / gpt-6-luna, `'minimal'` on
     * the original gpt-5, `'low'` on gpt-6-astra and the o-series, `'medium'`
     * on gpt-5.5-pro.
     */
    private function lowestEffortTier(string $model): string
    {
        return OpenAiReasoningEffort::lowest($model);
    }

    /**
     * First tier from $preferences that the model accepts.
     *
     * Callers pass the requested tier followed by its cheaper fallbacks, so a
     * cross-provider `'max'` clamps down on a family that caps lower instead of
     * returning HTTP 400.
     *
     * @param list<string> $preferences requested tier first, then fallbacks
     */
    private function clampEffort(string $model, array $preferences): string
    {
        $supported = $this->reasoningEffortTiers($model);

        foreach ($preferences as $tier) {
            if (in_array($tier, $supported, true)) {
                return $tier;
            }
        }

        // Every family accepts 'high'.
        return 'high';
    }

    /**
     * GPT reasoning families that use the Responses API and
     * `max_completion_tokens` (gpt-5, gpt-6, and later dated snapshots).
     */
    private function isGptReasoningFamily(string $model): bool
    {
        return str_starts_with($model, 'gpt-5') || str_starts_with($model, 'gpt-6');
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
    public function create(array $requestOptions): mixed
    {
        return $this->executeResponsesRequest($requestOptions, false);
    }

    public function createStreamed(array $requestOptions): mixed
    {
        return $this->executeResponsesRequest($requestOptions, true);
    }

    private function executeResponsesRequest(array $requestOptions, bool $stream): mixed
    {
        $requestOptions = $this->applyReasoningRejectionCache($requestOptions);

        $fullInput = $requestOptions['input'] ?? [];
        $hasPreviousResponse = isset($requestOptions['previous_response_id']);
        $hasReasoning = isset($requestOptions['reasoning']);
        $model = (string) ($requestOptions['model'] ?? '');

        if ($hasPreviousResponse) {
            $requestOptions['input'] = OpenAIResponsesFormat::reduceToLastUserMessage($fullInput);
        }
        $this->transcripts?->recordResponsesRequest($requestOptions);

        $send = fn (array $opts): mixed => $stream
            ? $this->api->sdk()->responses()->createStreamed($opts)
            : $this->api->sdk()->responses()->create($opts);

        try {
            return $send($requestOptions);
        } catch (\Exception $e) {
            if ($hasReasoning && $this->isReasoningEffortRejectedError($e)) {
                $this->rememberReasoningRejection($model, $requestOptions['reasoning'] ?? [], $e);
                unset($requestOptions['reasoning']);

                return $send($requestOptions);
            }

            if ($hasPreviousResponse && $this->isPreviousResponseError($e)) {
                $this->logger->warning(
                    $stream
                        ? 'OpenAI: previous_response_id invalid for stream, retrying with full context'
                        : 'OpenAI: previous_response_id invalid, retrying with full context',
                    [
                        'previous_response_id' => $requestOptions['previous_response_id'],
                        'error' => $e->getMessage(),
                    ],
                );
                unset($requestOptions['previous_response_id']);
                $requestOptions['input'] = $fullInput;
                $this->transcripts?->recordResponsesRequest($requestOptions);

                return $send($requestOptions);
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

        foreach (['minimal', 'none', 'low', 'medium', 'high', 'xhigh', 'max'] as $tier) {
            if (str_contains($message, "'".$tier."'")) {
                return true;
            }
        }

        return false;
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
}
