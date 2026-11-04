<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a finished research run hands to the saved-task step: the report the
 * person would read, with its sources written out so a later step (an email)
 * can quote them, or the sentence that says why there is no report. No file
 * is created.
 */
final readonly class ResearchTaskHandover
{
    private const DOMAIN = 'research';

    public function __construct(
        private ResearchOutcomeText $outcomes,
        private UserRepository $users,
        private TranslatorInterface $translator,
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
            ResearchOutcome::COMPLETE_AT_LIMIT,
            ResearchOutcome::COMPLETE_SNIPPETS_ONLY,
        ], true);
        if ($finished && '' !== $report) {
            $args = ['research_report' => $this->reportForTheNextStep($state, $report, $code)];
            if (is_array($state->reportFile)) {
                $args['research_files'] = [$state->reportFile];
            }

            return $args;
        }

        return ['research_failed' => $this->outcomes->sentence($state->outcome, $this->locale($state))];
    }

    private function reportForTheNextStep(ResearchRunState $state, string $report, ?string $code): string
    {
        $reader = ReaderReport::from($report, $state->ledger);
        $markdown = $reader['markdown'];
        if ('' === $markdown) {
            return $report;
        }
        $locale = $this->locale($state);
        $body = ResearchOutcome::COMPLETE === $code
            ? $markdown
            : '> '.$this->outcomes->sentence($state->outcome, $locale)."\n\n".$markdown;

        return ReaderReport::withSourceList(
            $body,
            $reader['sources'],
            $this->translator->trans('research.report.sources', [], self::DOMAIN, $locale),
        );
    }

    private function locale(ResearchRunState $state): string
    {
        $user = $this->users->find($state->ref->userId);

        return $user instanceof User ? $user->getLocale() : 'en';
    }
}
