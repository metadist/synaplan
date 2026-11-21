<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * The cost limit before one model call of a run: the reason to stop, if
 * any, what the run may spend in all (null without a monthly budget), and
 * whether it is close enough to that to be told to finish up.
 */
final readonly class ChatRunCostCheck
{
    public function __construct(
        public ?ChatRunEnd $end,
        public ?int $limitMicro = null,
        public bool $nearlyUsed = false,
    ) {
    }
}
