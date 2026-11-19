<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * Turns a stored run back into its state. One per kind of run.
 */
interface RunStateCodec
{
    /**
     * Null when the stored form is unreadable and counts as no run, the way a
     * kind that never raised on a broken form treats it.
     *
     * @throws \UnexpectedValueException when the stored form is not a run of this kind
     */
    public function decode(string $stored): ?RunState;
}
