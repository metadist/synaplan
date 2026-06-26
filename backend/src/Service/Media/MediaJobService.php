<?php

declare(strict_types=1);

namespace App\Service\Media;

use Psr\Log\LoggerInterface;

/**
 * Owns the lifecycle of {@see MediaJob} records: creation, state transitions,
 * heartbeats and the client-facing status projection.
 *
 * This is the single place that knows the rules of the job state machine, so
 * the worker, the reaper and the HTTP layer all transition jobs the same way
 * and always through a terminal state (the whole point of the job record).
 * Persistence/indexing is delegated to {@see MediaJobStore} (Redis).
 */
final class MediaJobService
{
    /**
     * Hard per-type lifetime ceilings (seconds). Past this the reaper marks the
     * job timed_out regardless of provider state — the "no job runs forever"
     * rail. Generous enough for legitimate 4K renders, finite by design.
     */
    private const DEADLINE_SECONDS = [
        MediaJob::TYPE_VIDEO => 1200, // 20 min
        MediaJob::TYPE_IMAGE => 240,  // 4 min
        MediaJob::TYPE_AUDIO => 600,  // 10 min
    ];

    private const DEFAULT_DEADLINE_SECONDS = 900;

    public function __construct(
        private readonly MediaJobStore $store,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Stage a new job in `queued`. The caller is expected to dispatch the
     * advancer (see MediaJobDispatcher) after this returns.
     *
     * @param array<string, mixed> $params {
     *                                     userId:int, type:string, provider:string,
     *                                     prompt?:?string, modelId?:?int, model?:?string,
     *                                     chatId?:?int, messageId?:?int, nodeId?:?string, trackId?:?string,
     *                                     inputRef?:?string, options?:array<string,mixed>
     *                                     }
     */
    public function create(array $params): MediaJob
    {
        $type = is_string($params['type'] ?? null) ? $params['type'] : MediaJob::TYPE_VIDEO;

        $job = new MediaJob();
        $job->setUserId((int) ($params['userId'] ?? 0))
            ->setType($type)
            ->setProvider(is_string($params['provider'] ?? null) ? $params['provider'] : 'unknown')
            ->setPrompt(isset($params['prompt']) && is_string($params['prompt']) ? $params['prompt'] : null)
            ->setModelId(isset($params['modelId']) ? (int) $params['modelId'] : null)
            ->setModel(isset($params['model']) && is_string($params['model']) ? $params['model'] : null)
            ->setChatId(isset($params['chatId']) ? (int) $params['chatId'] : null)
            ->setMessageId(isset($params['messageId']) ? (int) $params['messageId'] : null)
            ->setNodeId(isset($params['nodeId']) && is_string($params['nodeId']) ? $params['nodeId'] : null)
            ->setTrackId(isset($params['trackId']) && is_string($params['trackId']) ? $params['trackId'] : null)
            ->setInputRef(isset($params['inputRef']) && is_string($params['inputRef']) ? $params['inputRef'] : null)
            ->setDeadlineAt(time() + $this->deadlineSecondsFor($type));

        if (isset($params['options']) && is_array($params['options'])) {
            /** @var array<string, mixed> $options */
            $options = $params['options'];
            $job->setOptions($options);
        }

        $this->store->save($job);

        $this->logger->info('MediaJob created', [
            'job_key' => $job->getJobKey(),
            'type' => $job->getType(),
            'provider' => $job->getProvider(),
            'user_id' => $job->getUserId(),
            'message_id' => $job->getMessageId(),
            'node_id' => $job->getNodeId(),
            'deadline_at' => $job->getDeadlineAt(),
        ]);

        return $job;
    }

    public function findByKey(string $jobKey): ?MediaJob
    {
        return $this->store->find($jobKey);
    }

    /**
     * Look up a job by key, enforcing ownership. Returns null for both
     * "not found" and "not yours" so callers cannot probe other users' jobs.
     */
    public function findForUser(string $jobKey, int $userId): ?MediaJob
    {
        $job = $this->store->find($jobKey);
        if (null === $job || $job->getUserId() !== $userId) {
            return null;
        }

        return $job;
    }

    public function save(MediaJob $job): void
    {
        $this->store->save($job);
    }

    public function markSubmitting(MediaJob $job): void
    {
        if (null === $job->getStartedAt()) {
            $job->setStartedAt(time());
        }
        $job->setStatus(MediaJob::STATUS_SUBMITTING);
        $this->store->save($job);
    }

    public function markRunning(MediaJob $job, ?string $providerRef = null): void
    {
        if (null !== $providerRef) {
            $job->setProviderRef($providerRef);
        }
        if (null === $job->getStartedAt()) {
            $job->setStartedAt(time());
        }
        $job->setStatus(MediaJob::STATUS_RUNNING);
        $this->store->save($job);
    }

    public function updateProgress(MediaJob $job, ?int $percent, ?string $providerStatus): void
    {
        if (null !== $percent) {
            $job->setPercent($percent);
        }
        if (null !== $providerStatus) {
            $job->setProviderStatus($providerStatus);
        }
        $job->heartbeat();
        $this->store->save($job);
    }

    public function markFinalizing(MediaJob $job): void
    {
        $job->setStatus(MediaJob::STATUS_FINALIZING);
        $this->store->save($job);
    }

    /**
     * @param array<string, mixed> $result completed file descriptor
     */
    public function markCompleted(MediaJob $job, array $result): void
    {
        $job->setResult($result)
            ->setPercent(100)
            ->setError(null)
            ->setFinishedAt(time())
            ->setStatus(MediaJob::STATUS_COMPLETED);
        $this->store->save($job);

        $this->logger->info('MediaJob completed', [
            'job_key' => $job->getJobKey(),
            'elapsed_seconds' => $job->getElapsedSeconds(),
        ]);
    }

    public function markFailed(MediaJob $job, string $error): void
    {
        $job->setError($this->truncateError($error))
            ->setFinishedAt(time())
            ->setStatus(MediaJob::STATUS_FAILED);
        $this->store->save($job);

        $this->logger->warning('MediaJob failed', [
            'job_key' => $job->getJobKey(),
            'error' => $job->getError(),
            'elapsed_seconds' => $job->getElapsedSeconds(),
        ]);
    }

    public function markCancelled(MediaJob $job): void
    {
        $job->setFinishedAt(time())
            ->setStatus(MediaJob::STATUS_CANCELLED);
        $this->store->save($job);
    }

    public function markTimedOut(MediaJob $job, string $reason): void
    {
        $job->setError($this->truncateError($reason))
            ->setFinishedAt(time())
            ->setStatus(MediaJob::STATUS_TIMED_OUT);
        $this->store->save($job);

        $this->logger->warning('MediaJob timed out', [
            'job_key' => $job->getJobKey(),
            'reason' => $reason,
            'elapsed_seconds' => $job->getElapsedSeconds(),
        ]);
    }

    public function heartbeat(MediaJob $job): void
    {
        $job->heartbeat();
        $this->store->save($job);
    }

    /**
     * @return list<MediaJob>
     */
    public function findStale(int $heartbeatCutoff, int $limit = 100): array
    {
        return $this->store->findStale($heartbeatCutoff, $limit);
    }

    /**
     * @return list<MediaJob>
     */
    public function findByMessage(int $messageId): array
    {
        return $this->store->findByMessage($messageId);
    }

    /**
     * Map an internal job to the card-friendly state vocabulary the frontend
     * already understands (running/done/failed/cancelled), plus the live
     * progress fields and the produced file when complete.
     *
     * @return array{
     *     job_id:string, status:string, state:string, type:string,
     *     percent:?int, provider_status:?string, elapsed_seconds:int,
     *     error:?string, file:?array<string,mixed>, finished:bool,
     *     created_at:int, updated_at:int, deadline_at:?int,
     *     max_wait_seconds:int, remaining_seconds:?int
     * }
     */
    public function toStatusArray(MediaJob $job): array
    {
        $result = $job->getResult();
        $file = is_array($result['file'] ?? null) ? $result['file'] : null;
        $deadlineAt = $job->getDeadlineAt();
        $now = time();
        $remainingSeconds = null !== $deadlineAt ? max(0, $deadlineAt - $now) : null;

        return [
            'job_id' => $job->getJobKey(),
            'status' => $job->getStatus(),
            'state' => $this->clientState($job->getStatus()),
            'type' => $job->getType(),
            'percent' => $job->getPercent(),
            'provider_status' => $job->getProviderStatus(),
            'elapsed_seconds' => $job->getElapsedSeconds(),
            'error' => $job->getError(),
            'file' => $file,
            'finished' => $job->isTerminal(),
            'created_at' => $job->getCreated(),
            'updated_at' => $job->getUpdated(),
            'deadline_at' => $deadlineAt,
            'max_wait_seconds' => $this->deadlineSecondsFor($job->getType()),
            'remaining_seconds' => $remainingSeconds,
        ];
    }

    public function deadlineSecondsFor(string $type): int
    {
        return self::DEADLINE_SECONDS[$type] ?? self::DEFAULT_DEADLINE_SECONDS;
    }

    private function clientState(string $status): string
    {
        return match ($status) {
            MediaJob::STATUS_COMPLETED => 'done',
            MediaJob::STATUS_FAILED, MediaJob::STATUS_TIMED_OUT => 'failed',
            MediaJob::STATUS_CANCELLED => 'cancelled',
            default => 'running',
        };
    }

    private function truncateError(string $error): string
    {
        $error = trim($error);
        if (mb_strlen($error) <= 2000) {
            return $error;
        }

        return mb_substr($error, 0, 1997).'...';
    }
}
