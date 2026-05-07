<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Backgrounded memory extraction.
 *
 * Phase 2a: dispatched by `ChatHandler::handleStream()` after the assistant
 * answer has been streamed and persisted, so the SSE `complete` event can
 * fire the moment the user has the answer in hand. The handler runs the
 * `MemoryExtractionService` LLM call + Qdrant writes on the worker thread,
 * unblocking the user's chat input and removing the 5-9 s "Analyzing
 * memories…" tail from the streaming connection.
 *
 * Routed to `async_ai_high` (see `messenger.yaml`) — same queue as the rest
 * of the user-facing AI work, so latency-sensitive jobs aren't blocked
 * behind slow background indexing on `async_index`.
 */
final readonly class ExtractMemoriesCommand
{
    /**
     * @param array<int, array{role: string, content: string}> $threadSnapshot   Conversation history at the moment of the request, plus the assistant response appended as the last entry. Pre-flattened to plain arrays so it serialises cleanly across the queue boundary.
     * @param array<int, array<string, mixed>>                 $relevantMemories Optional list of pre-loaded memories that were already in scope when the response was generated. Empty if none.
     */
    public function __construct(
        private int $messageId,
        private int $userId,
        private string $aiResponse,
        private array $threadSnapshot,
        private array $relevantMemories = [],
    ) {
    }

    public function getMessageId(): int
    {
        return $this->messageId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getAiResponse(): string
    {
        return $this->aiResponse;
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function getThreadSnapshot(): array
    {
        return $this->threadSnapshot;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRelevantMemories(): array
    {
        return $this->relevantMemories;
    }
}
