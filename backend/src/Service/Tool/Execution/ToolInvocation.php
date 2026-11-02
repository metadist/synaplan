<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\AI\Messages\Tools\WebFetchBudget;
use App\Entity\User;
use App\Service\Runtime\RuntimeProfile;

final readonly class ToolInvocation
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $toolName,
        public array $arguments,
        public ToolCaller $caller,
        public GateMode $gate,
        public bool $recordUsage = false,
        public ?GateRequest $decision = null,
        public bool $decideOnly = false,
        public ?User $user = null,
        public ?WebFetchBudget $pages = null,
        public ?RuntimeProfile $assistant = null,
    ) {
    }
}
