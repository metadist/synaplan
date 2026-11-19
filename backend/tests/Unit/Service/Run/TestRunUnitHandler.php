<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Run\RunClaim;
use App\Service\Run\RunDelivery;
use App\Service\Run\RunState;
use App\Service\Run\RunUnitHandler;
use App\Service\Run\RunUnitResult;

/**
 * Units of the test kind: `step` makes one paid call and asks for `finish`;
 * `finish` completes the run. A finished run is posted into its chat through
 * the kernel's delivery.
 */
final class TestRunUnitHandler implements RunUnitHandler
{
    public const LEASE_MS = 240_000;

    public int $paidCalls = 0;

    /** @var list<string> */
    public array $chat = [];

    /** Runs inside the unit, before its paid call. */
    public ?\Closure $duringUnit = null;

    public function __construct(private readonly ?RunDelivery $delivery = null)
    {
    }

    public function knows(string $unit): bool
    {
        return \in_array($unit, ['step', 'finish'], true);
    }

    public function leaseMs(RunState $state, string $unit): int
    {
        return self::LEASE_MS;
    }

    public function run(RunState $state, string $unit, RunClaim $claim): RunUnitResult
    {
        \assert($state instanceof TestRunState);
        $during = $this->duringUnit;
        if (null !== $during) {
            $during($state, $claim);
        }
        if ($claim->cancelled()) {
            return $this->cancel($state);
        }
        ++$this->paidCalls;
        ++$state->paidCalls;
        $state->done[] = $unit;
        if ('finish' === $unit) {
            $state->status = 'completed';

            return RunUnitResult::done();
        }

        return new RunUnitResult('finish');
    }

    public function cancel(RunState $state): RunUnitResult
    {
        \assert($state instanceof TestRunState);
        $state->status = 'stopped';

        return RunUnitResult::done();
    }

    public function after(RunState $state, string $unit, RunUnitResult $result): void
    {
        $this->deliver($state);
    }

    public function deliver(RunState $state): void
    {
        if (null === $this->delivery || !$state->isTerminal()) {
            return;
        }
        \assert($state instanceof TestRunState);
        $this->delivery->once($state->runId(), function () use ($state): void {
            $this->chat[] = $state->runId().': '.$state->status;
        });
    }
}
