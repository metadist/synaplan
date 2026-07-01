<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\Service\Multitask\Plan\TaskPlan;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Persists a {@see TaskPlan} to BMESSAGE_TASKS (one row per node).
 *
 * v1 uses plain DBAL rather than a Doctrine entity: the table is
 * observability/derived data and shadow mode only needs append-on-create. A
 * proper entity/repository can come with the Sprint 6 admin view.
 *
 * REPLACE semantics: a message has exactly one row per plan node (enforced by
 * the UNIQUE (BMESSAGEID, BNODEID) constraint). Persisting again for the same
 * message — shadow run followed by executed run, or an /again re-turn —
 * atomically replaces the previous rows instead of stacking duplicates.
 *
 * Best-effort: persistence failures are logged and swallowed — they must
 * never break a user turn (shadow mode especially).
 */
final readonly class TaskPlanStore
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Replace the plan rows for a message, one row per plan node. In shadow
     * mode nodes are recorded as 'pending' (they are never executed). Returns
     * the number of rows written (0 when persistence failed).
     */
    public function persist(int $messageId, TaskPlan $plan, ?int $modelId = null, string $status = 'pending'): int
    {
        return $this->persistWithStatuses($messageId, $plan, $modelId, [], $status);
    }

    /**
     * Replace the plan rows for a message using a per-node status map
     * (nodeId => status), defaulting to $default for nodes not present in the
     * map. Used by the DAG executor to record each node's final outcome.
     * Atomic (delete + inserts in one transaction) and best-effort.
     *
     * @param array<string, string> $statuses
     * @param array<string, string> $jobKeys  nodeId => MediaJob job_key (async media)
     */
    public function persistWithStatuses(
        int $messageId,
        TaskPlan $plan,
        ?int $modelId,
        array $statuses,
        string $default = 'pending',
        array $jobKeys = [],
    ): int {
        try {
            return (int) $this->connection->transactional(function (Connection $connection) use ($messageId, $plan, $modelId, $statuses, $default, $jobKeys): int {
                $connection->delete('BMESSAGE_TASKS', ['BMESSAGEID' => $messageId]);

                $written = 0;
                foreach ($plan->nodes as $node) {
                    $row = [
                        'BMESSAGEID' => $messageId,
                        'BNODEID' => $node->id,
                        'BCAPABILITY' => $node->capability->value,
                        'BDEPENDSON' => json_encode($node->dependsOn, \JSON_UNESCAPED_SLASHES) ?: '[]',
                        'BSTATUS' => $statuses[$node->id] ?? $default,
                        'BMODELID' => $modelId,
                    ];
                    if (isset($jobKeys[$node->id]) && '' !== $jobKeys[$node->id]) {
                        $row['BJOBKEY'] = $jobKeys[$node->id];
                    }
                    $connection->insert('BMESSAGE_TASKS', $row);
                    ++$written;
                }

                return $written;
            });
        } catch (\Throwable $e) {
            $this->logger->warning('TaskPlanStore: failed to persist task plan', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
