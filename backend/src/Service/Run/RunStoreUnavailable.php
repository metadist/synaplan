<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * Redis did not answer. The sentence is safe to show: it names the recovery
 * and does not pretend that a step already underway was free.
 */
final class RunStoreUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The run could not be saved just now. Start it again in a moment. A step that was already underway may still count.');
    }
}
