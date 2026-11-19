<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Run\RunUnitBus;

/** Queues the next research step; the run kernel's bus for research runs. */
interface ResearchUnitBus extends RunUnitBus
{
}
