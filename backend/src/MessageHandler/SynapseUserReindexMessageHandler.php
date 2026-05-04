<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SynapseUserReindexMessage;
use App\Service\Message\SynapseIndexer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for SynapseUserReindexMessage — refreshes a single user's
 * Synapse Routing topics in the background.
 *
 * Failure-tolerant on purpose: we never re-throw, because the auto-
 * index hook is best-effort. A failed refresh just leaves the user's
 * topics on the previous embedding model — Routing then either
 * filters them as stale or falls back to the AI sorter, both of
 * which keep the chat experience working.
 */
#[AsMessageHandler]
final readonly class SynapseUserReindexMessageHandler
{
    public function __construct(
        private SynapseIndexer $synapseIndexer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SynapseUserReindexMessage $message): void
    {
        try {
            $result = $this->synapseIndexer->ensureUserTopicsFresh($message->userId);

            if ($result['indexed'] > 0 || $result['errors'] > 0) {
                $this->logger->info('SynapseUserReindex: completed', [
                    'user_id' => $message->userId,
                    'indexed' => $result['indexed'],
                    'skipped' => $result['skipped'],
                    'errors' => $result['errors'],
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('SynapseUserReindex: handler failed', [
                'user_id' => $message->userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
