<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index;

use App\Message\SearchReindexUserMessage;
use App\Repository\SearchIndexRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * First index build for users who have no rows yet (an install upgraded to
 * Smart Search). The first search queues it; the handler marks it done, so
 * "indexing" never outlives the actual run.
 */
final readonly class BackfillTracker
{
    private const KEY_PREFIX = 'smart_search.backfill.';
    private const STATE_PENDING = 'pending';
    private const STATE_DONE = 'done';
    private const PENDING_TTL = 3600;
    private const DONE_TTL = 86400;

    public function __construct(
        private SearchIndexRepository $repository,
        private MessageBusInterface $bus,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool true while the first build is still pending
     */
    public function ensure(int $userId): bool
    {
        try {
            $state = $this->cache->get(self::KEY_PREFIX.$userId, function (ItemInterface $item) use ($userId): string {
                if ($this->repository->countForUser($userId) > 0) {
                    $item->expiresAfter(self::DONE_TTL);

                    return self::STATE_DONE;
                }
                $this->bus->dispatch(new SearchReindexUserMessage($userId));
                $item->expiresAfter(self::PENDING_TTL);

                return self::STATE_PENDING;
            });

            return self::STATE_PENDING === $state;
        } catch (\Throwable $e) {
            $this->logger->warning('Smart Search backfill check failed', ['user_id' => $userId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Drops the state, so the next {@see ensure()} queues the build again. */
    public function forget(int $userId): void
    {
        $this->cache->delete(self::KEY_PREFIX.$userId);
    }

    public function markDone(int $userId): void
    {
        $key = self::KEY_PREFIX.$userId;
        $this->cache->delete($key);
        $this->cache->get($key, static function (ItemInterface $item): string {
            $item->expiresAfter(self::DONE_TTL);

            return self::STATE_DONE;
        });
    }
}
