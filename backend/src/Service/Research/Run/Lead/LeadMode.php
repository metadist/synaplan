<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

/** Who picks the next search. Tools is the lead; rounds is the engine. */
enum LeadMode: string
{
    case Tools = 'tools';
    case Rounds = 'rounds';
}
