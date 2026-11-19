<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Loop;

use App\Service\Tool\Loop\ToolLoopAdapter;
use App\Service\Tool\Loop\ToolLoopStop;
use App\Service\Tool\Loop\ToolLoopTurn;
use Symfony\Component\Clock\MockClock;

/**
 * Plays back scripted model turns and records what the loop handed back.
 */
final class ScriptedToolLoopAdapter implements ToolLoopAdapter
{
    /** @var list<int> */
    public array $turns = [];

    /** @var list<list<array<string, mixed>>> */
    public array $executed = [];

    /** @var list<list<array<string, mixed>>> */
    public array $skipped = [];

    /**
     * @param \Closure(int): ToolLoopTurn $script
     * @param ToolLoopStop|null           $stopAfterExecute what execute() answers
     */
    public function __construct(
        private readonly \Closure $script,
        private readonly ?MockClock $clock = null,
        private readonly int $secondsPerTurn = 0,
        private readonly ?ToolLoopStop $stopAfterExecute = null,
    ) {
    }

    public function turn(int $iteration): ToolLoopTurn
    {
        $this->turns[] = $iteration;
        if (null !== $this->clock && $this->secondsPerTurn > 0) {
            $this->clock->sleep($this->secondsPerTurn);
        }

        return ($this->script)($iteration);
    }

    public function execute(array $ours, array $skipped): ?ToolLoopStop
    {
        $this->executed[] = $ours;
        $this->skipped[] = $skipped;

        return $this->stopAfterExecute;
    }
}
