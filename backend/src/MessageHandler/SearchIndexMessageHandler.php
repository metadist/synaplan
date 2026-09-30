<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SearchIndexMessage;
use App\Service\SmartSearch\Index\SearchIndexEmbedder;
use App\Service\SmartSearch\Index\SearchIndexer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SearchIndexMessageHandler
{
    /** The changed row plus a few stragglers of the same user. */
    private const EMBED_ROWS = 8;

    public function __construct(
        private SearchIndexer $indexer,
        private SearchIndexEmbedder $embedder,
    ) {
    }

    public function __invoke(SearchIndexMessage $message): void
    {
        $this->indexer->refresh($message->kind, $message->userId, $message->refId);
        $this->embedder->embedPending($message->userId, self::EMBED_ROWS);
    }
}
