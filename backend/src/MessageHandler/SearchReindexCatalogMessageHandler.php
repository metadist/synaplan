<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SearchReindexCatalogMessage;
use App\Service\SmartSearch\Index\SearchIndexEmbedder;
use App\Service\SmartSearch\Index\SearchIndexer;
use App\Service\SmartSearch\Index\SettingsCatalog;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SearchReindexCatalogMessageHandler
{
    private const MAX_EMBED_ROWS = 2000;

    public function __construct(
        private SettingsCatalog $catalog,
        private SearchIndexer $indexer,
        private SearchIndexEmbedder $embedder,
    ) {
    }

    public function __invoke(SearchReindexCatalogMessage $message): void
    {
        $this->indexer->replaceCatalog(SettingsCatalog::KIND, $this->catalog->documents());
        $this->embedder->embedPending(SettingsCatalog::CATALOG_USER_ID, self::MAX_EMBED_ROWS);
    }
}
