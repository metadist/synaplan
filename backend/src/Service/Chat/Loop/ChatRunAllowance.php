<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * What a payer may still spend, read before a model call of a run. Amounts
 * are what the person is billed, in micro-dollars; null means the plan has
 * no monthly budget, so there is no such limit.
 */
final readonly class ChatRunAllowance
{
    public function __construct(
        public bool $allowed,
        public ?int $leftMicro,
        public ?int $runCapMicro,
        public ?int $monthlyMicro = null,
    ) {
    }
}
