<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SearchReindexUserMessage;
use App\Service\SmartSearch\Index\BackfillTracker;
use App\Service\SmartSearch\Index\SearchIndexEmbedder;
use App\Service\SmartSearch\Index\SearchIndexer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SearchReindexUserMessageHandler
{
    public function __construct(
        private SearchIndexer $indexer,
        private SearchIndexEmbedder $embedder,
        private BackfillTracker $backfill,
    ) {
    }

    public function __invoke(SearchReindexUserMessage $message): void
    {
        try {
            $this->indexer->reindexUser($message->userId);
        } finally {
            // Keyword search works from here on; vectors follow.
            $this->backfill->markDone($message->userId);
        }
        $this->embedder->embedPending($message->userId);
    }
}
