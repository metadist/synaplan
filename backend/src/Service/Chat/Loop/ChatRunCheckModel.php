<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Research\Run\ResearchModelChoice;

/**
 * The model that checks a chat run's answer against its sources, or null
 * when the run gets only the check that needs no model.
 */
interface ChatRunCheckModel
{
    public function pick(ChatRunState $state): ?ResearchModelChoice;
}
