<?php

declare(strict_types=1);

namespace App\Service\Multitask\Skill;

use App\Service\Multitask\Plan\Capability;

/**
 * A building block's self-description — the single source of truth for what
 * the planner is told about one capability (release-4.0 plan 08 §2,
 * catalog-lite scope locked in plan 09).
 *
 * Declared by the {@see \App\Service\Multitask\Execution\TaskRunner} that
 * executes the capability, collected by the {@see SkillCatalog}, rendered into
 * the planner prompt's `[CAPABILITYLIST]`. Adding a new block therefore means
 * ONE runner file that declares its skill — no parallel edit of a hard-coded
 * descriptions array.
 *
 * `dynamicNote` is the hook for DYNAMIC blocks whose parameter space is only
 * known at plan time (the mcp_fetch node's per-user tool sub-catalog): when
 * set, the catalog invokes it with the user id and appends the returned lines
 * under the capability's summary. Return null to contribute nothing.
 */
final readonly class SkillDescriptor
{
    /**
     * @param string                         $summary        one-line, planner-facing description ([CAPABILITYLIST] entry)
     * @param (\Closure(?int): ?string)|null $dynamicNote    per-user expansion appended below the summary
     * @param string|null                    $enabledFlag    BCONFIG `MULTITASK.<flag>` gating this block; when the flag
     *                                                       resolves to false the capability is OMITTED from the planner
     *                                                       catalog (the planner never learns it exists — plan 09 §6)
     * @param bool                           $enabledDefault flag value when no BCONFIG row exists (new experimental
     *                                                       blocks ship default-off and are flipped on when validated)
     */
    public function __construct(
        public Capability $capability,
        public string $summary,
        public ?\Closure $dynamicNote = null,
        public ?string $enabledFlag = null,
        public bool $enabledDefault = false,
    ) {
    }
}
