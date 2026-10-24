<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

enum ResearchDepth: string
{
    case Quick = 'quick';
    case Standard = 'standard';
    case Thorough = 'thorough';
}
