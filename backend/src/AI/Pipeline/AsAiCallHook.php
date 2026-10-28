<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * Marks a service as an AI call pipeline hook (`03_contracts.md` §1.2).
 *
 * The phase is the method implemented (`before()` or `after()`), never an
 * attribute property. Both phases run in descending `$priority` order.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsAiCallHook
{
    public function __construct(
        public int $priority,
        public bool $blocking = false,
        public int $budgetMs = 1,
        public HookFailure $onError = HookFailure::Open,
        public ?string $flag = null,
    ) {
    }
}
