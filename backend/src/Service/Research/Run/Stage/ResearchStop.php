<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

/** Set by the person pressing Stop. The next paid call must not start. */
final class ResearchStop
{
    public function __construct(public bool $stopped = false)
    {
    }
}
