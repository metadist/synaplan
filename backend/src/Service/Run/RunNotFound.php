<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * The run is gone: an unknown id, or its state expired or was deleted. The
 * message is the sentence a person can read; the id stays here for logs.
 */
final class RunNotFound extends \RuntimeException
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct('This run is no longer on the server. Start it again.');
    }
}
