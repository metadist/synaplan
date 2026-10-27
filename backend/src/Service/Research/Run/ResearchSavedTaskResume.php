<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\SavedTask\SavedTaskResumeService;
use Psr\Log\LoggerInterface;

/**
 * When a research run that belongs to a saved task finishes, continue that
 * task with the report or fail the step with the outcome sentence.
 */
final readonly class ResearchSavedTaskResume
{
    public function __construct(
        private SavedTaskResumeService $tasks,
        private ResearchOutcomeText $outcomes,
        private UserRepository $users,
        private LoggerInterface $logger,
    ) {
    }

    public function after(ResearchRunState $state): void
    {
        if (!$state->status->isTerminal() || null === $state->savedTaskRunId || null === $state->savedTaskNodeId) {
            return;
        }

        try {
            $this->tasks->completeWaitingResearch(
                $state->savedTaskRunId,
                $state->savedTaskNodeId,
                $this->args($state),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Saved task was not continued after research', [
                'run_id' => $state->ref->id,
                'saved_task_run_id' => $state->savedTaskRunId,
                'exception' => $e,
            ]);
        }
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
