<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SummarizeApiSessionCommand;
use App\Service\MessagesGateway\ApiSessionSummaryService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for {@see SummarizeApiSessionCommand}.
 *
 * Runs on the messenger worker so the API request path (a streaming SSE
 * relay for Claude Code) never waits on the summarizer. Failures are logged
 * and swallowed — a missing session summary is cosmetic, not worth a retry
 * storm against a possibly failing AI provider.
 */
#[AsMessageHandler]
final readonly class SummarizeApiSessionCommandHandler
{
    public function __construct(
        private ApiSessionSummaryService $apiSessionSummaryService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SummarizeApiSessionCommand $command): void
    {
        try {
            $this->apiSessionSummaryService->record(
                $command->getUserId(),
                $command->getSessionKey(),
                $command->getClient(),
                $command->getModel(),
                $command->getRequestExcerpt(),
                $command->getResponseExcerpt(),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('SummarizeApiSessionCommand: failed', [
                'user_id' => $command->getUserId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
