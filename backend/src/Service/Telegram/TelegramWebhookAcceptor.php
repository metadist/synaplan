<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Repository\TelegramBotRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Decides whether a Telegram webhook call becomes a worker job.
 * A bad secret or a repeated update_id is dropped. The HTTP layer still
 * answers 200 so Telegram does not retry a call we already refused.
 */
final readonly class TelegramWebhookAcceptor
{
    private const DEDUPE_SECONDS = 300;

    public function __construct(
        private TelegramBotRepository $bots,
        private CacheItemPoolInterface $cache,
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
            $this->logger->info('Telegram webhook secret rejected', ['bot_key' => $botKey]);

            return TelegramWebhookDecision::drop();
        }

        $updateId = $update['update_id'] ?? null;
        if (!is_int($updateId) && !(is_string($updateId) && ctype_digit($updateId))) {
            return TelegramWebhookDecision::drop();
        }
        $updateId = (int) $updateId;

        $item = $this->cache->getItem($this->cacheKey($botKey, $updateId));
        if ($item->isHit()) {
            return TelegramWebhookDecision::drop();
        }
        $item->set(1);
        $item->expiresAfter(self::DEDUPE_SECONDS);
        $this->cache->save($item);

        return TelegramWebhookDecision::dispatch((int) $bot->getId());
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
