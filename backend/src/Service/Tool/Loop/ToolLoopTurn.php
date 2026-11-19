<?php

declare(strict_types=1);

namespace App\Service\Tool\Loop;

/**
 * What one model turn asks the loop to do next.
 */
final readonly class ToolLoopTurn
{
    /**
     * @param list<array<string, mixed>> $ours
     */
    private function __construct(
        public ?ToolLoopStop $stop,
        public array $ours,
    ) {
    }

    /**
     * End the run. Only the loop reports the iteration cap and the deadline.
     */
    public static function stop(ToolLoopStop $reason): self
    {
        if ($reason->isLimit()) {
            throw new \InvalidArgumentException('Only the loop reports a limit stop.');
        }

        return new self($reason, []);
    }

    /**
     * Ask the model once more without running a tool. Spends an iteration.
     */
    public static function again(): self
    {
        return new self(null, []);
    }

    /**
     * Run these Synaplan-owned calls, then take the next turn.
     *
     * @param list<array<string, mixed>> $ours
     */
    public static function run(array $ours): self
    {
        if ([] === $ours) {
            throw new \InvalidArgumentException('A run turn needs at least one call.');
        }

        return new self(null, $ours);
    }
}
