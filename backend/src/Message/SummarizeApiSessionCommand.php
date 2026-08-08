<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Backgrounded API-session summary refresh.
 *
 * Dispatched after an API gateway request (Anthropic-compatible /v1/messages
 * or OpenAI-compatible /v1/chat/completions) has been served and metered, so
 * the request path never waits on the summarizer. The handler folds the new
 * request/response excerpt into a rolling 2-3 sentence session summary kept
 * in a per-session BMESSAGES chat via
 * {@see \App\Service\MessagesGateway\ApiSessionSummaryService::record()}.
 *
 * Excerpts are capped at dispatch time (see the service constants) — the
 * queue payload never carries full transcripts.
 *
 * Routed to `async_ai_high` (see `messenger.yaml`).
 */
final readonly class SummarizeApiSessionCommand
{
    public function __construct(
        private int $userId,
        private string $sessionKey,
        private string $client,
        private string $model,
        private string $requestExcerpt,
        private string $responseExcerpt,
    ) {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getSessionKey(): string
    {
        return $this->sessionKey;
    }

    /**
     * Client label, e.g. 'claude-code' or 'openai-api'.
     */
    public function getClient(): string
    {
        return $this->client;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getRequestExcerpt(): string
    {
        return $this->requestExcerpt;
    }

    public function getResponseExcerpt(): string
    {
        return $this->responseExcerpt;
    }
}
