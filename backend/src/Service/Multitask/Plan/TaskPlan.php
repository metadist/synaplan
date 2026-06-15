<?php

declare(strict_types=1);

namespace App\Service\Multitask\Plan;

/**
 * An immutable, validated task plan (a small DAG of {@see TaskNode}s).
 *
 * Build it via {@see fromArray()} (validates first, throws on invalid input) or
 * {@see singleChatPlan()} for the degenerate/fallback path — a one-node `chat`
 * plan that reproduces today's single-answer behaviour and is the safe fallback
 * whenever the planner output is unusable.
 */
final readonly class TaskPlan
{
    /**
     * @param list<TaskNode> $nodes
     */
    private function __construct(
        public int $version,
        public string $language,
        public string $replyNode,
        public array $nodes,
    ) {
    }

    /**
     * Build from a decoded JSON payload. Validates with {@see TaskPlanValidator}
     * and throws {@see InvalidTaskPlanException} when the payload is not sound.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload, ?TaskPlanValidator $validator = null): self
    {
        $validator ??= new TaskPlanValidator();
        $errors = $validator->validate($payload);
        if ([] !== $errors) {
            throw new InvalidTaskPlanException($errors);
        }

        $nodes = [];
        /** @var list<array<string, mixed>> $tasks */
        $tasks = $payload['tasks'];
        foreach ($tasks as $task) {
            /** @var Capability $capability validated above */
            $capability = Capability::from((string) $task['capability']);
            $nodes[] = new TaskNode(
                id: (string) $task['id'],
                capability: $capability,
                dependsOn: array_values(array_map('strval', $task['depends_on'] ?? [])),
                inputs: $task['inputs'] ?? [],
                params: $task['params'] ?? [],
            );
        }

        return new self(
            version: 1,
            language: is_string($payload['language'] ?? null) ? $payload['language'] : 'en',
            replyNode: (string) $payload['reply_node'],
            nodes: $nodes,
        );
    }

    /**
     * The degenerate plan: one `chat` node that is also the reply node.
     *
     * `topicId`/`promptId` are carried in params so a custom user topic
     * (BPROMPTS.BOWNERID>0) keeps its PromptMeta.aiModel pin downstream — the
     * migration carrier from the master-plan decision.
     */
    public static function singleChatPlan(string $language = 'en', ?string $topicId = null, ?string $promptId = null): self
    {
        $params = [];
        if (null !== $topicId) {
            $params['topic_id'] = $topicId;
        }
        if (null !== $promptId) {
            $params['prompt_id'] = $promptId;
        }

        return new self(
            version: 1,
            language: $language,
            replyNode: 'n1',
            nodes: [new TaskNode(id: 'n1', capability: Capability::Chat, params: $params)],
        );
    }

    public function isSingleNode(): bool
    {
        return 1 === count($this->nodes);
    }

    public function nodeById(string $id): ?TaskNode
    {
        foreach ($this->nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Nodes in a dependency-respecting (topological) order. Assumes the plan is
     * acyclic (guaranteed for instances built via fromArray/singleChatPlan).
     *
     * @return list<TaskNode>
     */
    public function topologicalOrder(): array
    {
        $byId = [];
        foreach ($this->nodes as $node) {
            $byId[$node->id] = $node;
        }

        $ordered = [];
        $state = [];
        $visit = function (string $id) use (&$visit, &$state, &$ordered, $byId): void {
            if (isset($state[$id]) || !isset($byId[$id])) {
                return;
            }
            $state[$id] = true;
            foreach ($byId[$id]->dependsOn as $dep) {
                $visit($dep);
            }
            $ordered[] = $byId[$id];
        };

        foreach ($this->nodes as $node) {
            $visit($node->id);
        }

        return $ordered;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'language' => $this->language,
            'reply_node' => $this->replyNode,
            'tasks' => array_map(static fn (TaskNode $n): array => $n->toArray(), $this->nodes),
        ];
    }
}
