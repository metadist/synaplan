<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/** Puts a finished web run's report where the person will see it. */
interface ResearchReportPoster
{
    public function deliver(ResearchRunState $state): void;
}
