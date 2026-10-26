<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

enum ResearchStatus: string
{
    case Briefing = 'briefing';
    case WaitingAnswer = 'waiting_answer';
    case Planning = 'planning';
    case WaitingPlan = 'waiting_plan';
    case Gathering = 'gathering';
    case Writing = 'writing';
    case Checking = 'checking';
    case Complete = 'complete';
    case Stopped = 'stopped';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Complete, self::Stopped, self::Failed => true,
            default => false,
        };
    }
}
