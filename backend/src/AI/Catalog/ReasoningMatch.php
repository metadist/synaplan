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
    case Any = 'any';
}
