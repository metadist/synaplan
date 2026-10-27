<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Message\ContinueSavedTaskAfterResearch;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Tells the saved task that waits on a research run that the run ended or
 * was deleted. Only queues the hand-over: the next steps may call models and
 * send mail, so they never run inside a research unit or a stop request.
 */
final readonly class ResearchSavedTaskSignal
{
    public function __construct(
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function after(ResearchRunState $state): void
    {
        if ($state->status->isTerminal()) {
            $this->send($state, false);
        }
    }

    public function deleted(ResearchRunState $state): void
    {
        $this->send($state, true);
    }

    private function send(ResearchRunState $state, bool $deleted): void
    {
        if (null === $state->savedTaskRunId || null === $state->savedTaskNodeId) {
            return;
        }

        try {
            $this->bus->dispatch(new ContinueSavedTaskAfterResearch(
                $state->ref->id,
                $state->ref->userId,
                $state->savedTaskRunId,
                $state->savedTaskNodeId,
                0,
                $state->reportFile,
                $deleted,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Saved task hand-over after research was not queued', [
                'run_id' => $state->ref->id,
                'saved_task_run_id' => $state->savedTaskRunId,
                'exception' => $e,
            ]);
        }
    }
}
