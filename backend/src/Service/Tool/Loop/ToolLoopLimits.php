<?php

declare(strict_types=1);

namespace App\Service\Tool\Loop;

use App\Service\Tool\Execution\ToolLimits;

/**
 * The bounds of one agent run, passed in by the caller's profile.
 *
 * `null` means "no bound": a profile may run without an iteration cap and
 * without a deadline. Every run still caps the tools of a single turn.
 */
final readonly class ToolLoopLimits
{
    public function __construct(
        public ?int $maxIterations,
        public ?int $deadlineSeconds,
        public int $maxToolsPerTurn = ToolLimits::MAX_TOOLS_PER_TURN,
    ) {
        if ($maxToolsPerTurn < 1) {
            throw new \InvalidArgumentException('A turn must allow at least one tool.');
        }
        if (null !== $maxIterations && $maxIterations < 0) {
            throw new \InvalidArgumentException('An iteration cap cannot be negative.');
        }
        if (null !== $deadlineSeconds && $deadlineSeconds < 0) {
            throw new \InvalidArgumentException('A deadline cannot be negative.');
        }
    }

    /**
     * Today's caps. The iteration cap is the caller's per-user setting.
     */
    public static function defaults(int $maxIterations = ToolLimits::MAX_ITERATIONS_DEFAULT): self
    {
        return new self($maxIterations, ToolLimits::DEADLINE_SECONDS, ToolLimits::MAX_TOOLS_PER_TURN);
    }

    public static function unbounded(int $maxToolsPerTurn = ToolLimits::MAX_TOOLS_PER_TURN): self
    {
        return new self(null, null, $maxToolsPerTurn);
    }
}
