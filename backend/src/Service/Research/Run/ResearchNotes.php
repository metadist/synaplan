<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/** Answers the person already gave, so the next step can use them. */
final class ResearchNotes
{
    public static function text(ResearchRunState $state): string
    {
        if ([] === $state->replies) {
            return '';
        }
        $lines = [];
        foreach ($state->replies as $reply) {
            $lines[] = $reply['question'].': '.$reply['answer'];
        }

        return "The person answered:\n".implode("\n", $lines);
    }
}
