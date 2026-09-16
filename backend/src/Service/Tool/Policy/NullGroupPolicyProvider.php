<?php

declare(strict_types=1);

namespace App\Service\Tool\Policy;

use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(GroupPolicyProviderInterface::class)]
final readonly class NullGroupPolicyProvider implements GroupPolicyProviderInterface
{
    public function outcomeFor(int $userId, ToolDescriptor $tool, SideEffect $class): ?PolicyOutcome
    {
        return null;
    }
}
