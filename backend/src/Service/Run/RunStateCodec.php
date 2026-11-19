<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * Turns a stored run back into its state. One per kind of run.
 */
interface RunStateCodec
{
    /**
     * @throws \UnexpectedValueException when the stored form is not a run of this kind
     */
    public function decode(string $stored): RunState;
}
