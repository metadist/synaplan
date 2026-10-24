<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Research\Run\Stage\StageContext;

interface ResearchStageContextFactory
{
    /**
     * @throws RoleUnavailable when the run has no model for a step
     */
    public function open(ResearchRunState $state): StageContext;
}
