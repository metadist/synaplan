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
     * Calls past the cap are not run. When the adapter replays the model's
     * whole turn, it answers each of them with
     * {@see \App\Service\Tool\Execution\ToolLimits::SKIPPED_RESULT}, so
     * every replayed call keeps its result and the next upstream call is
     * not rejected.
     *
     * @param list<array<string, mixed>> $ours
     * @param list<array<string, mixed>> $skipped
     *
     * @return ToolLoopStop|null null takes the next turn
     */
    public function execute(array $ours, array $skipped): ?ToolLoopStop;
}
