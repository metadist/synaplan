<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Run\RunKeys;

/**
 * The run kernel's Redis operations, plus the one research needs on its own:
 * keeping a source's extracted text. A missing key and a dead store are not
 * the same answer where a mix-up would hide a failure; a dead store raises
 * `ResearchStoreUnavailable`.
 */
interface ResearchRunKeys extends RunKeys
{
    /**
     * Keep a source's extracted text until the run expires. False when the
     * run is gone, so a worker cannot bring back a deleted run's text.
     */
    public function putSourceText(string $runId, string $sourceId, string $text): bool;
}
