<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

use App\Service\Tool\Policy\GroupPolicyProviderInterface;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;

final class NeutralGroupPolicy implements GroupPolicyProviderInterface
{
    public function outcomeFor(int $userId, ToolDescriptor $tool, SideEffect $class): ?PolicyOutcome
    {
        return null;
    }
}
