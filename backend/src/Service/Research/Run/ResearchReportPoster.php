<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * Puts a finished web run's report, or the sentence why it ended, where the
 * person will see it. Never throws: a failed post must not undo the run.
 */
interface ResearchReportPoster
{
    public function deliver(ResearchRunState $state): void;
}
