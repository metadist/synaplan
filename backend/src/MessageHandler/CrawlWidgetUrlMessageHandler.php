<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\CrawlWidgetUrlMessage;
use App\Service\File\VectorizationService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\UrlContentService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Crawls a URL configured in a widget flow response, extracts text,
 * vectorizes it and stores the embeddings for RAG retrieval.
 *
 * Old chunks for the same URL source are deleted before storing new ones,
 * making re-crawls idempotent.
 */
#[AsMessageHandler]
final readonly class CrawlWidgetUrlMessageHandler
{
    public function __construct(
        private UrlContentService $urlContentService,
        private VectorizationService $vectorizationService,
        private VectorStorageFacade $vectorStorage,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CrawlWidgetUrlMessage $message): void
    {
        $widgetId = $message->getWidgetId();
        $url = $message->getUrl();
        $ownerId = $message->getOwnerId();
        $nodeId = $message->getResponseNodeId();

        $this->logger->info('CrawlWidgetUrl: Starting crawl', [
            'widget_id' => $widgetId,
            'url' => $url,
            'owner_id' => $ownerId,
            'node_id' => $nodeId,
        ]);

        $result = $this->urlContentService->fetchForCrawling($url);

        if (!$result->success || '' === $result->extractedText) {
            $this->logger->warning('CrawlWidgetUrl: Fetch failed or empty', [
                'widget_id' => $widgetId,
                'url' => $url,
                'error' => $result->error,
            ]);

            return;
        }

        $groupKey = sprintf('WIDGET:%s', $widgetId);
        $fileId = self::buildFileId($widgetId, $nodeId);

        $this->vectorStorage->deleteByFile($ownerId, $fileId);

        $prefixedText = sprintf(
            "Source: %s\nTitle: %s\n\n%s",
            $url,
            $result->title,
            $result->extractedText,
        );

        $vectorResult = $this->vectorizationService->vectorizeAndStore(
            $prefixedText,
            $ownerId,
            $fileId,
            $groupKey,
            0,
        );

        if ($vectorResult['success']) {
            $this->logger->info('CrawlWidgetUrl: Vectorization complete', [
                'widget_id' => $widgetId,
                'url' => $url,
                'chunks_created' => $vectorResult['chunks_created'],
            ]);
        } else {
            $this->logger->error('CrawlWidgetUrl: Vectorization failed', [
                'widget_id' => $widgetId,
                'url' => $url,
                'error' => $vectorResult['error'],
            ]);
        }
    }

    /**
     * Deterministic file ID per widget+node so re-crawls replace old chunks.
     */
    public static function buildFileId(string $widgetId, string $nodeId): int
    {
        return abs(crc32("crawl:{$widgetId}:{$nodeId}")) % 2_000_000_000;
    }
}
