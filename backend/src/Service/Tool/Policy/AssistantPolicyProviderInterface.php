<?php

declare(strict_types=1);

namespace App\Service\Tool\Policy;

use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;

interface AssistantPolicyProviderInterface
{
    /**
     * @param array<string, mixed>|null $assistantTools agent.v1 `tools` document, or null when no assistant is pinned
     */
    public function outcomeFor(?array $assistantTools, ToolDescriptor $tool, SideEffect $class): ?PolicyOutcome;
}
