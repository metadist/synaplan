<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SearchReindexUserMessage;
use App\Service\SmartSearch\Index\BackfillTracker;
use App\Service\SmartSearch\Index\SearchIndexEmbedder;
use App\Service\SmartSearch\Index\SearchIndexer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SearchReindexUserMessageHandler
{
    public function __construct(
        private SearchIndexer $indexer,
        private SearchIndexEmbedder $embedder,
        private BackfillTracker $backfill,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SearchReindexUserMessage $message): void
    {
        try {
            $this->indexer->reindexUser($message->userId);
        } catch (\Throwable $e) {
            // Forget the attempt so the next search queues a fresh build
            // instead of reporting "done" for a day. No messenger retry on
            // top of that, or a failing build would run twice.
            $this->backfill->forget($message->userId);
            $this->logger->warning('Smart Search backfill failed; the next search queues it again', [
                'user_id' => $message->userId,
                'error' => $e->getMessage(),
            ]);

            return;
        }
        // Keyword search works from here on; vectors follow.
        $this->backfill->markDone($message->userId);
        $this->embedder->embedPending($message->userId);
    }
}
