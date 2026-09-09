<?php

declare(strict_types=1);

namespace App\Service\SavedTask\Graph;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\Multitask\TaskPlanExecutor;

/**
 * Turns the plan a chat turn actually executed into a Saved Task graph.
 *
 * "Schedule this" used to store only the instruction text, so every rerun
 * asked the planner again — and a `url_fetch → chat → email_me` turn that
 * worked in the chat could come back as a lone chat answer. The executed
 * node definitions are persisted on the OUT message
 * ({@see TaskPlanExecutor::PLAN_DEFINITION_META}); this class copies them
 * into the `graph` column so {@see SavedTaskPlanFactory} replays exactly
 * those steps.
 */
final readonly class SavedTaskGraphCapture
{
    public function __construct(
        private MessageRepository $messages,
    ) {
    }

    /**
     * Graph v1 for the plan executed on the given OUT message, or null when
     * the message is not the owner's, carries no DAG, or ran a single step
     * (a one-node plan is what the planner produces anyway — nothing to pin).
     *
     * @return array<string, mixed>|null
     */
    public function fromMessage(int $messageId, int $ownerId, string $triggerType): ?array
    {
        $message = $this->messages->find($messageId);
        if (!$message instanceof Message || $message->getUserId() !== $ownerId) {
            return null;
        }

        $raw = $message->getMeta(TaskPlanExecutor::PLAN_DEFINITION_META);
        if (null === $raw || '' === $raw) {
            return null;
        }

        $definition = json_decode($raw, true);
        if (!is_array($definition)) {
            return null;
        }

        return $this->fromDefinition($definition, $triggerType);
    }

    /**
     * @param array<string, mixed> $definition {@see \App\Service\Multitask\Plan\TaskPlan::toArray()}
     *
     * @return array<string, mixed>|null
     */
    public function fromDefinition(array $definition, string $triggerType): ?array
    {
        $tasks = $definition['tasks'] ?? null;
        if (!is_array($tasks) || !array_is_list($tasks)) {
            return null;
        }

        $nodes = [];
        foreach ($tasks as $task) {
            if (!is_array($task) || !is_string($task['id'] ?? null) || !is_string($task['capability'] ?? null)) {
                return null;
            }
            $dependsOn = is_array($task['depends_on'] ?? null) ? $task['depends_on'] : [];
            $nodes[] = [
                'id' => $task['id'],
                'capability' => $task['capability'],
                'depends_on' => array_values(array_filter($dependsOn, 'is_string')),
                'inputs' => is_array($task['inputs'] ?? null) ? $task['inputs'] : [],
                'params' => is_array($task['params'] ?? null) ? $task['params'] : [],
            ];
        }

        // Fewer than two nodes means the turn was a plain answer; the planner
        // reproduces that on its own and a pinned graph would only add noise.
        if (count($nodes) < 2) {
            return null;
        }

        $graph = [
            'version' => SavedTaskGraphValidator::VERSION,
            'trigger' => ['type' => $triggerType],
            'nodes' => $nodes,
        ];

        // Keep the answer surface the user saw (a chat step, not the trailing
        // email_me confirmation) so the rerun's chat reply reads the same.
        $replyNode = $definition['reply_node'] ?? null;
        if (is_string($replyNode) && in_array($replyNode, array_column($nodes, 'id'), true)) {
            $graph['reply_node'] = $replyNode;
        }

        return $graph;
    }
}
