<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Loop;

use App\Service\Research\Run\ResearchOutcome;

/**
 * What the loop should do next. A halt keeps what was found and starts
 * nothing else. A write hands the ledger to the report. Continue means
 * another round, and a hint tells the lead to change strategy.
 */
final readonly class LoopStop
{
    public const CONTINUE = 'continue';

    public const WRITE = 'write';

    public const HALT = 'halt';

    public function __construct(
        public string $next,
        public ?string $reason,
        public ?ResearchOutcome $outcome,
        public bool $hint,
    ) {
        if (!in_array($next, [self::CONTINUE, self::WRITE, self::HALT], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown loop step "%s".', $next));
        }
    }
}
