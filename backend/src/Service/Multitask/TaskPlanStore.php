<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\Service\Multitask\Plan\Capability;
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

    /**
     * Update a single node's status for a still-running turn (best-effort).
     *
     * Used by the DAG progress path (#1142) so BMESSAGE_TASKS reflects live
     * per-node progress WHILE the turn runs — a client that reloads mid-stream
     * can then rebuild running/completed cards instead of seeing only the bare
     * user prompt. A miss only affects that transient reload view, never the
     * turn, so failures are logged and swallowed.
     */
    public function updateNodeStatus(int $messageId, string $nodeId, string $status): void
    {
        if ('' === $nodeId) {
            return;
        }

        try {
            $this->connection->update(
                'BMESSAGE_TASKS',
                ['BSTATUS' => $status],
                ['BMESSAGEID' => $messageId, 'BNODEID' => $nodeId],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('TaskPlanStore: failed to update node status', [
                'message_id' => $messageId,
                'node_id' => $nodeId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Load the persisted per-node cards for a message as the client-facing
     * shape used to render task cards on reload (#1142). Hidden assembler nodes
     * (compose_reply) are excluded — they are not user-visible cards. Ordered by
     * insertion (BID) so cards render in plan order.
     *
     * Best-effort: a read failure returns an empty list (the caller falls back
     * to showing only the persisted messages).
     *
     * @return list<array{nodeId: string, capability: string, kind: string, state: string}>
     */
    public function loadCards(int $messageId): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT BNODEID, BCAPABILITY, BSTATUS FROM BMESSAGE_TASKS WHERE BMESSAGEID = ? ORDER BY BID ASC',
                [$messageId],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('TaskPlanStore: failed to load task cards', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $cards = [];
        foreach ($rows as $row) {
            $capability = (string) $row['BCAPABILITY'];
            $kind = Capability::tryFromString($capability)?->uiKind() ?? 'text';
            if ('hidden' === $kind) {
                continue;
            }
            $cards[] = [
                'nodeId' => (string) $row['BNODEID'],
                'capability' => $capability,
                'kind' => $kind,
                'state' => (string) $row['BSTATUS'],
            ];
        }

        return $cards;
    }

    /**
     * Record the terminal outcome of an async media node after the turn ended.
     *
     * The DAG persists such nodes as 'running' (the background job outlives the
     * request); when the job reaches its terminal state the worker heals the row
     * via the job key stamped at persist time (#1239). Best-effort like the rest
     * of this store — a miss only affects observability, never the user turn.
     */
    public function updateStatusByJobKey(string $jobKey, string $status): void
    {
        if ('' === $jobKey) {
            return;
        }

        try {
            $this->connection->update('BMESSAGE_TASKS', ['BSTATUS' => $status], ['BJOBKEY' => $jobKey]);
        } catch (\Throwable $e) {
            $this->logger->warning('TaskPlanStore: failed to update node status by job key', [
                'job_key' => $jobKey,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
