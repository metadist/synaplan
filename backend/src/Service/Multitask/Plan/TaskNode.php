<?php

declare(strict_types=1);

namespace App\Service\Multitask\Plan;

/**
 * A single node of a {@see TaskPlan} DAG.
 *
 * - `id`        : unique within the plan (e.g. "n1").
 * - `capability`: what to run (maps to an existing handler/service).
 * - `dependsOn` : ids of nodes whose output this node consumes.
 * - `inputs`    : typed references (literal, "$message.*", "$nX.text", "$nX.file").
 * - `params`    : capability-specific knobs (e.g. media format, duration, resolution,
 *                 or topic_id/prompt_id for a custom-topic chat node — the migration
 *                 carrier so per-topic PromptMeta.aiModel pins survive).
 */
final readonly class TaskNode
{
    /**
     * @param list<string>         $dependsOn
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string $id,
        public Capability $capability,
        public array $dependsOn = [],
        public array $inputs = [],
        public array $params = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'capability' => $this->capability->value,
            'depends_on' => $this->dependsOn,
            'inputs' => $this->inputs,
            'params' => $this->params,
        ];
    }
}
