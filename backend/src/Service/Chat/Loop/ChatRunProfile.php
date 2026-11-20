<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Tool\Loop\ToolLoopLimits;

/**
 * The `chat` run profile: no step cap and no deadline. The model decides how
 * long it works; a single step still runs at most the per-turn tool cap.
 */
final class ChatRunProfile
{
    public static function limits(): ToolLoopLimits
    {
        return ToolLoopLimits::unbounded();
    }
}
