<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * What one unit leaves behind: the unit to queue next, or none.
 */
final readonly class RunUnitResult
{
    public function __construct(public ?string $next = null)
    {
        if (null !== $next && 1 !== preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $next)) {
            throw new \InvalidArgumentException('A run unit needs a short name.');
        }
    }

    public static function done(): self
    {
        return new self();
    }
}
