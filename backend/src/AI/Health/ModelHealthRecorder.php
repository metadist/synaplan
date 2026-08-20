<?php

declare(strict_types=1);

namespace App\AI\Health;

use App\Repository\ModelRepository;
use App\Service\Usage\UsageFailureLogger;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Counts how AI calls end, per model, inside a rolling window.
 *
 * This is the free half of the detection: it only looks at calls that happen
 * anyway, so it costs nothing and — unlike a catalog lookup — it also sees the
 * failures that only show up for THIS account (exhausted quota, blocked
 * region).
 *
 * Counters live in the shared Redis pool so all web nodes agree, and expire on
 * their own: an unused window simply lapses instead of having to be swept.
 * Increments are read-modify-write rather than atomic, so a burst can lose the
 * odd count. That is fine for a threshold heuristic and not worth a Redis-only
 * dependency for.
 *
 * Nothing in here may throw: a broken health counter must never take down the
 * AI call it is observing.
 */
final class ModelHealthRecorder
{
    private const KEY_PREFIX = 'model_health.window.';

    /**
     * AiFacade capability => BMODELS.BTAG. Needed because one provider model id
     * can back several catalog rows (Groq's Qwen backs both the chat and the
     * vision row), and attributing a vision failure to the chat row would
     * report the wrong model as broken.
     */
    private const CAPABILITY_TAGS = [
        'chat' => 'chat',
        'vision' => 'pic2text',
        'embedding' => 'vectorize',
        'image_generation' => 'text2pic',
        'video_generation' => 'text2vid',
        'speech_to_text' => 'sound2text',
        'text_to_speech' => 'text2sound',
    ];

    /** @return list<string> */
    public static function capabilities(): array
    {
        return array_keys(self::CAPABILITY_TAGS);
    }

    /** BMODELS.BTAG for an AiFacade capability, or null when unmapped. */
    public static function tagForCapability(string $capability): ?string
    {
        return self::CAPABILITY_TAGS[$capability] ?? null;
    }

    /** @var array<string, int|null> */
    private array $resolveMemo = [];

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly ModelHealthConfig $config,
        private readonly FailureClassifier $classifier,
        private readonly ModelRepository $modelRepository,
        private readonly UsageFailureLogger $failureLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Record a call that came back cleanly.
     */
    public function recordSuccess(string $capability, string $providerName, ?string $providerModelId): void
    {
        $modelId = $this->resolveModelId($capability, $providerName, $providerModelId);
        if (null === $modelId) {
            return;
        }

        $this->mutate($modelId, function (array $window): array {
            ++$window['ok'];
            $window['last_success_at'] = time();
            // A success clears the stored error so the status page never shows
            // a stale message next to a model that is working again.
            $window['last_kind'] = null;
            $window['last_message'] = null;

            return $window;
        });
    }

    /**
     * Record a failed call. Returns how it was classified so the caller can act
     * on it without classifying a second time.
     */
    public function recordFailure(
        string $capability,
        string $providerName,
        ?string $providerModelId,
        \Throwable $error,
        ?int $userId = null,
    ): FailureKind {
        $kind = $this->classifier->classify($error);
        if (!$kind->countsAgainstModel()) {
            return $kind;
        }

        $modelId = $this->resolveModelId($capability, $providerName, $providerModelId);

        // The BUSELOG row is written even when the model is not in the catalog:
        // an operator debugging a direct provider call still wants the trail.
        if (null !== $userId && $userId > 0) {
            $this->failureLogger->record(
                userId: $userId,
                action: $capability,
                provider: $providerName,
                model: $providerModelId ?? 'unknown',
                modelId: $modelId,
                failureKind: $kind->value,
                errorMessage: $error->getMessage(),
            );
        }

        if (null === $modelId) {
            return $kind;
        }

        $message = $error->getMessage();
        $this->mutate($modelId, static function (array $window) use ($kind, $message): array {
            ++$window['fail'];
            $window['last_failure_at'] = time();
            $window['last_kind'] = $kind->value;
            $window['last_message'] = mb_substr($message, 0, 500);

            return $window;
        });

        return $kind;
    }

    public function snapshot(int $modelId): ModelHealthCounters
    {
        $window = $this->readWindow($modelId);

        return new ModelHealthCounters(
            successes: (int) $window['ok'],
            failures: (int) $window['fail'],
            lastKind: is_string($window['last_kind'] ?? null) ? FailureKind::tryFrom($window['last_kind']) : null,
            lastMessage: is_string($window['last_message'] ?? null) ? $window['last_message'] : null,
            lastFailureAt: (int) ($window['last_failure_at'] ?? 0),
            lastSuccessAt: (int) ($window['last_success_at'] ?? 0),
        );
    }

    /**
     * @param list<int> $modelIds
     *
     * @return array<int, ModelHealthCounters>
     */
    public function snapshotMany(array $modelIds): array
    {
        $snapshots = [];
        foreach ($modelIds as $modelId) {
            $snapshots[$modelId] = $this->snapshot($modelId);
        }

        return $snapshots;
    }

    /**
     * Forget the window for a model. Used after an operator acts on it, so the
     * next verdict is formed from fresh evidence instead of the old backlog.
     */
    public function reset(int $modelId): void
    {
        try {
            $this->cache->deleteItem(self::KEY_PREFIX.$modelId);
        } catch (\Throwable $e) {
            $this->logger->debug('Model health counter reset failed', [
                'model_id' => $modelId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find the catalog row a provider call belongs to.
     *
     * Returns null when the model is not in BMODELS — direct provider calls
     * with an ad-hoc model id exist, and they simply have nothing to report on.
     */
    public function resolveModelId(string $capability, string $providerName, ?string $providerModelId): ?int
    {
        if (null === $providerModelId || '' === trim($providerModelId)) {
            return null;
        }

        $tag = self::CAPABILITY_TAGS[$capability] ?? null;
        if (null === $tag) {
            return null;
        }

        $memoKey = $tag.'|'.strtolower($providerName).'|'.strtolower($providerModelId);
        if (array_key_exists($memoKey, $this->resolveMemo)) {
            return $this->resolveMemo[$memoKey];
        }

        try {
            $modelId = $this->modelRepository->findIdByServiceProviderIdAndTag($providerName, $providerModelId, $tag);
        } catch (\Throwable $e) {
            $this->logger->debug('Model health lookup failed', [
                'provider' => $providerName,
                'model' => $providerModelId,
                'error' => $e->getMessage(),
            ]);
            $modelId = null;
        }

        return $this->resolveMemo[$memoKey] = $modelId;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    private function mutate(int $modelId, callable $mutator): void
    {
        try {
            $item = $this->cache->getItem(self::KEY_PREFIX.$modelId);
            $window = $item->isHit() && is_array($item->get()) ? $item->get() : self::emptyWindow();
            $item->set($mutator($window + self::emptyWindow()));
            // Tumbling window: when nothing is recorded for a full window the
            // key lapses and the model starts from a clean slate.
            $item->expiresAfter($this->config->windowSeconds());
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->debug('Model health counter update failed', [
                'model_id' => $modelId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readWindow(int $modelId): array
    {
        try {
            $item = $this->cache->getItem(self::KEY_PREFIX.$modelId);
            if ($item->isHit() && is_array($item->get())) {
                return $item->get() + self::emptyWindow();
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Model health counter read failed', [
                'model_id' => $modelId,
                'error' => $e->getMessage(),
            ]);
        }

        return self::emptyWindow();
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyWindow(): array
    {
        return [
            'ok' => 0,
            'fail' => 0,
            'last_kind' => null,
            'last_message' => null,
            'last_failure_at' => 0,
            'last_success_at' => 0,
        ];
    }
}
