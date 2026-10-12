<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\DTO\CostResult;
use App\Entity\Model;
use App\Entity\User;
use App\Entity\VoiceSession;
use App\Exception\VoiceLiveBusyException;
use App\Repository\ConfigRepository;
use App\Repository\VoiceSessionRepository;
use App\Service\CostCalculationService;
use App\Service\PremiumFeatureGate;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One BUSELOG row per live conversation, updated on every tick.
 *
 * The row's cost is the voice cost plus the chat model's answers. It feeds
 * the same monthly budget as chat, images and video. There is no minutes quota.
 */
final readonly class VoiceLiveUsageService
{
    public const ACTION = 'VOICE_LIVE';

    private const MAX_SESSION_SECONDS = 1800;

    private const WARN_SECONDS = 60;

    /** Reserve this fraction on top of the voice price so answers fit in the budget. */
    private const ANSWER_ALLOWANCE = 0.5;

    /** Gemini's published audio rate, used as the worst case for per-audio-token models. */
    private const AUDIO_TOKENS_PER_SECOND = 25;

    public function __construct(
        private EntityManagerInterface $em,
        private VoiceSessionRepository $sessions,
        private CostCalculationService $costs,
        private RateLimitService $rateLimit,
        private PremiumFeatureGate $budgetGate,
        private ConfigRepository $config,
    ) {
    }

    public function open(User $user, Model $model, ?int $chatId, string $client, bool $zeroCost = false, string $transport = 'relay'): VoiceSession
    {
        $userId = (int) $user->getId();
        if (null !== $this->sessions->findOpenForUser($userId)) {
            throw new VoiceLiveBusyException(sprintf('User %d already has a live conversation.', $userId));
        }

        $now = time();
        $session = (new VoiceSession())
            ->setUserId($userId)
            ->setChatId($chatId)
            ->setModelId((int) $model->getId())
            ->setProvider($model->getService())
            ->setStatus('live')
            ->setStarted($now)
            ->setClient($this->client($client))
            ->setTransport('webrtc' === $transport ? 'webrtc' : 'relay');
        $this->em->persist($session);
        $this->em->flush();

        $metadata = [
            'source' => $session->getClient(),
            'chat_id' => $chatId,
            'voice_session_id' => $session->getId(),
            'voice_cost' => '0.000000',
            'answer_cost' => '0.000000',
            'answer_count' => 0,
            'answer_prompt_tokens' => 0,
            'answer_completion_tokens' => 0,
            'zero_cost' => $zeroCost,
            'media_usage' => ['duration_seconds' => 0],
            'last_tick' => $now,
        ];
        $useLogId = $this->insertUseLog($user, $model, $metadata, '0.000000', 0, 0, 0, 'running', 0, null);
        $session->setUseLogId($useLogId);
        $this->em->flush();

        return $session;
    }

    /**
     * Seconds the budget still pays for, capped by the admin max length.
     * No budget, or the budget gate off, means the max length only.
     */
    public function allowedSeconds(User $user, Model $model): int
    {
        $max = $this->maxSessionSeconds();
        $perSecond = $this->chargedPerSecond($model);
        if ($perSecond <= 0.0 || !$this->budgetApplies($user)) {
            return $max;
        }

        $remaining = (float) $this->rateLimit->checkCostBudget($user)['remaining'];

        return (int) min($max, max(0, (int) floor($remaining / $perSecond)));
    }

    public function tick(VoiceSession $session, VoiceLiveMeter $meter, ?int $latencyMs = null): VoiceLiveDecision
    {
        if ('ended' === $session->getStatus()) {
            return new VoiceLiveDecision('stop', 0, $session->getEndReason() ?? 'ended');
        }

        $meta = $this->readMetadata($session);
        $meta['last_tick'] = time();
        $voice = $this->voiceCost($session, $meter, $this->asBool($meta['zero_cost'] ?? false));
        $meta['voice_cost'] = $voice->totalCost;
        $meta['media_usage'] = ['duration_seconds' => round($meter->seconds, 3)];
        $total = $this->money((float) $voice->totalCost + $this->asFloat($meta['answer_cost'] ?? 0));
        $prompt = $meter->textInTokens + $meter->audioInTokens + $meter->audioCacheTokens + $this->asInt($meta['answer_prompt_tokens'] ?? 0);
        $completion = $meter->textOutTokens + $meter->audioOutTokens + $this->asInt($meta['answer_completion_tokens'] ?? 0);

        $session->setSeconds((int) round($meter->seconds));
        $this->writeUseLog($session, $meta, $total, $prompt, $completion, $voice, 'running', $latencyMs);
        $this->em->flush();

        return $this->decide($session, $meter);
    }

    /**
     * Add one Synaplan answer to the conversation row. Not a MESSAGES row.
     */
    public function addAnswer(VoiceSession $session, string $rawCost, int $promptTokens, int $completionTokens): void
    {
        if ('ended' === $session->getStatus()) {
            return;
        }

        $meta = $this->readMetadata($session);
        if (!$this->asBool($meta['zero_cost'] ?? false)) {
            $meta['answer_cost'] = $this->money($this->asFloat($meta['answer_cost'] ?? 0) + (float) $rawCost);
        }
        $meta['answer_count'] = $this->asInt($meta['answer_count'] ?? 0) + 1;
        $meta['answer_prompt_tokens'] = $this->asInt($meta['answer_prompt_tokens'] ?? 0) + $promptTokens;
        $meta['answer_completion_tokens'] = $this->asInt($meta['answer_completion_tokens'] ?? 0) + $completionTokens;
        $current = $this->em->getConnection()->fetchAssociative(
            'SELECT BPROMPT_TOKENS, BCOMPLETION_TOKENS FROM BUSELOG WHERE BID = :id AND BUSERID = :user_id',
            ['id' => $session->getUseLogId(), 'user_id' => $session->getUserId()],
        );
        $prompt = (is_array($current) ? (int) $current['BPROMPT_TOKENS'] : 0) + $promptTokens;
        $completion = (is_array($current) ? (int) $current['BCOMPLETION_TOKENS'] : 0) + $completionTokens;
        $total = $this->money($this->asFloat($meta['voice_cost'] ?? 0) + $this->asFloat($meta['answer_cost'] ?? 0));
        $this->writeUseLog($session, $meta, $total, $prompt, $completion, null, 'running', null);
        $this->em->flush();
    }

    public function close(VoiceSession $session, string $reason, VoiceLiveMeter $meter, ?int $latencyMs = null): void
    {
        $this->tick($session, $meter, $latencyMs);
        $meta = $this->readMetadata($session);
        $total = $this->money($this->asFloat($meta['voice_cost'] ?? 0) + $this->asFloat($meta['answer_cost'] ?? 0));
        $session->setStatus('ended')->setEndReason($reason)->setEnded(time());
        $this->writeUseLog($session, $meta, $total, 0, 0, null, 'success', $latencyMs, true);
        $this->em->flush();
    }

    private function decide(VoiceSession $session, VoiceLiveMeter $meter): VoiceLiveDecision
    {
        $max = $this->maxSessionSeconds();
        if ($meter->seconds >= $max) {
            return new VoiceLiveDecision('stop', 0, 'max_duration');
        }

        $model = $this->em->find(Model::class, $session->getModelId());
        $allowed = $model instanceof Model ? $this->allowedSeconds($this->user($session), $model) : $max;
        if (!$this->budgetApplies($this->user($session))) {
            return new VoiceLiveDecision('continue', $allowed, '');
        }

        $remaining = (float) $this->rateLimit->checkCostBudget($this->user($session))['remaining'];
        if ($remaining <= 0.0) {
            if ('ending' === $session->getStatus()) {
                return new VoiceLiveDecision('stop', 0, 'limit_budget');
            }
            $session->setStatus('ending');
            $this->em->flush();

            return new VoiceLiveDecision('wrap_up', 0, 'limit_budget');
        }

        if ($allowed <= self::WARN_SECONDS) {
            return new VoiceLiveDecision('warn', $allowed, 'limit_budget');
        }

        return new VoiceLiveDecision('continue', $allowed, '');
    }

    private function budgetApplies(User $user): bool
    {
        if (!$this->budgetGate->isCostBudgetEnforced()) {
            return false;
        }

        return (float) $this->rateLimit->checkCostBudget($user)['budget'] > 0.0;
    }

    private function chargedPerSecond(Model $model): float
    {
        $json = $model->getJson();
        $mode = $json['pricing_mode'] ?? 'per_token';
        $raw = match ($mode) {
            'per_second' => CostCalculationService::normaliseToPerUnit($model->getPriceIn(), $model->getInUnit()),
            'per_audio_token' => (
                ((float) ($json['audio_price_in_per_1M'] ?? 0) + (float) ($json['audio_price_out_per_1M'] ?? 0))
                / 1_000_000
            ) * self::AUDIO_TOKENS_PER_SECOND,
            default => 0.0,
        };
        $markup = 1.0 + ($this->rateLimit->getMarkupPercent() / 100.0);

        return $raw * (1.0 + self::ANSWER_ALLOWANCE) * $markup;
    }

    private function voiceCost(VoiceSession $session, VoiceLiveMeter $meter, bool $zeroCost): CostResult
    {
        if ($zeroCost) {
            return new CostResult('0.000000', '0.000000', '0.000000', '0.000000', [], 0);
        }

        return $this->costs->calculateVoiceLiveCost($session->getModelId(), $meter);
    }

    private function user(VoiceSession $session): User
    {
        $user = $this->em->find(User::class, $session->getUserId());
        if (!$user instanceof User) {
            throw new \RuntimeException(sprintf('Live voice session %d has no user.', (int) $session->getId()));
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function insertUseLog(
        User $user,
        Model $model,
        array $metadata,
        string $cost,
        int $prompt,
        int $completion,
        int $latency,
        string $status,
        int $tokens,
        ?string $snapshot,
    ): int {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO BUSELOG (BUSERID, BUNIXTIMES, BACTION, BPROVIDER, BMODEL, BTOKENS,
             BPROMPT_TOKENS, BCOMPLETION_TOKENS, BCACHED_TOKENS, BCACHE_CREATION_TOKENS,
             BESTIMATED, BMODEL_ID, BPRICE_SNAPSHOT, BCOST, BLATENCY, BSTATUS, BERROR, BMETADATA)
             VALUES (:user_id, :timestamp, :action, :provider, :model, :tokens,
             :prompt_tokens, :completion_tokens, 0, 0,
             0, :model_id, :price_snapshot, :cost, :latency, :status, :error, :metadata)',
            [
                'user_id' => $user->getId(),
                'timestamp' => time(),
                'action' => self::ACTION,
                'provider' => $model->getService(),
                'model' => $model->getName(),
                'tokens' => $tokens,
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'model_id' => $model->getId(),
                'price_snapshot' => $snapshot,
                'cost' => $cost,
                'latency' => $latency,
                'status' => $status,
                'error' => '',
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            ],
        );

        return (int) $this->em->getConnection()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function writeUseLog(
        VoiceSession $session,
        array $metadata,
        string $cost,
        int $prompt,
        int $completion,
        ?CostResult $voice,
        string $status,
        ?int $latencyMs,
        bool $keepTokens = false,
    ): void {
        $useLogId = $session->getUseLogId();
        if (null === $useLogId) {
            throw new \RuntimeException(sprintf('Live voice session %d has no usage row.', (int) $session->getId()));
        }

        $sets = [
            'BCOST = :cost',
            'BSTATUS = :status',
            'BMETADATA = :metadata',
        ];
        $params = [
            'cost' => $cost,
            'status' => $status,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'id' => $useLogId,
            'user_id' => $session->getUserId(),
        ];
        if (!$keepTokens) {
            $sets[] = 'BTOKENS = :tokens';
            $sets[] = 'BPROMPT_TOKENS = :prompt_tokens';
            $sets[] = 'BCOMPLETION_TOKENS = :completion_tokens';
            $params['tokens'] = $prompt + $completion;
            $params['prompt_tokens'] = $prompt;
            $params['completion_tokens'] = $completion;
        }
        if (null !== $voice && [] !== $voice->priceSnapshot) {
            $sets[] = 'BPRICE_SNAPSHOT = :price_snapshot';
            $params['price_snapshot'] = json_encode($voice->priceSnapshot, JSON_THROW_ON_ERROR);
        }
        if (null !== $latencyMs) {
            $sets[] = 'BLATENCY = :latency';
            $params['latency'] = $latencyMs;
        }

        $this->em->getConnection()->executeStatement(
            'UPDATE BUSELOG SET '.implode(', ', $sets).' WHERE BID = :id AND BUSERID = :user_id',
            $params,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readMetadata(VoiceSession $session): array
    {
        $raw = $this->em->getConnection()->fetchOne(
            'SELECT BMETADATA FROM BUSELOG WHERE BID = :id AND BUSERID = :user_id',
            ['id' => $session->getUseLogId(), 'user_id' => $session->getUserId()],
        );
        if (!is_string($raw) || '' === $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function maxSessionSeconds(): int
    {
        $value = $this->config->getValue(0, 'LIVE_VOICE', 'MAX_SESSION_SECONDS');
        $seconds = is_numeric($value) ? (int) $value : self::MAX_SESSION_SECONDS;

        return max(60, $seconds);
    }

    private function client(string $client): string
    {
        $client = strtolower(trim($client));

        return in_array($client, ['web', 'ios', 'android', 'carplay'], true) ? $client : 'web';
    }

    private function money(float $amount): string
    {
        return number_format(max(0.0, $amount), 6, '.', '');
    }

    private function asFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function asBool(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value;
    }

    public function isIncognito(VoiceSession $session): bool
    {
        return $this->asBool($this->readMetadata($session)['incognito'] ?? false);
    }

    /**
     * Unix time of the last usage tick, or the start time when none has landed.
     */
    public function lastActivityUnix(VoiceSession $session): int
    {
        $tick = $this->asInt($this->readMetadata($session)['last_tick'] ?? 0);

        return $tick > 0 ? $tick : $session->getStarted();
    }

    public function stringMeta(VoiceSession $session, string $key): string
    {
        $value = $this->readMetadata($session)[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function mergeMetadata(VoiceSession $session, array $extra): void
    {
        $useLogId = $session->getUseLogId();
        if (null === $useLogId) {
            return;
        }

        $meta = array_merge($this->readMetadata($session), $extra);
        $this->em->getConnection()->executeStatement(
            'UPDATE BUSELOG SET BMETADATA = :metadata WHERE BID = :id AND BUSERID = :user_id',
            [
                'metadata' => json_encode($meta, \JSON_THROW_ON_ERROR),
                'id' => $useLogId,
                'user_id' => $session->getUserId(),
            ],
        );
    }

    /**
     * Close from the last stored tick. A dead gateway has no newer meter.
     */
    public function finalizeStored(VoiceSession $session, string $reason): void
    {
        if ('ended' === $session->getStatus()) {
            return;
        }

        $meta = $this->readMetadata($session);
        $total = $this->money($this->asFloat($meta['voice_cost'] ?? 0) + $this->asFloat($meta['answer_cost'] ?? 0));
        $session->setStatus('ended')->setEndReason($reason)->setEnded(time());
        if (null !== $session->getUseLogId()) {
            $this->writeUseLog($session, $meta, $total, 0, 0, null, 'success', null, true);
        }
        $this->em->flush();
    }
}
