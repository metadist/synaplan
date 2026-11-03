<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * What a finished run says in its chat reply: the reader's report, the
 * outcome sentence above it when the run did not complete, or the sentence
 * alone (with the direct answer) when there is no report. The sources stay
 * beside the reply, where the chat lists them.
 */
final readonly class ResearchReplyText
{
    public function __construct(
        private ResearchOutcomeText $outcomes,
        private ?ResearchDirectAnswer $direct = null,
    ) {
    }

    /**
     * @return array{text: string, sources: list<array{title: string, url: string, host: string, description: string}>}
     */
    public function compose(ResearchRunState $state, string $locale): array
    {
        $report = trim($state->ledger->report() ?? '');
        $outcome = $state->outcome;
        if ('' === $report) {
            $sentence = $this->outcomes->sentence($outcome, $locale);
            $answer = ResearchOutcome::ANSWERED_WITHOUT_RESEARCH === $outcome?->code ? $this->direct?->answer($state) : null;

            return ['text' => null === $answer ? $sentence : '> '.$sentence."\n\n".$answer, 'sources' => []];
        }

        $reader = ReaderReport::from($report, $state->ledger);
        $markdown = $reader['markdown'];
        if ('' === $markdown) {
            return ['text' => $this->outcomes->sentence($outcome, $locale), 'sources' => []];
        }
        $text = ResearchOutcome::COMPLETE === $outcome?->code
            ? $markdown
            : '> '.$this->outcomes->sentence($outcome, $locale)."\n\n".$markdown;

        return [
            'text' => $text,
            'sources' => $reader['sources'],
        ];
    }
}
