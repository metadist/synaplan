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
    /** @var array<string, list<RecordedUsage>> keyed by "{userId}:{action}" */
    private array $recordedByHook = [];

    /** @var array<string, list<array{action: string, provider: string, model: string, prompt_tokens: int, completion_tokens: int, total_tokens: int, cost: string, charged_cost: string, price_known: bool}>> */
    private array $shadowExpectations = [];

    /** @var array<string, bool> keyed by "{userId}:{action}" */
    private array $admittedInRequest = [];

    private ?string $activeTurnId = null;

    private int $turnSequence = 0;

    private int $callSequence = 0;

    /** @var (\Closure(array<string, mixed>): array<string, mixed>)|null */
    private ?\Closure $shadowPerturbation = null;

    private readonly AiUsageSpecBuilder $specBuilder;

    public function __construct(
        private readonly RateLimitService $rateLimitService,
        CostCalculationService $costCalculationService,
        private readonly UserRepository $users,
        ModelRepository $models,
        private readonly UsageHookConfig $config,
        private readonly TurnUsageLedger $turnLedger = new TurnUsageLedger(),
        private readonly ?EventRingStore $eventRing = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->specBuilder = new AiUsageSpecBuilder($costCalculationService, $rateLimitService, $models);
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

        $check = $this->rateLimitService->checkLimit($user, $action);
        if (!($check['allowed'] ?? true)) {
            return AiCallRefusal::rateLimit($call->provider, 'Rate limit exceeded.');
        }

        $this->admittedInRequest[$key] = true;

        return null;
    }

    public function prepareTurnContext(AiCall $call): AiCall
    {
        if ($this->config->isLegacy()) {
            return $call;
        }

        $userId = $call->context->userId ?? 0;
        $turnId = $call->context->turnId;
        if (null === $turnId || '' === $turnId) {
            $optTurnId = $call->options->providerOptions['turn_id'] ?? null;
            $baseId = \is_string($optTurnId) && '' !== $optTurnId
                ? $optTurnId
                : ($call->context->runId ?? $this->activeTurnId ?? sprintf('turn-%d-%s', ++$this->turnSequence, bin2hex(random_bytes(6))));
            $turnId = self::scopeTurnId($userId, $baseId);
        } else {
            $turnId = self::scopeTurnId($userId, $turnId);
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
        if ($this->config->isLegacy() || AiCallOutcome::Ok !== $result->outcome || !$call->context->billable) {
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

        $spec = $this->specBuilder->build($call, $result);
        if (null === $spec) {
            return;
        }

        [$action, $metadata] = $spec;
        $turnId = $call->context->turnId ?? $this->activeTurnId ?? self::scopeTurnId($userId, 'turn-default');
        $callId = \is_string($call->options->providerOptions['call_id'] ?? null)
            ? (string) $call->options->providerOptions['call_id']
            : sprintf('call-%d', ++$this->callSequence);

        if ($call->options->callerMeters) {
            return;
        }

        if ($this->config->isOn() && $call->context->source->isJoined()) {
            $recorded = $this->rateLimitService->recordUsage($user, $action, $metadata);
            $this->recordedByHook[$userId.':'.$action][] = $recorded;
            $this->recordTurnContribution($turnId, $callId, $call, $metadata, $recorded);

            return;
        }

        $preview = $this->specBuilder->preview($metadata);
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

        $rawTurnId = \is_string($metadata['turn_id'] ?? null) && '' !== $metadata['turn_id']
            ? self::scopeTurnId($userId, (string) $metadata['turn_id'])
            : $this->activeTurnId;
        unset($metadata['turn_id']);
        $recorded = $rateLimitService->recordUsage($user, $action, $metadata);

        if ($this->config->isShadow()) {
            $this->verifyShadow($userId, $action, $metadata, $recorded, $rawTurnId);
        }

        return $recorded;
    }

    public function reset(): void
    {
        $this->recordedByHook = [];
        $this->shadowExpectations = [];
        $this->admittedInRequest = [];
        $this->activeTurnId = null;
        $this->turnSequence = 0;
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
            $receipts = $this->recordedByHook[$key];
            $this->recordedByHook[$key] = [];
            if (1 === \count($receipts)) {
                return $receipts[0];
            }

            return self::foldReceipts($receipts);
        }

        if (AiAction::Messages->value === $action) {
            $turnId = \is_string($metadata['turn_id'] ?? null) && '' !== $metadata['turn_id']
                ? self::scopeTurnId($userId, (string) $metadata['turn_id'])
                : $this->activeTurnId;
            $this->activeTurnId = null;
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
    private function verifyShadow(
        int $userId,
        string $action,
        array $metadata,
        RecordedUsage $actual,
        ?string $turnId = null,
    ): void {
        $key = $userId.':'.$action;
        if (!empty($this->shadowExpectations[$key])) {
            $items = $this->shadowExpectations[$key];
            $this->shadowExpectations[$key] = [];
            $expected = 1 === \count($items) ? $items[0] : self::foldShadowExpectations($items);
        } elseif (AiAction::Messages->value === $action && null !== $turnId && '' !== $turnId) {
            $this->activeTurnId = null;
            $expected = $this->buildShadowMessagesExpectation($metadata, $turnId);
        } else {
            $expected = $this->buildShadowExpectationFromMetadata($action, $metadata);
        }

        if (null !== $this->shadowPerturbation) {
            $expected = ($this->shadowPerturbation)($expected);
        }

        $actualProvider = \is_string($metadata['provider'] ?? null) ? $metadata['provider'] : '';
        $actualModel = \is_string($metadata['model'] ?? null) ? $metadata['model'] : '';
        $tokenDelta = $expected['total_tokens'] - $actual->totalTokens;
        $costDelta = round((float) $expected['cost'] - (float) $actual->rawCost, 6);
        $chargedDelta = isset($expected['charged_cost'])
            ? round((float) $expected['charged_cost'] - (float) $actual->chargedCost, 6)
            : 0.0;
        $priceKnownMismatch = isset($expected['price_known']) && $expected['price_known'] !== $actual->priceKnown;

        if ($expected['action'] !== $action
            || $expected['provider'] !== $actualProvider
            || $expected['model'] !== $actualModel
            || 0 !== $tokenDelta
            || abs($costDelta) > 0.000001
            || abs($chargedDelta) > 0.000001
            || $priceKnownMismatch
        ) {
            $reportedCostDelta = abs($costDelta) > 0.000001 ? $costDelta : $chargedDelta;
            $this->logger->warning('AI usage hook shadow mismatch', [
                'event' => 'ai.usage_mismatch',
                'action' => $action,
                'provider' => $actualProvider,
                'model' => $actualModel,
                'token_delta' => $tokenDelta,
                'cost_delta' => number_format($reportedCostDelta, 6, '.', ''),
            ]);

            $this->eventRing?->record([
                'level' => 'warning',
                'channel' => 'ai',
                'event' => 'ai.usage_mismatch',
                'message' => sprintf('Usage shadow mismatch on %s (token delta %d, cost delta %s)', $action, $tokenDelta, number_format($reportedCostDelta, 6, '.', '')),
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
            'charged_cost' => $preview->chargedCost,
            'price_known' => $preview->priceKnown,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array{action: string, provider: string, model: string, prompt_tokens: int, completion_tokens: int, total_tokens: int, cost: string, charged_cost: string, price_known: bool}
     */
    private function buildShadowExpectationFromMetadata(string $action, array $metadata): array
    {
        $preview = $this->specBuilder->preview($metadata);

        return [
            'action' => $action,
            'provider' => \is_string($metadata['provider'] ?? null) ? $metadata['provider'] : '',
            'model' => \is_string($metadata['model'] ?? null) ? $metadata['model'] : '',
            'prompt_tokens' => $preview->promptTokens,
            'completion_tokens' => $preview->completionTokens,
            'total_tokens' => $preview->totalTokens,
            'cost' => $preview->rawCost,
            'charged_cost' => $preview->chargedCost,
            'price_known' => $preview->priceKnown,
        ];
    }

    /**
     * Build the shadow expectation for a per-turn `MESSAGES` row from the
     * {@see TurnUsageLedger}, comparing the ledger's aggregated `facade_charged_cost`
     * / `facade_price_known` (or token usage) against the caller's metadata.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array{action: string, provider: string, model: string, prompt_tokens: int, completion_tokens: int, total_tokens: int, cost: string, charged_cost: string, price_known: bool}
     */
    private function buildShadowMessagesExpectation(array $metadata, string $turnId): array
    {
        $closed = $this->turnLedger->close($turnId);
        $wouldBeMeta = $metadata;
        if (null !== $closed && $closed->callCount > 0 && $closed->facadeMetered && FacadeMeteredChat::isMetered($wouldBeMeta)) {
            $wouldBeMeta['facade_charged_cost'] = $closed->chargedCost;
            $wouldBeMeta['facade_price_known'] = $closed->priceKnown;
        }

        $preview = $this->specBuilder->preview($wouldBeMeta);
        if (FacadeMeteredChat::isMetered($wouldBeMeta)) {
            $actualCost = isset($metadata['facade_charged_cost']) && is_numeric($metadata['facade_charged_cost'])
                ? number_format((float) $metadata['facade_charged_cost'], 6, '.', '')
                : '0.000000';
            $ledgerCost = isset($wouldBeMeta['facade_charged_cost']) && is_numeric($wouldBeMeta['facade_charged_cost'])
                ? number_format((float) $wouldBeMeta['facade_charged_cost'], 6, '.', '')
                : '0.000000';
            $costDiff = round((float) $ledgerCost - (float) $actualCost, 6);
            $priceKnown = isset($wouldBeMeta['facade_price_known']) && \is_bool($wouldBeMeta['facade_price_known'])
                ? $wouldBeMeta['facade_price_known']
                : true;

            return [
                'action' => AiAction::Messages->value,
                'provider' => \is_string($metadata['provider'] ?? null) ? $metadata['provider'] : '',
                'model' => \is_string($metadata['model'] ?? null) ? $metadata['model'] : '',
                'prompt_tokens' => $preview->promptTokens,
                'completion_tokens' => $preview->completionTokens,
                'total_tokens' => $preview->totalTokens,
                'cost' => number_format($costDiff, 6, '.', ''),
                'charged_cost' => number_format($costDiff, 6, '.', ''),
                'price_known' => $priceKnown,
            ];
        }

        return [
            'action' => AiAction::Messages->value,
            'provider' => \is_string($metadata['provider'] ?? null) ? $metadata['provider'] : '',
            'model' => \is_string($metadata['model'] ?? null) ? $metadata['model'] : '',
            'prompt_tokens' => $preview->promptTokens,
            'completion_tokens' => $preview->completionTokens,
            'total_tokens' => $preview->totalTokens,
            'cost' => $preview->rawCost,
            'charged_cost' => $preview->chargedCost,
            'price_known' => $preview->priceKnown,
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

    private static function scopeTurnId(int $userId, string $turnId): string
    {
        $prefix = $userId.':';

        return str_starts_with($turnId, $prefix) ? $turnId : $prefix.$turnId;
    }

    /**
     * @param non-empty-list<RecordedUsage> $receipts
     */
    private static function foldReceipts(array $receipts): RecordedUsage
    {
        $charged = 0.0;
        $raw = 0.0;
        $prompt = 0;
        $completion = 0;
        $total = 0;
        $priceKnown = true;
        foreach ($receipts as $r) {
            $charged += (float) $r->chargedCost;
            $raw += (float) $r->rawCost;
            $prompt += $r->promptTokens;
            $completion += $r->completionTokens;
            $total += $r->totalTokens;
            if (!$r->priceKnown) {
                $priceKnown = false;
            }
        }

        return new RecordedUsage(
            chargedCost: number_format($charged, 6, '.', ''),
            rawCost: number_format($raw, 6, '.', ''),
            promptTokens: $prompt,
            completionTokens: $completion,
            totalTokens: $total,
            priceKnown: $priceKnown,
        );
    }

    /**
     * @param non-empty-list<array{action: string, provider: string, model: string, prompt_tokens: int, completion_tokens: int, total_tokens: int, cost: string, charged_cost: string, price_known: bool}> $items
     *
     * @return array{action: string, provider: string, model: string, prompt_tokens: int, completion_tokens: int, total_tokens: int, cost: string, charged_cost: string, price_known: bool}
     */
    private static function foldShadowExpectations(array $items): array
    {
        $last = $items[\count($items) - 1];
        $prompt = 0;
        $completion = 0;
        $total = 0;
        $cost = 0.0;
        $charged = 0.0;
        $priceKnown = true;
        foreach ($items as $item) {
            $prompt += $item['prompt_tokens'];
            $completion += $item['completion_tokens'];
            $total += $item['total_tokens'];
            $cost += (float) $item['cost'];
            $charged += (float) $item['charged_cost'];
            if (!$item['price_known']) {
                $priceKnown = false;
            }
        }

        return [
            'action' => $last['action'],
            'provider' => $last['provider'],
            'model' => $last['model'],
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total,
            'cost' => number_format($cost, 6, '.', ''),
            'charged_cost' => number_format($charged, 6, '.', ''),
            'price_known' => $priceKnown,
        ];
    }
}
