<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Entity\User;
use App\Message\ContinueSavedTaskAfterResearch;
use App\Repository\UserRepository;
use App\Service\SavedTask\SavedTaskResumeService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * When a research run that belongs to a saved task finishes, continue that
 * task with the report or fail the step with the outcome sentence.
 *
 * Research can finish before the saved task has paused on its step. Then
 * the hand-over is tried again a little later.
 */
final readonly class ResearchSavedTaskResume
{
    private const RETRY_DELAY_MS = 10_000;

    /** Thirty minutes of retries, longer than any saved task run takes to pause. */
    private const MAX_RETRIES = 180;

    public function __construct(
        private SavedTaskResumeService $tasks,
        private ResearchOutcomeText $outcomes,
        private UserRepository $users,
        private LoggerInterface $logger,
        private ?MessageBusInterface $bus = null,
        private ?ResearchStateStore $store = null,
    ) {
    }

    public function retry(string $runId, int $attempt): void
    {
        $state = $this->store?->load($runId);
        if (null !== $state) {
            $this->after($state, $attempt);
        }
    }

    public function after(ResearchRunState $state, int $attempt = 0): void
    {
        if (!$state->status->isTerminal() || null === $state->savedTaskRunId || null === $state->savedTaskNodeId) {
            return;
        }

        try {
            $run = $this->tasks->completeWaitingResearch(
                $state->savedTaskRunId,
                $state->savedTaskNodeId,
                $this->args($state),
            );
            if (null === $run && $this->tasks->isRunning($state->savedTaskRunId)) {
                $this->later($state, $attempt);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Saved task was not continued after research', [
                'run_id' => $state->ref->id,
                'saved_task_run_id' => $state->savedTaskRunId,
                'exception' => $e,
            ]);
        }
    }

    private function later(ResearchRunState $state, int $attempt): void
    {
        if (null === $this->bus || $attempt >= self::MAX_RETRIES) {
            $this->logger->warning('Saved task never paused on its research step', [
                'run_id' => $state->ref->id,
                'saved_task_run_id' => $state->savedTaskRunId,
            ]);

            return;
        }
        $this->bus->dispatch(
            new ContinueSavedTaskAfterResearch($state->ref->id, $attempt + 1),
            [new DelayStamp(self::RETRY_DELAY_MS)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function args(ResearchRunState $state): array
    {
        $report = trim($state->ledger->report() ?? '');
        $code = $state->outcome?->code;
        $finished = in_array($code, [
            ResearchOutcome::COMPLETE,
            ResearchOutcome::COMPLETE_PARTIAL,
            ResearchOutcome::COMPLETE_SNIPPETS_ONLY,
        ], true);
        if ($finished && '' !== $report) {
            $args = ['research_report' => $report];
            if (is_array($state->reportFile)) {
                $args['research_files'] = [$state->reportFile];
            }

            return $args;
        }
        $user = $this->users->find($state->ref->userId);
        $locale = $user instanceof User ? $user->getLocale() : 'en';

        return ['research_failed' => $this->outcomes->sentence($state->outcome, $locale)];
    }
}
