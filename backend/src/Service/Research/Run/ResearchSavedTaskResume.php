<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Message\ContinueSavedTaskAfterResearch;
use App\Service\SavedTask\SavedTaskResearchContinuation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Continues the saved task that waits on a research run: with the report,
 * or by failing the step with the outcome sentence.
 *
 * Research can end before the saved task has paused on its step, and a
 * hand-over can fail for a moment. Both are tried again a little later. When
 * the retries run out, the waiting step fails, so a run never waits forever.
 */
final readonly class ResearchSavedTaskResume
{
    private const RETRY_DELAY_MS = 10_000;

    /** Thirty minutes of retries, longer than any saved task run takes to pause. */
    private const MAX_RETRIES = 180;

    private const DELETED = 'The research was deleted, so the next step did not run.';

    private const GAVE_UP = 'The research ended, but this saved task could not continue. The research result is in the chat.';

    public function __construct(
        private SavedTaskResearchContinuation $tasks,
        private ResearchTaskHandover $handover,
        private ResearchStateStore $store,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function continue(ContinueSavedTaskAfterResearch $message): void
    {
        try {
            $args = $this->args($message);
            if (null === $args) {
                return;
            }
            $run = $this->tasks->complete($message->taskRunId(), $message->nodeId(), $message->userId(), $args);
            if (null === $run && $this->tasks->isActive($message->taskRunId())) {
                $this->later($message, null);
            }
        } catch (\Throwable $e) {
            $this->later($message, $e);
        }
    }

    /**
     * @return array<string, mixed>|null null while the run has not ended
     */
    private function args(ContinueSavedTaskAfterResearch $message): ?array
    {
        $state = $message->deleted() ? null : $this->store->load($message->runId());
        if (null === $state) {
            return ['research_failed' => self::DELETED];
        }
        if (!$state->status->isTerminal()) {
            return null;
        }
        $state->reportFile ??= $message->reportFile();

        return $this->handover->args($state);
    }

    private function later(ContinueSavedTaskAfterResearch $message, ?\Throwable $error): void
    {
        $context = [
            'run_id' => $message->runId(),
            'saved_task_run_id' => $message->taskRunId(),
            'attempt' => $message->attempt(),
            'exception' => $error,
        ];
        if ($message->attempt() < self::MAX_RETRIES) {
            if (null !== $error) {
                $this->logger->warning('Saved task was not continued after research; trying again', $context);
            }
            $this->bus->dispatch($message->nextAttempt(), [new DelayStamp(self::RETRY_DELAY_MS)]);

            return;
        }

        $this->logger->error('Saved task was not continued after research; the waiting step fails', $context);
        $this->tasks->fail($message->taskRunId(), $message->nodeId(), self::GAVE_UP);
    }
}
