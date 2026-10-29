<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchLease;
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

    /** Set by the worker for the step it claimed. Null in a test that does not hold a lease. */
    public ?ResearchLease $lease = null;

    /** The person switched the web on. A run that names only files does not search the web. */
    public function webOn(): bool
    {
        return $this->hasSource('web');
    }

    /** The person switched files on as a source; otherwise a run never searches or reads them. */
    public function filesOn(): bool
    {
        return $this->hasSource('files');
    }

    private function hasSource(string $name): bool
    {
        return in_array($name, preg_split('/[\s,]+/', strtolower($this->sourcesOn)) ?: [], true);
    }
}
