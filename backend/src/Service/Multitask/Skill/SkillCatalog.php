<?php

declare(strict_types=1);

namespace App\Service\Multitask\Skill;

use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects every runner's {@see SkillDescriptor} and renders the planner
 * prompt's `[CAPABILITYLIST]` from them (release-4.0 catalog-lite).
 *
 * Rendering is deterministic: capabilities appear in {@see Capability::values()}
 * order (the enum declaration order), independent of runner registration
 * order — this is what keeps the rendered planner prompt stable and lets the
 * PlannerPromptCharacterizationTest prove byte-equivalence across refactors.
 *
 * A capability without a descriptor renders with an empty description (the
 * exact behaviour of the previous hard-coded array's `?? ''` fallback) so a
 * missing declaration degrades visibly in the prompt instead of crashing the
 * planner.
 */
final class SkillCatalog
{
    /** @var array<string, SkillDescriptor> capability value => descriptor */
    private array $byCapability = [];

    /**
     * @param iterable<TaskRunner>        $runners
     * @param MultitaskRoutingConfig|null $routingConfig resolves per-block enable
     *                                                   flags; null (unit tests)
     *                                                   falls back to each
     *                                                   descriptor's enabledDefault
     */
    public function __construct(
        #[AutowireIterator('app.multitask.runner')]
        iterable $runners = [],
        private readonly ?MultitaskRoutingConfig $routingConfig = null,
    ) {
        foreach ($runners as $runner) {
            foreach ($runner->describe() as $descriptor) {
                $this->byCapability[$descriptor->capability->value] = $descriptor;
            }
        }
    }

    public function descriptorFor(Capability $capability): ?SkillDescriptor
    {
        return $this->byCapability[$capability->value] ?? null;
    }

    /**
     * Render the `[CAPABILITYLIST]` block: one `- "capability": summary` line
     * per capability, plus any per-user dynamic note a descriptor contributes.
     *
     * @param array<string, mixed> $context per-turn render context for dynamic
     *                                      blocks: `topic` (resolved routing
     *                                      topic) and `topic_metadata` (its
     *                                      BPROMPTMETA key/value map)
     */
    public function renderCapabilityList(?int $userId = null, array $context = []): string
    {
        $lines = [];
        foreach (Capability::cases() as $capability) {
            $descriptor = $this->byCapability[$capability->value] ?? null;

            // Flag-gated blocks (url_fetch, mcp_fetch, email_search …) are
            // omitted entirely when disabled: the planner never learns they
            // exist, so it cannot emit them (plan 09 §6 — plan-time gate; the
            // runner re-checks the flag at run time as defense in depth).
            if (null !== $descriptor && !$this->isEnabled($descriptor, $userId)) {
                continue;
            }

            $note = null !== $descriptor && null !== $descriptor->dynamicNote
                ? ($descriptor->dynamicNote)($userId, $context)
                : null;
            $hasNote = is_string($note) && '' !== trim($note);

            // A dynamic block with nothing to offer this user/turn (no
            // connected servers, topic not entitled) stays invisible.
            if (null !== $descriptor && $descriptor->requiresDynamicNote && !$hasNote) {
                continue;
            }

            $lines[] = '- "'.$capability->value.'": '.(null !== $descriptor ? $descriptor->summary : '');
            if ($hasNote) {
                $lines[] = rtrim($note);
            }
        }

        return implode("\n", $lines);
    }

    private function isEnabled(SkillDescriptor $descriptor, ?int $userId): bool
    {
        if (null === $descriptor->enabledFlag) {
            return true;
        }
        if (null === $this->routingConfig) {
            return $descriptor->enabledDefault;
        }

        return $this->routingConfig->isFeatureEnabled($descriptor->enabledFlag, $userId, $descriptor->enabledDefault);
    }
}
