<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchRoles;
use App\Service\Research\Run\ResearchRunState;

/** What one stage can see. The run, the budget and the ledger live on the state. */
final class StageContext
{
    public function __construct(
        public ResearchRunState $state,
        public ResearchRoles $roles,
        public ResearchEventLog $events,
        public \DateTimeImmutable $today,
        public ResearchStop $stop,
        public ResearchWriteNow $writeNow = new ResearchWriteNow(),
        public string $timezone = 'UTC',
        public string $rules = '',
        public string $turns = '',
        public string $sourcesOn = 'web',
    ) {
    }
}
