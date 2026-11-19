<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Service\Tool\Loop\AgentToolLoop;
use App\Service\Tool\Loop\ToolLoopLimits;

/**
 * The one place for the tool-loop caps. {@see ToolLoopLimits::defaults()}
 * passes them to {@see AgentToolLoop}; every tool result is clamped here.
 */
final readonly class ToolLimits
{
    public const MAX_ITERATIONS_DEFAULT = 8;
    public const DEADLINE_SECONDS = 240;
    public const MAX_TOOLS_PER_TURN = 16;
    public const RESULT_CHARS = 12000;
    /** The result of a call past {@see self::MAX_TOOLS_PER_TURN}. */
    public const SKIPPED_RESULT = 'Tool call skipped: too many tool calls in one turn. Call it again in the next turn if you still need it.';

    public static function clamp(string $text): string
    {
        if (mb_strlen($text) > self::RESULT_CHARS) {
            return mb_substr($text, 0, self::RESULT_CHARS).'…';
        }

        return $text;
    }
}
