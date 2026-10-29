<?php

declare(strict_types=1);

namespace App\Service\Message\Effort;

/**
 * The one reason the sorter names for its effort rating. A closed code: the
 * clients map it to a sentence in the person's language, the sorter writes
 * no free text.
 */
enum EffortReason: string
{
    case Simple = 'simple';
    case CurrentFact = 'current_fact';
    case SeveralParts = 'several_parts';
    case Comparison = 'comparison';
    case Procedure = 'procedure';
    case AskedShort = 'asked_short';
    case AskedThorough = 'asked_thorough';
    case FollowUp = 'follow_up';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function defaultFor(Effort $effort): self
    {
        return match ($effort) {
            Effort::Answer => self::Simple,
            Effort::Lookup => self::CurrentFact,
            Effort::Research => self::SeveralParts,
        };
    }
}
