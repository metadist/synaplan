<?php

declare(strict_types=1);

namespace App\Service\Tool\Policy;

use App\Service\Compute\ComputeConfig;
use App\Service\Multitask\Plan\Capability;
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
        private ?ComputeConfig $computeConfig = null,
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
        ?PolicyOutcome $nodeOverride = null,
    ): PolicyOutcome {
        $class = $tool->sideEffect;
        $resolved = $this->mostRestrictive([
            $this->baseOutcome($tool, $actorId, $class, $context),
            $this->groupPolicy->outcomeFor($actorId, $tool, $class),
            $this->assistantPolicy->outcomeFor($assistantTools, $tool, $class),
            $this->hardBlock($tool, $class),
            $nodeOverride,
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

    private function baseOutcome(ToolDescriptor $tool, int $actorId, SideEffect $class, PolicyContext $context): PolicyOutcome
    {
        if (ToolDescriptor::POLICY_OWN_ARTEFACT === $tool->policyException && $actorId === $tool->ownerId) {
            return PolicyOutcome::Auto;
        }

        if (Capability::CodeRun->value === $tool->name || ToolSource::Compute === $tool->source) {
            return $this->computeBaseOutcome($context);
        }

        return $this->toolsConfig->defaultOutcome($class, $actorId);
    }

    private function computeBaseOutcome(PolicyContext $context): PolicyOutcome
    {
        $raw = PolicyContext::Unattended === $context
            ? ($this->computeConfig?->policyUnattended() ?? ComputeConfig::POLICY_APPROVE)
            : ($this->computeConfig?->policyInteractive() ?? ComputeConfig::POLICY_AUTO);

        return PolicyOutcome::tryFrom($raw) ?? PolicyOutcome::Approve;
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
