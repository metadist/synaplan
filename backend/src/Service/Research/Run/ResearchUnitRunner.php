<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Research\Run\Stage\StageContext;
use App\Service\Research\Run\Stage\StageResult;

interface ResearchUnitRunner
{
    public function run(StageContext $ctx, string $unit): StageResult;
}
