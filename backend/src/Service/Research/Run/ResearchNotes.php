<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/** Answers the person already gave, so the next step can use them. */
final class ResearchNotes
{
    public const SKIPPED = 'Skipped';

    public static function text(ResearchRunState $state): string
    {
        if ([] === $state->replies) {
            return '';
        }
        $lines = [];
        foreach ($state->replies as $reply) {
            $answer = self::SKIPPED === $reply['answer'] ? '(no answer, use your own judgement)' : $reply['answer'];
            $lines[] = $reply['question'].': '.$answer;
        }

        return "The person answered:\n".implode("\n", $lines);
    }
}
