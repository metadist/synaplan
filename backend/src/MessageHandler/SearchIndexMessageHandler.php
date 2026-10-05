<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SearchIndexMessage;
use App\Service\SmartSearch\Index\SearchIndexEmbedder;
use App\Service\SmartSearch\Index\SearchIndexer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class SearchIndexMessageHandler
{
    /** The changed row plus a few stragglers of the same user. */
    private const EMBED_ROWS = 8;
    /** A deferred item that is still invisible after this many checks was rolled back or deleted. */
    public const MAX_DEFERRED_ATTEMPTS = 3;
    public const DEFERRED_RETRY_DELAY_MS = 5000;

    public function __construct(
        private SearchIndexer $indexer,
        private SearchIndexEmbedder $embedder,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(SearchIndexMessage $message): void
    {
        if ($message->removed) {
            $this->indexer->remove($message->kind, $message->userId, $message->refId);

            return;
        }

        $retry = $message->deferred && $message->attempt < self::MAX_DEFERRED_ATTEMPTS;
        if (!$this->indexer->refresh($message->kind, $message->userId, $message->refId, deleteWhenMissing: !$retry)) {
            if ($retry) {
                $this->bus->dispatch($message->nextAttempt(), [
                    new DelayStamp(self::DEFERRED_RETRY_DELAY_MS * ($message->attempt + 1)),
                ]);
            }

            return;
        }
        $this->embedder->embedPending($message->userId, self::EMBED_ROWS);
    }
}
