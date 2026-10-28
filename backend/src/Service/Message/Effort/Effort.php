<?php

declare(strict_types=1);

namespace App\Service\Message\Effort;

/**
 * How much work the sorter says a message needs: one reply, one search with
 * a few pages, or a research run with a plan and sources per point.
 */
enum Effort: string
{
    case Answer = 'answer';
    case Lookup = 'lookup';
    case Research = 'research';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
