<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * The run is gone (unknown id, or its state expired). The message is the
 * sentence a person can read; the id stays on the exception for logs.
 */
final class ResearchRunNotFound extends \RuntimeException
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct('This research is no longer on the server. Start it again.');
    }
}
