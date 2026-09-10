<?php

declare(strict_types=1);

namespace App\Service\Tool\Policy;

use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use App\Service\Tool\ToolsConfig;

/**
 * Resolves auto / approve / block. Most restrictive wins (block > approve > auto).
 * `allow_unattended` can only turn approve into auto for write-class, never unblock.
 */
final readonly class ApprovalPolicy
{
    public function __construct(
        private ToolsConfig $toolsConfig,
        private GroupPolicyProviderInterface $groupPolicy,
        private AssistantPolicyProviderInterface $assistantPolicy,
    ) {
    }

    /**
     * @param array<string, mixed>|null $assistantTools
     */
    public function decide(
        ToolDescriptor $tool,
        int $actorId,
        PolicyContext $context,
        ?array $assistantTools = null,
        bool $allowUnattended = false,
        ?string $assistantKey = null,
    ): PolicyOutcome {
        $class = $tool->sideEffect;
        $base = $this->baseOutcome($tool, $actorId, $class);
        $group = $this->groupPolicy->outcomeFor($actorId, $tool, $class);
        $agent = $this->assistantPolicy->outcomeFor($assistantTools, $tool, $class);
        $task = null;
        if (PolicyContext::Unattended === $context && $allowUnattended && SideEffect::Write === $class) {
            $task = PolicyOutcome::Auto;
        }
        $hard = $this->hardBlock($tool, $class);
        $user = $this->userOverride($actorId, $assistantKey, $tool);

        return $this->mostRestrictive([$base, $group, $agent, $task, $hard, $user]);
    }

    public function canAlwaysAllow(PolicyOutcome $resolved): bool
    {
        return PolicyOutcome::Approve === $resolved;
    }

    private function baseOutcome(ToolDescriptor $tool, int $actorId, SideEffect $class): PolicyOutcome
    {
        if (ToolDescriptor::POLICY_OWN_ARTEFACT === $tool->policyException && $actorId === $tool->ownerId) {
            return PolicyOutcome::Auto;
        }

        return $this->toolsConfig->defaultOutcome($class, $actorId);
    }

    private function hardBlock(ToolDescriptor $tool, SideEffect $class): ?PolicyOutcome
    {
        if (ToolSource::Mcp !== $tool->source) {
            return null;
        }
        $allowWrite = (bool) ($tool->meta['allowWrite'] ?? false);
        if (!$allowWrite && SideEffect::Read !== $class) {
            return PolicyOutcome::Block;
        }

        return null;
    }

    private function userOverride(int $actorId, ?string $assistantKey, ToolDescriptor $tool): ?PolicyOutcome
    {
        if (null === $assistantKey || '' === $assistantKey) {
            return null;
        }
        $allowed = $this->toolsConfig->alwaysAllowTools($actorId, $assistantKey);
        if (in_array($tool->name, $allowed, true) || in_array($tool->callName(), $allowed, true)) {
            return PolicyOutcome::Auto;
        }

        return null;
    }

    /**
     * @param list<PolicyOutcome|null> $outcomes
     */
    private function mostRestrictive(array $outcomes): PolicyOutcome
    {
        $best = PolicyOutcome::Auto;
        foreach ($outcomes as $outcome) {
            if (null !== $outcome && $outcome->isMoreRestrictiveThan($best)) {
                $best = $outcome;
            }
        }

        return $best;
    }
}
