<?php

declare(strict_types=1);

namespace App\Service\Tool\Loop;

/**
 * The wire side of one agent run: calls the model, reads its tool calls,
 * runs Synaplan's and appends the tool turn. One instance per run.
 *
 * A failed tool comes back to the model as a tool result, never as a throw:
 * the adapter's executor turns the outcome into the result text.
 */
interface ToolLoopAdapter
{
    public function turn(int $iteration): ToolLoopTurn;

    /**
     * Runs the turn's Synaplan-owned calls, already cut to the per-turn cap,
     * and appends the tool turn for the next model call.
     *
     * @param list<array<string, mixed>> $ours
     *
     * @return ToolLoopStop|null null takes the next turn
     */
    public function execute(array $ours): ?ToolLoopStop;
}
