<?php

declare(strict_types=1);

namespace App\Service\Tool\Policy;

use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Reads `tools.policy` from an assistant definition: per-tool name first,
 * then per-class (`read` / `write` / `destructive`).
 */
#[AsAlias(AssistantPolicyProviderInterface::class)]
final readonly class DefinitionAssistantPolicyProvider implements AssistantPolicyProviderInterface
{
    public function outcomeFor(?array $assistantTools, ToolDescriptor $tool, SideEffect $class): ?PolicyOutcome
    {
        if (null === $assistantTools) {
            return null;
        }
        $policy = $assistantTools['policy'] ?? null;
        if (!is_array($policy)) {
            return null;
        }
        foreach ([$tool->name, $tool->callName(), $class->value] as $key) {
            $raw = $policy[$key] ?? null;
            if (is_string($raw)) {
                $outcome = PolicyOutcome::tryFrom($raw);
                if (null !== $outcome) {
                    return $outcome;
                }
            }
        }

        return null;
    }
}
