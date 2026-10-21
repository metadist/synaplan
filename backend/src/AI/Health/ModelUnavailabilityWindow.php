<?php

declare(strict_types=1);

namespace App\AI\Health;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * How many times one model was unreachable during the last hour.
 *
 * The health monitor's own window decides whether a model looks degraded.
 * This counter only answers whether it has failed often enough to ask the
 * person to pick a different default. It never changes a default itself.
 *
 * A later success does not wipe the count: the hour expires on its own.
 * Nothing here may throw — a broken counter must not take down the AI call
 * it is observing.
 */
final class ModelUnavailabilityWindow
{
    public const WINDOW_SECONDS = 3600;

    /** Ask about a different default only after more failures than this. */
    public const OFFER_AFTER = 2;

    private const KEY_PREFIX = 'model_health.unavailable.';

    /**
     * Cap stored in the cache entry. The offer only needs to know the count
     * is past the threshold; thirty is enough to say so honestly.
     */
    private const MAX_STAMPS = 30;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(int $modelId, ?int $now = null): void
    {
        $now ??= time();

        try {
            $item = $this->cache->getItem(self::KEY_PREFIX.$modelId);
            $stamps = $this->fresh($item->isHit() ? $item->get() : [], $now);
            $stamps[] = $now;
            if (count($stamps) > self::MAX_STAMPS) {
                $stamps = array_slice($stamps, -self::MAX_STAMPS);
            }
            $item->set($stamps);
            $item->expiresAfter(self::WINDOW_SECONDS);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->debug('Model unavailability counter update failed', [
                'model_id' => $modelId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function count(int $modelId, ?int $now = null): int
    {
        $now ??= time();

        try {
            $item = $this->cache->getItem(self::KEY_PREFIX.$modelId);
            if (!$item->isHit()) {
                return 0;
            }

            return count($this->fresh($item->get(), $now));
        } catch (\Throwable $e) {
            $this->logger->debug('Model unavailability counter read failed', [
                'model_id' => $modelId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    public function offersChange(int $modelId, ?int $now = null): bool
    {
        return $this->count($modelId, $now) > self::OFFER_AFTER;
    }

    public function reset(int $modelId): void
    {
        try {
            $this->cache->deleteItem(self::KEY_PREFIX.$modelId);
        } catch (\Throwable $e) {
            $this->logger->debug('Model unavailability counter reset failed', [
                'model_id' => $modelId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<int>
     */
    private function fresh(mixed $stored, int $now): array
    {
        if (!is_array($stored)) {
            return [];
        }

        $cutoff = $now - self::WINDOW_SECONDS;
        $stamps = [];
        foreach ($stored as $stamp) {
            if (is_int($stamp) && $stamp >= $cutoff) {
                $stamps[] = $stamp;
            }
        }

        return $stamps;
    }
}
