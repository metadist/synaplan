<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Run\RunReapPolicy;
use App\Service\Run\RunState;

/**
 * Reaping rules of the test kind: a run in `waiting` is stopped after an
 * hour; an interrupted run keeps the steps it did.
 */
final class TestRunReapPolicy implements RunReapPolicy
{
    public const WAIT_SECONDS = 3600;

    /** @var list<string> */
    public array $closed = [];

    /** @var list<string> */
    public array $waited = [];

    public function __construct(private readonly ?TestRunUnitHandler $handler = null)
    {
    }

    public function isWaiting(RunState $state): bool
    {
        return $state instanceof TestRunState && 'waiting' === $state->status;
    }

    public function reapWait(RunState $state, int $ageSeconds): bool
    {
        if ($ageSeconds < self::WAIT_SECONDS) {
            return false;
        }
        $this->waited[] = $state->runId();

        return true;
    }

    public function resumeUnit(RunState $state): string
    {
        return 'step';
    }

    public function interrupt(RunState $fresh, int $attempt): bool
    {
        \assert($fresh instanceof TestRunState);
        $fresh->status = 'interrupted';

        return true;
    }

    public function closed(RunState $state): void
    {
        $this->closed[] = $state->runId();
        $this->handler?->deliver($state);
    }
}
