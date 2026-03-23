<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Async message to crawl a URL and store its content in the widget's RAG vector store.
 *
 * Queued in: async_index
 * Handled by: CrawlWidgetUrlMessageHandler
 */
final readonly class CrawlWidgetUrlMessage
{
    public function __construct(
        private string $widgetId,
        private string $url,
        private int $ownerId,
        private string $responseNodeId,
        private int $promptId = 0,
    ) {
    }

    public function getWidgetId(): string
    {
        return $this->widgetId;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function getResponseNodeId(): string
    {
        return $this->responseNodeId;
    }

    public function getPromptId(): int
    {
        return $this->promptId;
    }
}
