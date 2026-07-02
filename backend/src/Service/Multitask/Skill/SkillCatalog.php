<?php

declare(strict_types=1);

namespace App\Service\Multitask\Skill;

use App\Service\Multitask\Execution\TaskRunner;
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
     * @param iterable<TaskRunner> $runners
     */
    public function __construct(
        #[AutowireIterator('app.multitask.runner')]
        iterable $runners = [],
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
     */
    public function renderCapabilityList(?int $userId = null): string
    {
        $lines = [];
        foreach (Capability::cases() as $capability) {
            $descriptor = $this->byCapability[$capability->value] ?? null;
            $lines[] = '- "'.$capability->value.'": '.(null !== $descriptor ? $descriptor->summary : '');

            $note = null !== $descriptor && null !== $descriptor->dynamicNote
                ? ($descriptor->dynamicNote)($userId)
                : null;
            if (is_string($note) && '' !== trim($note)) {
                $lines[] = rtrim($note);
            }
        }

        return implode("\n", $lines);
    }
}
