<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

enum ResearchDepth: string
{
    case Quick = 'quick';
    case Standard = 'standard';
    case Thorough = 'thorough';

    /**
     * The chat chose the effort. The run starts on the standard row and the
     * plan stage shrinks it to the quick ceiling when the plan is small.
     */
    case Auto = 'auto';

    /** The budget row and the mode rules this depth starts from. Auto is sized like standard. */
    public function budgetRow(): self
    {
        return self::Auto === $this ? self::Standard : $this;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $depth): string => $depth->value, self::cases());
    }
}
