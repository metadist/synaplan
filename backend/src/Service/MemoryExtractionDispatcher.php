<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\ExtractMemoriesCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Forwards a prepared {@see ExtractMemoriesCommand} to the messenger bus.
 *
 * Single source of truth for the dispatch + log + swallow-failures
 * contract that both {@see Message\Handler\ChatHandler} (for
 * the synchronous fallback path) and
 * {@see \App\Controller\StreamController} (for the deferred SSE path
 * fixing the issue #881 race) need.
 *
 * Bus failures are logged at warning level and never re-thrown — a missed
 * extraction is recoverable on the next message; corrupting the
 * user-facing response is not.
 */
final readonly class MemoryExtractionDispatcher
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Dispatch the command. No-op when {@code $command} is {@code null}
     * (extraction skipped, e.g. for widget/disabled-memory users).
     */
    public function dispatch(?ExtractMemoriesCommand $command): void
    {
        if (null === $command) {
            return;
        }

        try {
            $this->messageBus->dispatch($command);

            $this->logger->info('MemoryExtractionDispatcher: Dispatched ExtractMemoriesCommand', [
                'message_id' => $command->getMessageId(),
                'user_id' => $command->getUserId(),
                'thread_length' => count($command->getThreadSnapshot()),
            ]);
        } catch (\Throwable $e) {
            // Never block the user-facing response on a dispatch hiccup —
            // worst case is one message worth of memories never gets
            // extracted; the next message's extraction picks up from
            // where we left off.
            $this->logger->warning('MemoryExtractionDispatcher: Failed to dispatch ExtractMemoriesCommand', [
                'message_id' => $command->getMessageId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
