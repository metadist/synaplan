<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\PromptMeta;
use App\Message\CrawlWidgetUrlMessage;
use App\Repository\PromptMetaRepository;
use App\Service\File\FileHelper;
use App\Service\File\VectorizationService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\UrlContentService;
use Doctrine\ORM\EntityManagerInterface;
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
        private PromptMetaRepository $promptMetaRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CrawlWidgetUrlMessage $message): void
    {
        $widgetId = $message->getWidgetId();
        $url = $message->getUrl();
        $ownerId = $message->getOwnerId();
        $nodeId = $message->getResponseNodeId();

        $safeUrl = FileHelper::redactUrlForLogging($url);

        $this->logger->info('CrawlWidgetUrl: Starting crawl', [
            'widget_id' => $widgetId,
            'url' => $safeUrl,
            'owner_id' => $ownerId,
            'node_id' => $nodeId,
        ]);

        $result = $this->urlContentService->fetchForCrawling($url);

        if (!$result->success || '' === $result->extractedText) {
            $this->logger->warning('CrawlWidgetUrl: Fetch failed or empty', [
                'widget_id' => $widgetId,
                'url' => $safeUrl,
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
                'url' => $safeUrl,
                'chunks_created' => $vectorResult['chunks_created'],
            ]);

            $this->updateCrawlStatus($message->getPromptId(), $nodeId);
        } else {
            $this->logger->error('CrawlWidgetUrl: Vectorization failed', [
                'widget_id' => $widgetId,
                'url' => $safeUrl,
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

    private function updateCrawlStatus(int $promptId, string $nodeId): void
    {
        if (0 === $promptId) {
            return;
        }

        try {
            $meta = $this->promptMetaRepository->findOneBy([
                'promptId' => $promptId,
                'metaKey' => 'widgetCrawlStatus',
            ]);

            $status = [];
            if ($meta) {
                $status = json_decode($meta->getMetaValue(), true, 512, JSON_THROW_ON_ERROR);
                if (!\is_array($status)) {
                    $status = [];
                }
            } else {
                $meta = new PromptMeta();
                $meta->setPromptId($promptId);
                $meta->setMetaKey('widgetCrawlStatus');
                $this->em->persist($meta);
            }

            $status[$nodeId] = time();
            $meta->setMetaValue(json_encode($status, JSON_THROW_ON_ERROR));
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to update crawl status', [
                'prompt_id' => $promptId,
                'node_id' => $nodeId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
