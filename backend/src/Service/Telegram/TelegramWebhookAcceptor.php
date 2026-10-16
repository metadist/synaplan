<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Repository\TelegramBotRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Decides whether a Telegram webhook call becomes a worker job.
 * A bad secret or a repeated update_id is dropped. The HTTP layer still
 * answers 200 so Telegram does not retry a call we already refused.
 */
final readonly class TelegramWebhookAcceptor
{
    private const DEDUPE_SECONDS = 300;
    private const LOCK_SECONDS = 10.0;

    public function __construct(
        private TelegramBotRepository $bots,
        private CacheItemPoolInterface $cache,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $update
     */
    public function decide(string $botKey, ?string $secretHeader, array $update): TelegramWebhookDecision
    {
        $bot = $this->bots->findOneByBotKey($botKey);
        if (null === $bot || null === $bot->getId()) {
            return TelegramWebhookDecision::drop();
        }
        if (!$this->secretMatches($bot->getSecretHash(), $secretHeader)) {
            $this->logger->info('Telegram webhook secret rejected', ['bot_id' => $bot->getId()]);

            return TelegramWebhookDecision::drop();
        }

        $updateId = $update['update_id'] ?? null;
        if (!is_int($updateId) && !(is_string($updateId) && ctype_digit($updateId))) {
            return TelegramWebhookDecision::drop();
        }
        $updateId = (int) $updateId;
        $key = $this->cacheKey($botKey, $updateId);

        // Check and set must not interleave: a parallel delivery of the same
        // update either waits out this lock or sees the marker afterwards.
        $lock = $this->lockFactory->createLock($key.'_lock', self::LOCK_SECONDS);
        if (!$lock->acquire()) {
            return TelegramWebhookDecision::drop();
        }
        try {
            $item = $this->cache->getItem($key);
            if ($item->isHit()) {
                return TelegramWebhookDecision::drop();
            }
            $item->set(1);
            $item->expiresAfter(self::DEDUPE_SECONDS);
            $this->cache->save($item);
        } finally {
            $lock->release();
        }

        return TelegramWebhookDecision::dispatch((int) $bot->getId(), $updateId, $key);
    }

    /**
     * Frees the update_id again so Telegram's retry is accepted, for when
     * the job could not be queued.
     */
    public function release(TelegramWebhookDecision $decision): void
    {
        if (null === $decision->reservationKey) {
            return;
        }
        $this->cache->deleteItem($decision->reservationKey);
    }

    private function secretMatches(string $storedHash, ?string $header): bool
    {
        if ('' === $storedHash || null === $header || '' === $header) {
            return false;
        }
        $given = hash('sha256', $header);
        if (strlen($storedHash) !== strlen($given)) {
            return false;
        }

        return hash_equals($storedHash, $given);
    }

    private function cacheKey(string $botKey, int $updateId): string
    {
        return 'telegram_update_'.$botKey.'_'.$updateId;
    }
}
