<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Entity\User;
use App\Repository\UserRepository;

/**
 * What a finished research run hands to the saved-task step: the report, or
 * the sentence that says why there is no report.
 */
final readonly class ResearchTaskHandover
{
    public function __construct(
        private ResearchOutcomeText $outcomes,
        private UserRepository $users,
    ) {
    }

    /**
     * @return array{research_report: string, research_files?: list<array{path: string, type: string, local_path: string}>}|array{research_failed: string}
     */
    public function args(ResearchRunState $state): array
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
