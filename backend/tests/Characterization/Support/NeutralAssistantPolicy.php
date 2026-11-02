<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

use App\Service\Tool\Policy\AssistantPolicyProviderInterface;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;

final class NeutralAssistantPolicy implements AssistantPolicyProviderInterface
{
    public function outcomeFor(?array $assistantTools, ToolDescriptor $tool, SideEffect $class): ?PolicyOutcome
    {
        return null;
    }
}
