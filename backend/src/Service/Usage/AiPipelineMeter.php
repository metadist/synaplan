<?php

declare(strict_types=1);

namespace App\Service\Usage;

use App\AI\Pipeline\AiAction;
use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallOutcome;
use App\AI\Pipeline\AiCallRefusal;
use App\AI\Pipeline\AiCallResult;
use App\AI\Pipeline\AiCallSource;
use App\AI\Pipeline\AiCapability;
use App\AI\Pipeline\UsageHookConfig;
use App\Entity\User;
use App\Observability\EventRingStore;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Coordinates pipeline-level rate-limit checks, `BUSELOG` metering, shadow-mode
 * comparison (`ai.usage_mismatch`), and {@see TurnUsageLedger} aggregation for
 * {@see \App\AI\Pipeline\Hook\RateLimitHook}, {@see \App\AI\Pipeline\Hook\UsageHook},
 * and {@see LegacyUsageWriter} (`03_contracts.md` §1.3, §1.5, S1 AU11).
 */
final class AiPipelineMeter implements ResetInterface
{
    private const CAPABILITY_TAGS = [
        'chat' => 'chat',
        'vision' => 'pic2text',
        'embedding' => 'vectorize',
        'image_generation' => 'text2pic',
        'video_generation' => 'text2vid',
        'speech_to_text' => 'sound2text',
        'text_to_speech' => 'text2sound',
    ];

    /** @var array<string, list<RecordedUsage>> keyed by "{userId}:{action}" */
    private array $recordedByHook = [];

    /** @var array<string, list<array{action: string, provider: string, model: string, prompt_tokens: int, completion_tokens: int, total_tokens: int, cost: string}>> */
    private array $shadowExpectations = [];

    /** @var array<string, bool> keyed by "{userId}:{action}" */
    private array $admittedInRequest = [];

    private ?string $activeTurnId = null;

    private int $callSequence = 0;

    /** @var (\Closure(array<string, mixed>): array<string, mixed>)|null */
    private ?\Closure $shadowPerturbation = null;

    public function __construct(
        private readonly RateLimitService $rateLimitService,
        private readonly CostCalculationService $costCalculationService,
        private readonly UserRepository $users,
        private readonly ModelRepository $models,
        private readonly UsageHookConfig $config,
        private readonly TurnUsageLedger $turnLedger = new TurnUsageLedger(),
        private readonly ?EventRingStore $eventRing = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        LegacyUsageWriter::bind($this);
    }

    public function config(): UsageHookConfig
    {
        return $this->config;
    }

    public function turnLedger(): TurnUsageLedger
    {
        return $this->turnLedger;
    }

    /**
     * Inject a perturbation for shadow-mode mismatch testing.
     *
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $perturbation
     */
    public function setShadowPerturbation(?\Closure $perturbation): void
    {
        $this->shadowPerturbation = $perturbation;
    }

    public function markAdmitted(int $userId, string $action): void
    {
        $this->admittedInRequest[$userId.':'.$action] = true;
    }

    public function checkRateLimit(AiCall $call): ?AiCallRefusal
    {
        if (!$this->config->isOn() || !$call->context->billable || $call->options->callerMeters) {
            return null;
        }

        $userId = $call->context->userId;
        if (null === $userId || $userId <= 0) {
            return null;
        }

        $action = self::rateLimitActionForCapability($call->capability);
        if (null === $action) {
            return null;
        }

        $key = $userId.':'.$action;
        if ($this->admittedInRequest[$key] ?? false) {
            return null;
        }

        $user = $this->users->find($userId);
        if (!$user instanceof User) {
            return null;
        }

        $this->admittedInRequest[$key] = true;
        $check = $this->rateLimitService->checkLimit($user, $action);
        if (!($check['allowed'] ?? true)) {
            return AiCallRefusal::rateLimit($call->provider, 'Rate limit exceeded.');
        }

        return null;
    }

    public function prepareTurnContext(AiCall $call): AiCall
    {
        $turnId = $call->context->turnId;
        if (null === $turnId || '' === $turnId) {
            $optTurnId = $call->options->providerOptions['turn_id'] ?? null;
            $turnId = \is_string($optTurnId) && '' !== $optTurnId
                ? $optTurnId
                : ($call->context->runId ?? $call->context->requestId ?? 'turn-default');
        }
        $this->activeTurnId = $turnId;

        return $call->withContext(new \App\AI\Pipeline\AiCallContext(
            userId: $call->context->userId,
            source: $call->context->source,
            requestId: $call->context->requestId,
            turnId: $turnId,
            runId: $call->context->runId,
            messageId: $call->context->messageId,
            billable: $call->context->billable,
            startedHrtimeNs: $call->context->startedHrtimeNs,
        ));
    }

    public function onCallAfter(AiCall $call, AiCallResult $result): void
    {
        if (AiCallOutcome::Ok !== $result->outcome || !$call->context->billable) {
            return;
        }

        $userId = $call->context->userId;
        if (null === $userId || $userId <= 0) {
            return;
        }

        $user = $this->users->find($userId);
        if (!$user instanceof User) {
            return;
        }

        $spec = $this->buildCallUsageSpec($call, $result);
        if (null === $spec) {
            return;
        }

        [$action, $metadata] = $spec;
        $turnId = $call->context->turnId ?? $this->activeTurnId ?? 'turn-default';
        $callId = \is_string($call->options->providerOptions['call_id'] ?? null)
            ? (string) $call->options->providerOptions['call_id']
            : sprintf('call-%d', ++$this->callSequence);

        if ($this->config->isOn() && $call->context->source->isJoined()) {
            $recorded = $this->rateLimitService->recordUsage($user, $action, $metadata);
            $this->recordedByHook[$userId.':'.$action][] = $recorded;
            $this->recordTurnContribution($turnId, $callId, $call, $metadata, $recorded);

            return;
        }

        $preview = $this->previewUsage($metadata);
        $this->recordTurnContribution($turnId, $callId, $call, $metadata, $preview);

        if ($this->config->isShadow()) {
            $this->queueShadowExpectation($userId, $action, $call, $metadata, $preview);
        }
    }

    /**
     * Entry point called by {@see LegacyUsageWriter::record()}.
     *
     * @param array<string, mixed> $metadata
     */
    public function recordFromLegacyWriter(
        RateLimitService $rateLimitService,
        User $user,
        string $action,
        array $metadata = [],
        ?AiCallSource $explicitSource = null,
    ): RecordedUsage {
        $userId = (int) $user->getId();
        $source = $explicitSource ?? AiCallSource::fromChannel(
            \is_string($metadata['source'] ?? null) ? $metadata['source'] : null,
            AiCallSource::ChatWeb,
        );

        if ($this->config->isOn() && $source->isJoined()) {
            return $this->fulfillJoinedOnMode($rateLimitService, $user, $action, $metadata);
        }

        $recorded = $rateLimitService->recordUsage($user, $action, $metadata);

        if ($this->config->isShadow()) {
            $this->verifyShadow($userId, $action, $metadata, $recorded);
        }

        return $recorded;
    }

    public function reset(): void
    {
        $this->recordedByHook = [];
        $this->shadowExpectations = [];
        $this->admittedInRequest = [];
        $this->activeTurnId = null;
        $this->callSequence = 0;
        $this->shadowPerturbation = null;
        $this->turnLedger->reset();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function fulfillJoinedOnMode(
        RateLimitService $rateLimitService,
        User $user,
        string $action,
        array $metadata,
    ): RecordedUsage {
        $userId = (int) $user->getId();
        $key = $userId.':'.$action;

        if (!empty($this->recordedByHook[$key])) {
            /** @var RecordedUsage $alreadyRecorded */
            $alreadyRecorded = array_shift($this->recordedByHook[$key]);

            return $alreadyRecorded;
        }

        if (AiAction::Messages->value === $action) {
            $turnId = \is_string($metadata['turn_id'] ?? null) && '' !== $metadata['turn_id']
                ? (string) $metadata['turn_id']
                : $this->activeTurnId;
            unset($metadata['turn_id']);

            if (null !== $turnId && '' !== $turnId) {
                $closed = $this->turnLedger->close($turnId);
                if (null === $closed) {
                    return new RecordedUsage('0.000000', '0.000000', 0, 0, 0);
                }
                if ($closed->callCount > 0 && $closed->facadeMetered && FacadeMeteredChat::isMetered($metadata)) {
                    $metadata['facade_charged_cost'] = $closed->chargedCost;
                    $metadata['facade_price_known'] = $closed->priceKnown;
                }
            }
        }

        return $rateLimitService->recordUsage($user, $action, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function verifyShadow(int $userId, string $action, array $metadata, RecordedUsage $actual): void
    {
        $key = $userId.':'.$action;
        $expected = !empty($this->shadowExpectations[$key])
            ? array_shift($this->shadowExpectations[$key])
            : $this->buildShadowExpectationFromMetadata($action, $metadata);

        if (null !== $this->shadowPerturbation) {
            $expected = ($this->shadowPerturbation)($expected);
        }

        $actualProvider = \is_string($metadata['provider'] ?? null) ? $metadata['provider'] : '';
        $actualModel = \is_string($metadata['model'] ?? null) ? $metadata['model'] : '';
        $tokenDelta = $expected['total_tokens'] - $actual->totalTokens;
        $costDelta = round((float) $expected['cost'] - (float) $actual->rawCost, 6);

        if ($expected['action'] !== $action
            || $expected['provider'] !== $actualProvider
            || $expected['model'] !== $actualModel
            || 0 !== $tokenDelta
            || abs($costDelta) > 0.000001
        ) {
            $this->logger->warning('AI usage hook shadow mismatch', [
                'event' => 'ai.usage_mismatch',
                'action' => $action,
                'provider' => $actualProvider,
                'model' => $actualModel,
                'token_delta' => $tokenDelta,
                'cost_delta' => number_format($costDelta, 6, '.', ''),
            ]);

            $this->eventRing?->record([
                'level' => 'warning',
                'channel' => 'ai',
                'event' => 'ai.usage_mismatch',
                'message' => sprintf('Usage shadow mismatch on %s (token delta %d, cost delta %s)', $action, $tokenDelta, number_format($costDelta, 6, '.', '')),
                'user_id' => $userId,
                'provider' => '' !== $actualProvider ? $actualProvider : null,
                'model' => '' !== $actualModel ? $actualModel : null,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function queueShadowExpectation(
        int $userId,
        string $action,
        AiCall $call,
        array $metadata,
        RecordedUsage $preview,
    ): void {
        $this->shadowExpectations[$userId.':'.$action][] = [
            'action' => $action,
            'provider' => \is_string($metadata['provider'] ?? null) ? $metadata['provider'] : $call->provider,
            'model' => \is_string($metadata['model'] ?? null) ? $metadata['model'] : (string) $call->model,
            'prompt_tokens' => $preview->promptTokens,
            'completion_tokens' => $preview->completionTokens,
            'total_tokens' => $preview->totalTokens,
            'cost' => $preview->rawCost,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array{action: string, provider: string, model: string, prompt_tokens: int, completion_tokens: int, total_tokens: int, cost: string}
     */
    private function buildShadowExpectationFromMetadata(string $action, array $metadata): array
    {
        $preview = $this->previewUsage($metadata);

        return [
            'action' => $action,
            'provider' => \is_string($metadata['provider'] ?? null) ? $metadata['provider'] : '',
            'model' => \is_string($metadata['model'] ?? null) ? $metadata['model'] : '',
            'prompt_tokens' => $preview->promptTokens,
            'completion_tokens' => $preview->completionTokens,
            'total_tokens' => $preview->totalTokens,
            'cost' => $preview->rawCost,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordTurnContribution(
        string $turnId,
        string $callId,
        AiCall $call,
        array $metadata,
        RecordedUsage $recorded,
    ): void {
        if (!\in_array($call->capability, [AiCapability::Chat, AiCapability::ChatStream, AiCapability::Passthrough], true)
            || $call->options->callerMeters
        ) {
            return;
        }

        $usage = \is_array($metadata['usage'] ?? null) ? $metadata['usage'] : [];
        $this->turnLedger->addContribution($turnId, $callId, [
            'prompt_tokens' => $recorded->promptTokens,
            'completion_tokens' => $recorded->completionTokens,
            'total_tokens' => $recorded->totalTokens,
            'cached_tokens' => (int) ($usage['cached_tokens'] ?? 0),
            'cache_creation_tokens' => (int) ($usage['cache_creation_tokens'] ?? 0),
            'cache_creation_1h_tokens' => (int) ($usage['cache_creation_1h_tokens'] ?? 0),
            'charged_cost' => $recorded->chargedCost,
            'price_known' => $recorded->priceKnown,
            'facade_metered' => true,
            'provider' => $call->provider,
            'model' => (string) $call->model,
            'model_id' => isset($metadata['model_id']) && is_numeric($metadata['model_id']) ? (int) $metadata['model_id'] : null,
        ]);
    }

    /**
     * Build the `[action, metadata]` pair for a completed `AiCall` when the
     * pipeline hook has everything needed to meter it.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildCallUsageSpec(AiCall $call, AiCallResult $result): ?array
    {
        if (\in_array($call->capability, [AiCapability::Chat, AiCapability::ChatStream], true)) {
            return $this->buildChatUsageSpec($call, $result->value);
        }

        if (\in_array($call->capability, [AiCapability::Embed, AiCapability::EmbedBatch], true)) {
            return $this->buildEmbeddingUsageSpec($call, $result->value);
        }

        return $this->buildMediaUsageSpec($call, $result);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildChatUsageSpec(AiCall $call, mixed $value): ?array
    {
        $opts = $call->options->providerOptions;
        $billTo = \is_array($opts['_bill_to'] ?? null) ? $opts['_bill_to'] : [];
        $explicitAction = \is_string($billTo['action'] ?? null) && '' !== $billTo['action']
            ? $billTo['action']
            : null;

        if ($call->options->callerMeters && null === $explicitAction) {
            return null;
        }

        $action = $explicitAction ?? AiChatUsageMeter::ACTION;
        $provider = $call->provider;
        $model = (string) ($call->model ?? 'unknown');
        $usage = \is_array($value) && \is_array($value['usage'] ?? null) ? $value['usage'] : [];
        $modelId = $call->options->modelId
            ?? (isset($billTo['model_id']) && is_numeric($billTo['model_id']) ? (int) $billTo['model_id'] : null)
            ?? $this->resolveModelId('chat', $provider, $model);

        $metadata = [
            'provider' => $provider,
            'model' => $model,
            'model_id' => $modelId,
            'usage' => $usage,
            'input_text' => self::messagesText($call->payload->messages),
            'response_text' => \is_array($value) && \is_string($value['content'] ?? null) ? $value['content'] : '',
        ];
        if (AiChatUsageMeter::ACTION === $action) {
            $source = $billTo['source'] ?? null;
            $metadata['source'] = \is_string($source) && '' !== trim($source) ? trim($source) : 'ai_facade';
        }
        foreach (['chat_id', 'agentId', 'agentVersionId'] as $key) {
            if (isset($billTo[$key])) {
                $metadata[$key] = $billTo[$key];
            }
        }

        return [$action, $metadata];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildEmbeddingUsageSpec(AiCall $call, mixed $value): ?array
    {
        $opts = $call->options->providerOptions;
        $usageMeta = \is_array($opts['_usage_meta'] ?? null) ? $opts['_usage_meta'] : [];
        $action = \is_string($usageMeta['usage_action'] ?? null) ? $usageMeta['usage_action'] : null;
        if (null === $action) {
            return null;
        }
        $modelId = isset($usageMeta['model_id']) && is_numeric($usageMeta['model_id'])
            ? (int) $usageMeta['model_id']
            : ($call->options->modelId ?? $this->resolveModelId('embedding', $call->provider, $call->model));
        $usage = \is_array($value) && \is_array($value['usage'] ?? null)
            ? $value['usage']
            : ['prompt_tokens' => 0, 'total_tokens' => 0];

        $metadata = [
            'usage' => [
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            ],
            'provider' => $call->provider,
            'model' => (string) $call->model,
            'model_id' => $modelId,
            'input_bytes' => array_sum(array_map('strlen', $call->payload->texts)),
            'source' => \is_string($usageMeta['usage_source'] ?? null) ? $usageMeta['usage_source'] : 'VECTORIZATION',
        ];
        if (isset($usageMeta['usage_inputs'])) {
            $metadata['inputs'] = (int) $usageMeta['usage_inputs'];
        }

        return [$action, $metadata];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function buildMediaUsageSpec(AiCall $call, AiCallResult $result): ?array
    {
        $opts = $call->options->providerOptions;
        $value = $result->value;

        if (AiCapability::Image === $call->capability) {
            if (!\is_array($value) || [] === $value) {
                return null;
            }
            $usageMeta = \is_array($opts['_usage_meta'] ?? null) ? $opts['_usage_meta'] : [];
            $source = \is_string($usageMeta['usage_source'] ?? null) ? $usageMeta['usage_source'] : null;
            if (null === $source && (!\is_array($value[0] ?? null) || !isset($value[0]['url']))) {
                return null;
            }
            $modelId = isset($usageMeta['model_id']) && is_numeric($usageMeta['model_id'])
                ? (int) $usageMeta['model_id']
                : ($call->options->modelId ?? $this->resolveModelId('image_generation', $call->provider, $call->model));

            $metadata = [
                'provider' => $call->provider,
                'model' => (string) $call->model,
                'model_id' => $modelId,
                'media_usage' => array_filter([
                    'images' => (float) \count($value),
                    'quality' => $opts['quality'] ?? 'standard',
                    'size' => $opts['size'] ?? '1024x1024',
                ]),
            ];
            if (null !== $source) {
                $metadata['source'] = $source;
            }

            return [AiAction::Images->value, $metadata];
        }

        if (AiCapability::Synthesize === $call->capability) {
            $modelId = $call->options->modelId ?? $this->resolveModelId('text_to_speech', $call->provider, $call->model);
            $chars = null !== $result->usage ? $result->usage->characterCount : mb_strlen((string) $call->payload->prompt);

            return [AiAction::Audios->value, [
                'provider' => $call->provider,
                'model' => (string) $call->model,
                'model_id' => $modelId,
                'media_usage' => ['characters' => $chars],
            ]];
        }

        if (AiCapability::Transcribe === $call->capability) {
            if (!\is_array($value)) {
                return null;
            }
            $modelId = $call->options->modelId ?? $this->resolveModelId('speech_to_text', $call->provider, $call->model);
            $duration = (float) ($value['duration'] ?? 0) + (float) ($value['first_pass_duration'] ?? 0);
            if (null === $modelId || $duration <= 0.0) {
                return null;
            }

            return [AiAction::Transcription->value, [
                'provider' => $call->provider,
                'model' => (string) $call->model,
                'model_id' => $modelId,
                'media_usage' => ['duration_seconds' => $duration],
                'language' => $value['language'] ?? 'unknown',
            ]];
        }

        return null;
    }

    /**
     * Read-only preview of what {@see RateLimitService::recordUsage()} would
     * compute for `$metadata`, without writing to `BUSELOG`.
     *
     * @param array<string, mixed> $metadata
     */
    private function previewUsage(array $metadata): RecordedUsage
    {
        $usage = \is_array($metadata['usage'] ?? null) ? $metadata['usage'] : [];
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $cachedTokens = (int) ($usage['cached_tokens'] ?? 0);
        $cacheCreationTokens = (int) ($usage['cache_creation_tokens'] ?? 0);
        $cacheCreation1hTokens = max(0, (int) ($usage['cache_creation_1h_tokens'] ?? 0));
        $totalTokens = (int) ($usage['total_tokens'] ?? ($promptTokens + $completionTokens));

        $inputBytes = !empty($metadata['input_bytes']) ? (int) $metadata['input_bytes'] : 0;
        if (0 === $inputBytes && !empty($metadata['input_text']) && \is_string($metadata['input_text'])) {
            $inputBytes = \strlen($metadata['input_text']);
        }
        $outputBytes = (!empty($metadata['response_text']) && \is_string($metadata['response_text']) ? \strlen($metadata['response_text']) : 0)
            + (!empty($metadata['response_bytes']) ? (int) $metadata['response_bytes'] : 0);

        if (0 === $totalTokens && ($inputBytes + $outputBytes) > 0) {
            $totalTokens = RateLimitService::estimateTokens($inputBytes + $outputBytes);
        }
        if ($totalTokens > 0 && 0 === $promptTokens && 0 === $completionTokens) {
            if ($inputBytes > 0 && $outputBytes > 0) {
                $ratio = $inputBytes / ($inputBytes + $outputBytes);
                $promptTokens = (int) ceil($totalTokens * $ratio);
                $completionTokens = $totalTokens - $promptTokens;
            } else {
                $promptTokens = $totalTokens;
            }
        }

        $modelId = isset($metadata['model_id']) && is_numeric($metadata['model_id']) ? (int) $metadata['model_id'] : null;
        $mediaUsage = \is_array($metadata['media_usage'] ?? null) ? $metadata['media_usage'] : [];
        $pricingMode = $this->costCalculationService->getPricingMode($modelId);
        $timestamp = time();

        if (!empty($metadata['zero_cost'])) {
            $rawCost = 0.0;
            $priceKnown = true;
        } elseif ('per_token' !== $pricingMode && [] !== $mediaUsage) {
            $inputQty = match ($pricingMode) {
                'per_character' => (float) ($mediaUsage['characters'] ?? 0),
                'per_second' => (float) ($mediaUsage['duration_seconds'] ?? 0),
                'per_request' => (float) ($mediaUsage['requests'] ?? 1),
                default => 0.0,
            };
            $outputQty = match ($pricingMode) {
                'per_image' => (float) ($mediaUsage['images'] ?? 0),
                'per_second' => (float) ($mediaUsage['duration_seconds'] ?? 0),
                'per_generation' => (float) ($mediaUsage['clips'] ?? 1),
                default => 0.0,
            };
            $costResult = $this->costCalculationService->calculateMediaCost(
                $modelId,
                $inputQty,
                $outputQty,
                $timestamp,
                \is_string($mediaUsage['resolution'] ?? null) ? $mediaUsage['resolution'] : null,
                \is_string($mediaUsage['quality'] ?? null) ? $mediaUsage['quality'] : null,
                \is_string($mediaUsage['size'] ?? null) ? $mediaUsage['size'] : null,
            );
            $rawCost = (float) $costResult->totalCost;
            $priceKnown = false !== ($costResult->priceSnapshot['price_known'] ?? true);
        } else {
            $costResult = $this->costCalculationService->calculateCost(
                $promptTokens,
                $completionTokens,
                $cachedTokens,
                $cacheCreationTokens,
                $modelId,
                $timestamp,
                $cacheCreation1hTokens,
            );
            $rawCost = (float) $costResult->totalCost;
            $priceKnown = false !== ($costResult->priceSnapshot['price_known'] ?? true);
        }

        $chargedCost = $rawCost * (1.0 + ($this->rateLimitService->getMarkupPercent() / 100.0));

        return new RecordedUsage(
            chargedCost: number_format($chargedCost, 6, '.', ''),
            rawCost: number_format($rawCost, 6, '.', ''),
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            priceKnown: $priceKnown,
        );
    }

    private function resolveModelId(string $capability, string $providerName, ?string $providerModelId): ?int
    {
        if (null === $providerModelId || '' === trim($providerModelId)) {
            return null;
        }

        if ('chat' === $capability) {
            return $this->models->findChatIdByProvider($providerName, $providerModelId);
        }

        $tag = self::CAPABILITY_TAGS[$capability] ?? null;
        if (null === $tag) {
            return null;
        }

        try {
            return $this->models->findIdByServiceProviderIdAndTag($providerName, $providerModelId, $tag);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function rateLimitActionForCapability(AiCapability $capability): ?string
    {
        return match ($capability) {
            AiCapability::Chat, AiCapability::ChatStream, AiCapability::Passthrough => AiAction::Messages->value,
            AiCapability::Embed, AiCapability::EmbedBatch => AiAction::Embeddings->value,
            AiCapability::Image => AiAction::Images->value,
            AiCapability::VideoStart => AiAction::Videos->value,
            AiCapability::Transcribe => AiAction::Transcription->value,
            AiCapability::Synthesize, AiCapability::SynthesizeStream => AiAction::Audios->value,
            default => null,
        };
    }

    /**
     * @param list<mixed> $messages
     */
    private static function messagesText(array $messages): string
    {
        $parts = [];
        foreach ($messages as $message) {
            if (!\is_array($message)) {
                continue;
            }
            $content = $message['content'] ?? null;
            if (\is_string($content) && '' !== $content) {
                $parts[] = $content;
            }
        }

        return implode("\n", $parts);
    }
}
