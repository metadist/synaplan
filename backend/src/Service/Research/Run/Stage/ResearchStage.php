<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

interface ResearchStage
{
    public function run(StageContext $ctx): StageResult;
}
