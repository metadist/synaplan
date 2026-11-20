<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RunChatUnit;
use App\Service\Chat\Loop\ChatRunModelFailed;
use App\Service\Chat\Loop\ChatRunWorker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunChatUnitHandler
{
    public function __construct(
        private ChatRunWorker $worker,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * A failed model call keeps the unit's claim; the reaper queues the unit
     * once more after the lease, then ends the run as interrupted. A retry
     * from the queue would only find the claim taken.
     */
    public function __invoke(RunChatUnit $message): void
    {
        try {
            $this->worker->handle($message->runId(), $message->unit(), $message->seq());
        } catch (ChatRunModelFailed $e) {
            $this->logger->warning('A chat run unit failed at the model call', ['run_id' => $message->runId(), 'seq' => $message->seq(), 'error' => $e->getMessage()]);
        }
    }
}
