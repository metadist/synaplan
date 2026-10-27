<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\ResearchFollowUp;
use App\Service\Research\Run\ResearchRunState;

/** The writer's task for a follow-up. A fresh run still just writes the report. */
final class FollowUpTask
{
    public static function text(ResearchRunState $state): string
    {
        $prior = null !== $state->priorReport && '' !== trim($state->priorReport)
            ? "\n\nPrevious report:\n".$state->priorReport
            : '';
        $focus = '' !== trim($state->instruction) ? "\n\nAlso cover: ".$state->instruction : '';

        return match ($state->followUp) {
            ResearchFollowUp::QUESTION => 'Answer only this question. Use the notes, and keep a citation that still applies.'."\n\n".$state->instruction.$prior,
            ResearchFollowUp::REVISE => $state->instruction."\n\nLeave a sentence as it is when it should stay, and keep its citation.".$prior,
            ResearchFollowUp::REFRESH => $state->instruction."\n\nKeep a citation that is still true.".$prior,
            ResearchFollowUp::DEEPER => 'Write the report again with the new findings. Keep what still holds, and keep its citation.'.$focus.$prior,
            default => 'Write the report.',
        };
    }
}
