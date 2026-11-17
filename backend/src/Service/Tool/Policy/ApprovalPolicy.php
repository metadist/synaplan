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
 * across the base outcome, group policy, assistant definition and the step.
 *
 * For an MCP tool the base outcome is the connection's own choice for that
 * tool (McpToolPermissions), which replaces the instance default. Nothing
 * loosens it afterwards: not the owner's "always allow" list, not an
 * `allow_unattended` step. An unattended run only tightens it: a write or
 * destructive tool that resolves to `auto` asks, unless the step allows
 * unattended runs. Every other tool keeps the instance default, and a
 * resolved `approve` may still loosen to `auto` by `allow_unattended` on an
 * unattended write-class step or by the owner's "always allow" list.
 * None of these can unblock.
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
     * @param bool                      $escalateToApproval the call's arguments delete content, so it asks
     *                                                      even when the tool is set to auto
     */
    public function decide(
        ToolDescriptor $tool,
        int $actorId,
        PolicyContext $context,
        ?array $assistantTools = null,
        bool $allowUnattended = false,
        ?string $assistantKey = null,
        ?PolicyOutcome $nodeOverride = null,
        bool $escalateToApproval = false,
    ): PolicyOutcome {
        $class = $tool->sideEffect;
        $restrictions = [
            $this->groupPolicy->outcomeFor($actorId, $tool, $class),
            $this->assistantPolicy->outcomeFor($assistantTools, $tool, $class),
            $nodeOverride,
            $escalateToApproval ? PolicyOutcome::Approve : null,
        ];
        $connection = $this->connectionOutcome($tool);
        $base = $connection ?? $this->baseOutcome($tool, $actorId, $class, $context);
        $resolved = $this->mostRestrictive([$base, ...$restrictions]);
        if (null !== $connection) {
            return $this->unattendedTightened($resolved, $class, $context, $allowUnattended);
        }
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

    /**
     * The connection's own choice for this MCP tool, or null when the tool
     * does not come with one (not an MCP tool, or a descriptor built without
     * a connection).
     */
    public function connectionOutcome(ToolDescriptor $tool): ?PolicyOutcome
    {
        if (ToolSource::Mcp !== $tool->source) {
            return null;
        }
        $raw = $tool->meta[McpToolMeta::CONNECTION_OUTCOME] ?? null;

        return is_string($raw) ? PolicyOutcome::tryFrom($raw) : null;
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

    private function unattendedTightened(PolicyOutcome $resolved, SideEffect $class, PolicyContext $context, bool $allowUnattended): PolicyOutcome
    {
        if (PolicyContext::Unattended === $context && PolicyOutcome::Auto === $resolved
            && SideEffect::Read !== $class && !$allowUnattended) {
            return PolicyOutcome::Approve;
        }

        return $resolved;
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
