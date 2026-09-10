<?php

declare(strict_types=1);

namespace App\Service\Tool\Policy;

use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;

/**
 * Resolves auto / approve / block. Most restrictive wins (block > approve > auto)
 * across the instance default, group policy, assistant definition and the MCP
 * hard block. Only afterwards may a resolved `approve` be loosened to `auto` —
 * by `allow_unattended` on an unattended write-class step, or by the owner's
 * "always allow" list for the assistant. Neither can unblock.
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
        $resolved = $this->mostRestrictive([
            $this->baseOutcome($tool, $actorId, $class),
            $this->groupPolicy->outcomeFor($actorId, $tool, $class),
            $this->assistantPolicy->outcomeFor($assistantTools, $tool, $class),
            $this->hardBlock($tool, $class),
        ]);
        if (PolicyOutcome::Approve !== $resolved || SideEffect::Write !== $class) {
            return $resolved;
        }
        if (PolicyContext::Unattended === $context && $allowUnattended) {
            return PolicyOutcome::Auto;
        }
        if ($this->isAlwaysAllowed($actorId, $assistantKey, $tool)) {
            return PolicyOutcome::Auto;
        }

        return $resolved;
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

    private function isAlwaysAllowed(int $actorId, ?string $assistantKey, ToolDescriptor $tool): bool
    {
        if (null === $assistantKey || '' === $assistantKey) {
            return false;
        }
        $allowed = $this->toolsConfig->alwaysAllowTools($actorId, $assistantKey);

        return in_array($tool->name, $allowed, true) || in_array($tool->callName(), $allowed, true);
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
