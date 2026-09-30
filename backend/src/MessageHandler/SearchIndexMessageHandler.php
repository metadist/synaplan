<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SearchIndexMessage;
use App\Service\SmartSearch\Index\SearchIndexer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SearchIndexMessageHandler
{
    public function __construct(
        private SearchIndexer $indexer,
    ) {
    }

    public function __invoke(SearchIndexMessage $message): void
    {
        $this->indexer->refresh($message->kind, $message->userId, $message->refId);
    }
}
