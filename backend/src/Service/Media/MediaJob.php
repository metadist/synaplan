<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Durable-in-Redis record of one asynchronous media-generation job (video, and
 * any other long-running render).
 *
 * Why Redis (not a DB table)
 * --------------------------
 * Progress and heartbeat are written many times per render and the platform is
 * a multi-node Galera cluster — pushing that write traffic into MariaDB would
 * add lock contention for data that is inherently ephemeral (the produced file
 * is the only durable artefact, and it is referenced from BMESSAGES like any
 * other asset). Redis is already the canonical cross-node state store here
 * (cache, locks, rate-limiter, Messenger transport), so jobs live there too.
 *
 * Why a job record exists at all
 * ------------------------------
 * Generation used to run *inline* in the request that started it, so a
 * multi-minute video held an HTTP/SSE connection open the whole time → PHP
 * timeouts, proxy cut-offs, and silent loss leaving the card stuck at 95%.
 * A job is created up-front (`queued`), advanced in short non-blocking steps by
 * a background worker (submit → poll → finalize), and is ALWAYS driven to a
 * terminal state — `completed`, `failed`, `cancelled` or `timed_out` — with a
 * heartbeat the reaper watches so nothing runs forever or fails silently.
 *
 * This is a plain mutable value object; persistence/indexing lives in
 * {@see MediaJobStore} and the state machine in {@see MediaJobService}.
 */
final class MediaJob
{
    public const TYPE_VIDEO = 'video';
    public const TYPE_IMAGE = 'image';
    public const TYPE_AUDIO = 'audio';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SUBMITTING = 'submitting';
    public const STATUS_RUNNING = 'running';
    public const STATUS_FINALIZING = 'finalizing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_TIMED_OUT = 'timed_out';

    /**
     * Non-terminal statuses the worker keeps advancing. Anything else is
     * terminal and must never be re-dispatched.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_SUBMITTING,
        self::STATUS_RUNNING,
        self::STATUS_FINALIZING,
    ];

    private string $jobKey;
    private int $userId = 0;
    private ?int $chatId = null;
    private ?int $messageId = null;
    private ?string $nodeId = null;
    private ?string $trackId = null;
    private string $type = self::TYPE_VIDEO;
    private string $provider = 'unknown';
    private ?int $modelId = null;
    private ?string $model = null;
    private ?string $prompt = null;
    private ?string $inputRef = null;
    /** @var array<string, mixed> */
    private array $options = [];
    private string $status = self::STATUS_QUEUED;
    private ?string $providerRef = null;
    private ?string $providerStatus = null;
    private ?int $percent = null;
    /** @var array<string, mixed>|null */
    private ?array $result = null;
    private ?string $error = null;
    private int $attempts = 0;
    private int $created;
    private int $updated;
    private ?int $startedAt = null;
    private ?int $finishedAt = null;
    private ?int $deadlineAt = null;

    public function __construct(?string $jobKey = null)
    {
        $now = time();
        $this->created = $now;
        $this->updated = $now;
        $this->jobKey = $jobKey ?? bin2hex(random_bytes(16));
    }

    public function getJobKey(): string
    {
        return $this->jobKey;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getChatId(): ?int
    {
        return $this->chatId;
    }

    public function setChatId(?int $chatId): self
    {
        $this->chatId = $chatId;

        return $this;
    }

    public function getMessageId(): ?int
    {
        return $this->messageId;
    }

    public function setMessageId(?int $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function getNodeId(): ?string
    {
        return $this->nodeId;
    }

    public function setNodeId(?string $nodeId): self
    {
        $this->nodeId = $nodeId;

        return $this;
    }

    public function getTrackId(): ?string
    {
        return $this->trackId;
    }

    public function setTrackId(?string $trackId): self
    {
        $this->trackId = $trackId;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getModelId(): ?int
    {
        return $this->modelId;
    }

    public function setModelId(?int $modelId): self
    {
        $this->modelId = $modelId;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getPrompt(): ?string
    {
        return $this->prompt;
    }

    public function setPrompt(?string $prompt): self
    {
        $this->prompt = $prompt;

        return $this;
    }

    public function getInputRef(): ?string
    {
        return $this->inputRef;
    }

    public function setInputRef(?string $inputRef): self
    {
        $this->inputRef = $inputRef;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function setOptions(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function isTerminal(): bool
    {
        return !in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function getProviderRef(): ?string
    {
        return $this->providerRef;
    }

    public function setProviderRef(?string $providerRef): self
    {
        $this->providerRef = $providerRef;
        $this->touch();

        return $this;
    }

    public function getProviderStatus(): ?string
    {
        return $this->providerStatus;
    }

    public function setProviderStatus(?string $providerStatus): self
    {
        $this->providerStatus = $providerStatus;

        return $this;
    }

    public function getPercent(): ?int
    {
        return $this->percent;
    }

    public function setPercent(?int $percent): self
    {
        if (null !== $percent) {
            $percent = max(0, min(100, $percent));
        }
        $this->percent = $percent;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResult(): ?array
    {
        return $this->result;
    }

    /**
     * @param array<string, mixed>|null $result
     */
    public function setResult(?array $result): self
    {
        $this->result = $result;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): self
    {
        $this->error = $error;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function incrementAttempts(): self
    {
        ++$this->attempts;
        $this->touch();

        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function getStartedAt(): ?int
    {
        return $this->startedAt;
    }

    public function setStartedAt(?int $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?int
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?int $finishedAt): self
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    public function getDeadlineAt(): ?int
    {
        return $this->deadlineAt;
    }

    public function setDeadlineAt(?int $deadlineAt): self
    {
        $this->deadlineAt = $deadlineAt;

        return $this;
    }

    public function getElapsedSeconds(): int
    {
        $end = $this->finishedAt ?? time();

        return max(0, $end - ($this->startedAt ?? $this->created));
    }

    public function isPastDeadline(?int $now = null): bool
    {
        if (null === $this->deadlineAt) {
            return false;
        }

        return ($now ?? time()) >= $this->deadlineAt;
    }

    /**
     * Bump the heartbeat without changing any other field. The worker calls
     * this on every poll so the reaper can tell a slow-but-alive job from a
     * dead one.
     */
    public function heartbeat(): self
    {
        $this->touch();

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'jobKey' => $this->jobKey,
            'userId' => $this->userId,
            'chatId' => $this->chatId,
            'messageId' => $this->messageId,
            'nodeId' => $this->nodeId,
            'trackId' => $this->trackId,
            'type' => $this->type,
            'provider' => $this->provider,
            'modelId' => $this->modelId,
            'model' => $this->model,
            'prompt' => $this->prompt,
            'inputRef' => $this->inputRef,
            'options' => $this->options,
            'status' => $this->status,
            'providerRef' => $this->providerRef,
            'providerStatus' => $this->providerStatus,
            'percent' => $this->percent,
            'result' => $this->result,
            'error' => $this->error,
            'attempts' => $this->attempts,
            'created' => $this->created,
            'updated' => $this->updated,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
            'deadlineAt' => $this->deadlineAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $job = new self(is_string($data['jobKey'] ?? null) ? $data['jobKey'] : null);
        $job->userId = (int) ($data['userId'] ?? 0);
        $job->chatId = isset($data['chatId']) ? (int) $data['chatId'] : null;
        $job->messageId = isset($data['messageId']) ? (int) $data['messageId'] : null;
        $job->nodeId = isset($data['nodeId']) && is_string($data['nodeId']) ? $data['nodeId'] : null;
        $job->trackId = isset($data['trackId']) && is_string($data['trackId']) ? $data['trackId'] : null;
        $job->type = is_string($data['type'] ?? null) ? $data['type'] : self::TYPE_VIDEO;
        $job->provider = is_string($data['provider'] ?? null) ? $data['provider'] : 'unknown';
        $job->modelId = isset($data['modelId']) ? (int) $data['modelId'] : null;
        $job->model = isset($data['model']) && is_string($data['model']) ? $data['model'] : null;
        $job->prompt = isset($data['prompt']) && is_string($data['prompt']) ? $data['prompt'] : null;
        $job->inputRef = isset($data['inputRef']) && is_string($data['inputRef']) ? $data['inputRef'] : null;
        $job->options = is_array($data['options'] ?? null) ? $data['options'] : [];
        $job->status = is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_QUEUED;
        $job->providerRef = isset($data['providerRef']) && is_string($data['providerRef']) ? $data['providerRef'] : null;
        $job->providerStatus = isset($data['providerStatus']) && is_string($data['providerStatus']) ? $data['providerStatus'] : null;
        $job->percent = isset($data['percent']) ? (int) $data['percent'] : null;
        $job->result = is_array($data['result'] ?? null) ? $data['result'] : null;
        $job->error = isset($data['error']) && is_string($data['error']) ? $data['error'] : null;
        $job->attempts = (int) ($data['attempts'] ?? 0);
        $job->created = (int) ($data['created'] ?? time());
        $job->updated = (int) ($data['updated'] ?? time());
        $job->startedAt = isset($data['startedAt']) ? (int) $data['startedAt'] : null;
        $job->finishedAt = isset($data['finishedAt']) ? (int) $data['finishedAt'] : null;
        $job->deadlineAt = isset($data['deadlineAt']) ? (int) $data['deadlineAt'] : null;

        return $job;
    }

    private function touch(): void
    {
        $this->updated = time();
    }
}
