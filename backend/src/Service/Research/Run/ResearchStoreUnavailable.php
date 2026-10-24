<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * Redis did not accept a write. The sentence is safe to show: it names the
 * recovery and does not pretend that a step already underway was free.
 */
final class ResearchStoreUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Research could not be saved just now. Start it again in a moment. A step that was already underway may still count.');
    }
}
