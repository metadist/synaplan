<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a finished run says in its chat reply: the reader's report, the
 * outcome sentence above it when the run did not complete, or the sentence
 * alone (with the direct answer) when there is no report. The attached file
 * carries the same report with its sources listed, so it reads on its own.
 */
final readonly class ResearchReplyText
{
    private const DOMAIN = 'research';

    public function __construct(
        private ResearchOutcomeText $outcomes,
        private TranslatorInterface $translator,
        private ?ResearchDirectAnswer $direct = null,
    ) {
    }

    /**
     * @return array{text: string, file: ?string, sources: list<array{title: string, url: string, host: string, description: string}>}
     */
    public function compose(ResearchRunState $state, string $locale): array
    {
        $report = trim($state->ledger->report() ?? '');
        $outcome = $state->outcome;
        if ('' === $report) {
            $sentence = $this->outcomes->sentence($outcome, $locale);
            $answer = ResearchOutcome::ANSWERED_WITHOUT_RESEARCH === $outcome?->code ? $this->direct?->answer($state) : null;

            return ['text' => null === $answer ? $sentence : '> '.$sentence."\n\n".$answer, 'file' => null, 'sources' => []];
        }

        $reader = ReaderReport::from($report, $state->ledger);
        $markdown = '' !== $reader['markdown'] ? $reader['markdown'] : $report;
        $text = ResearchOutcome::COMPLETE === $outcome?->code
            ? $markdown
            : '> '.$this->outcomes->sentence($outcome, $locale)."\n\n".$markdown;
        $title = $this->translator->trans('research.report.sources', [], self::DOMAIN, $locale);

        return [
            'text' => $text,
            'file' => ReaderReport::withSourceList($markdown, $reader['sources'], $title),
            'sources' => $reader['sources'],
        ];
    }
}
