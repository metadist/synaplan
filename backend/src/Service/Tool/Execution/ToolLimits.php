<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

/**
 * The one place for the tool-loop caps. The three loops still carry the same
 * numbers until AU26 deletes those copies.
 */
final readonly class ToolLimits
{
    public const MAX_ITERATIONS_DEFAULT = 8;
    public const DEADLINE_SECONDS = 240;
    public const MAX_TOOLS_PER_TURN = 16;
    public const RESULT_CHARS = 12000;

    public static function clamp(string $text): string
    {
        if (mb_strlen($text) > self::RESULT_CHARS) {
            return mb_substr($text, 0, self::RESULT_CHARS).'…';
        }

        return $text;
    }
}
