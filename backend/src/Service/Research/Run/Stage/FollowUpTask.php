<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\ResearchRunState;

/** The writer's task for a follow-up. A fresh run still just writes the report. */
final class FollowUpTask
{
    public static function text(ResearchRunState $state): string
    {
        $prior = null !== $state->priorReport && '' !== trim($state->priorReport)
            ? "\n\nPrevious report:\n".$state->priorReport
            : '';

        return match ($state->followUp) {
            'question' => 'Answer only this question. Use the notes, and keep a citation that still applies.'."\n\n".$state->instruction.$prior,
            'revise' => $state->instruction."\n\nLeave a sentence as it is when it should stay, and keep its citation.".$prior,
            'refresh' => $state->instruction."\n\nKeep a citation that is still true.".$prior,
            'deeper' => '' !== trim($state->instruction) ? $state->instruction : 'Write the report.',
            default => 'Write the report.',
        };
    }
}
