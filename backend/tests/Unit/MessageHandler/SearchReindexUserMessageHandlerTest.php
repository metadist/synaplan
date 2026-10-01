<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\SearchReindexUserMessage;
use App\MessageHandler\SearchReindexUserMessageHandler;
use App\Service\SmartSearch\Index\BackfillTracker;
use App\Service\SmartSearch\Index\SearchIndexEmbedder;
use App\Service\SmartSearch\Index\SearchIndexer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SearchReindexUserMessageHandlerTest extends TestCase
{
    public function testAFailedBuildIsForgottenSoTheNextSearchQueuesItAgain(): void
    {
        $indexer = $this->createMock(SearchIndexer::class);
        $indexer->method('reindexUser')->willThrowException(new \RuntimeException('database went away'));
        $backfill = $this->createMock(BackfillTracker::class);
        $backfill->expects(self::once())->method('forget')->with(7);
        $backfill->expects(self::never())->method('markDone');
        $embedder = $this->createMock(SearchIndexEmbedder::class);
        $embedder->expects(self::never())->method('embedPending');

        (new SearchReindexUserMessageHandler($indexer, $embedder, $backfill, new NullLogger()))(new SearchReindexUserMessage(7));
    }

    public function testASuccessfulBuildIsMarkedDoneBeforeEmbedding(): void
    {
        $indexer = $this->createMock(SearchIndexer::class);
        $backfill = $this->createMock(BackfillTracker::class);
        $backfill->expects(self::once())->method('markDone')->with(7);
        $backfill->expects(self::never())->method('forget');
        $embedder = $this->createMock(SearchIndexEmbedder::class);
        $embedder->expects(self::once())->method('embedPending')->with(7);

        (new SearchReindexUserMessageHandler($indexer, $embedder, $backfill, new NullLogger()))(new SearchReindexUserMessage(7));
    }
}
