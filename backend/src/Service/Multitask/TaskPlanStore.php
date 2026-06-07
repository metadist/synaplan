<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\Service\Multitask\Plan\TaskPlan;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Persists a {@see TaskPlan} to BMESSAGE_TASKS (one row per node).
 *
 * v1 uses plain DBAL inserts rather than a Doctrine entity: the table is
 * observability/derived data and shadow mode only needs append-on-create. A
 * proper entity/repository can come with the Sprint 6 admin view.
 *
 * Best-effort: persistence failures are logged and swallowed by callers — they
 * must never break a user turn (shadow mode especially).
 */
final readonly class TaskPlanStore
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Insert one row per plan node. In shadow mode nodes are recorded as
     * 'pending' (they are never executed). Returns the number of rows written.
     */
    public function persist(int $messageId, TaskPlan $plan, ?int $modelId = null, string $status = 'pending'): int
    {
        return $this->persistWithStatuses($messageId, $plan, $modelId, [], $status);
    }

    /**
     * Insert one row per node using a per-node status map (nodeId => status),
     * defaulting to $default for nodes not present in the map. Used by the DAG
     * executor to record each node's final outcome. Best-effort.
     *
     * @param array<string, string> $statuses
     */
    public function persistWithStatuses(int $messageId, TaskPlan $plan, ?int $modelId, array $statuses, string $default = 'pending'): int
    {
        $written = 0;
        foreach ($plan->nodes as $node) {
            try {
                $this->connection->insert('BMESSAGE_TASKS', [
                    'BMESSAGEID' => $messageId,
                    'BNODEID' => $node->id,
                    'BCAPABILITY' => $node->capability->value,
                    'BDEPENDSON' => json_encode($node->dependsOn, \JSON_UNESCAPED_SLASHES) ?: '[]',
                    'BSTATUS' => $statuses[$node->id] ?? $default,
                    'BMODELID' => $modelId,
                ]);
                ++$written;
            } catch (\Throwable $e) {
                $this->logger->warning('TaskPlanStore: failed to persist task node', [
                    'message_id' => $messageId,
                    'node_id' => $node->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $written;
    }
}
