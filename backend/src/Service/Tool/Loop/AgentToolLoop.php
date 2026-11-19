<?php

declare(strict_types=1);

namespace App\Service\Tool\Loop;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/**
 * The one agent tool loop under document chat and both gateways.
 *
 * It owns the iteration cap, the deadline and the per-turn tool cap, all
 * read from the caller's {@see ToolLoopLimits}. The adapter owns the wire:
 * the model call, which calls are Synaplan's, and the tool turn.
 */
final readonly class AgentToolLoop
{
    public function __construct(
        private ClockInterface $clock = new Clock(),
    ) {
    }

    public function run(ToolLoopLimits $limits, ToolLoopAdapter $adapter): ToolLoopRun
    {
        $deadline = null === $limits->deadlineSeconds ? null : $this->now() + $limits->deadlineSeconds;
        $iterations = 0;

        while (null === $limits->maxIterations || $iterations < $limits->maxIterations) {
            if (null !== $deadline && $this->now() > $deadline) {
                return new ToolLoopRun(ToolLoopStop::Deadline, $iterations);
            }

            $turn = $adapter->turn($iterations);
            ++$iterations;
            if (null !== $turn->stop) {
                return new ToolLoopRun($turn->stop, $iterations);
            }
            if ([] === $turn->ours) {
                continue;
            }

            $stop = $adapter->execute(
                \array_slice($turn->ours, 0, $limits->maxToolsPerTurn),
                \array_slice($turn->ours, $limits->maxToolsPerTurn),
            );
            if (null !== $stop) {
                return new ToolLoopRun($stop, $iterations);
            }
        }

        return new ToolLoopRun(ToolLoopStop::Iterations, $iterations);
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
