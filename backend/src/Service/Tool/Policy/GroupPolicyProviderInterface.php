<?php

declare(strict_types=1);

namespace App\Service\Tool\Policy;

use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;

interface GroupPolicyProviderInterface
{
    public function outcomeFor(int $userId, ToolDescriptor $tool, SideEffect $class): ?PolicyOutcome;
}
