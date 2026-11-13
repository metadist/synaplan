<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * How a reasoning-level rule matches a model id.
 */
enum ReasoningMatch: string
{
    case Prefix = 'prefix';
    case Contains = 'contains';
    /** The whole model id, so a longer suffixed id does not inherit the rule. */
    case Exact = 'exact';
    case Any = 'any';
}
