<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RevectorizeRunRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit + live-status row for one embedding-model change run.
 *
 * Lifecycle:
 *   1. queued     — written by the admin "switch model" endpoint, picked
 *                   up by the ReVectorizeJob handler.
 *   2. running    — handler started, processed/total counters tick.
 *   3. completed  — handler finished without errors.
 *   4. failed     — handler threw; `error` carries the message.
 *   5. cancelled  — operator killed the run; reserved for future UI.
 *
 * @see Version20260430120000 for the underlying SQL schema and the
 *      rationale for keeping this in its own table rather than BCONFIG.
 */
#[ORM\Entity(repositoryClass: RevectorizeRunRepository::class)]
#[ORM\Table(name: 'BREVECTORIZE_RUNS')]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_revectorize_user')]
#[ORM\Index(columns: ['BSTATUS'], name: 'idx_revectorize_status')]
#[ORM\Index(columns: ['BSCOPE', 'BCREATED'], name: 'idx_revectorize_scope_created')]
class RevectorizeRun
{
    public const SCOPE_DOCUMENTS = 'documents';
    public const SCOPE_MEMORIES = 'memories';
    public const SCOPE_SYNAPSE = 'synapse';
    public const SCOPE_ALL = 'all';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'integer')]
    private int $userId;

    #[ORM\Column(name: 'BSCOPE', length: 32)]
    private string $scope;

    #[ORM\Column(name: 'BMODEL_FROM_ID', type: 'integer', nullable: true)]
    private ?int $modelFromId = null;

    #[ORM\Column(name: 'BMODEL_TO_ID', type: 'integer')]
    private int $modelToId;

    #[ORM\Column(name: 'BSTATUS', length: 16, options: ['default' => self::STATUS_QUEUED])]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(name: 'BCHUNKS_TOTAL', type: 'integer', nullable: true)]
    private ?int $chunksTotal = null;

    #[ORM\Column(name: 'BCHUNKS_PROCESSED', type: 'integer', options: ['default' => 0])]
    private int $chunksProcessed = 0;

    #[ORM\Column(name: 'BCHUNKS_FAILED', type: 'integer', options: ['default' => 0])]
    private int $chunksFailed = 0;

    #[ORM\Column(name: 'BTOKENS_ESTIMATED', type: 'bigint', nullable: true)]
    private ?int $tokensEstimated = null;

    #[ORM\Column(name: 'BTOKENS_PROCESSED', type: 'bigint', options: ['default' => 0])]
    private int $tokensProcessed = 0;

    // Stored as MySQL DECIMAL → exposed as string via Doctrine to avoid
    // float precision drift across PHP/MySQL boundaries.
    #[ORM\Column(name: 'BCOST_ESTIMATED_USD', type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $costEstimatedUsd = null;

    #[ORM\Column(name: 'BCOST_ACTUAL_USD', type: 'decimal', precision: 10, scale: 4, options: ['default' => '0.0000'])]
    private string $costActualUsd = '0.0000';

    #[ORM\Column(name: 'BSEVERITY', length: 16, options: ['default' => self::SEVERITY_INFO])]
    private string $severity = self::SEVERITY_INFO;

    #[ORM\Column(name: 'BSTARTED_AT', type: 'bigint', nullable: true)]
    private ?int $startedAt = null;

    #[ORM\Column(name: 'BFINISHED_AT', type: 'bigint', nullable: true)]
    private ?int $finishedAt = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint')]
    private int $updated;

    #[ORM\Column(name: 'BERROR', type: 'text', nullable: true)]
    private ?string $error = null;

    public function __construct()
    {
        $now = time();
        $this->created = $now;
        $this->updated = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    public function getModelFromId(): ?int
    {
        return $this->modelFromId;
    }

    public function setModelFromId(?int $modelFromId): self
    {
        $this->modelFromId = $modelFromId;

        return $this;
    }

    public function getModelToId(): int
    {
        return $this->modelToId;
    }

    public function setModelToId(int $modelToId): self
    {
        $this->modelToId = $modelToId;

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

    public function getChunksTotal(): ?int
    {
        return $this->chunksTotal;
    }

    public function setChunksTotal(?int $chunksTotal): self
    {
        $this->chunksTotal = $chunksTotal;

        return $this;
    }

    public function getChunksProcessed(): int
    {
        return $this->chunksProcessed;
    }

    public function setChunksProcessed(int $chunksProcessed): self
    {
        $this->chunksProcessed = $chunksProcessed;
        $this->touch();

        return $this;
    }

    public function incrementChunksProcessed(int $delta = 1): self
    {
        $this->chunksProcessed += $delta;
        $this->touch();

        return $this;
    }

    public function getChunksFailed(): int
    {
        return $this->chunksFailed;
    }

    public function setChunksFailed(int $chunksFailed): self
    {
        $this->chunksFailed = $chunksFailed;

        return $this;
    }

    public function incrementChunksFailed(int $delta = 1): self
    {
        $this->chunksFailed += $delta;
        $this->touch();

        return $this;
    }

    public function getTokensEstimated(): ?int
    {
        return $this->tokensEstimated;
    }

    public function setTokensEstimated(?int $tokensEstimated): self
    {
        $this->tokensEstimated = $tokensEstimated;

        return $this;
    }

    public function getTokensProcessed(): int
    {
        return $this->tokensProcessed;
    }

    public function setTokensProcessed(int $tokensProcessed): self
    {
        $this->tokensProcessed = $tokensProcessed;
        $this->touch();

        return $this;
    }

    public function incrementTokensProcessed(int $delta): self
    {
        $this->tokensProcessed += $delta;
        $this->touch();

        return $this;
    }

    public function getCostEstimatedUsd(): ?string
    {
        return $this->costEstimatedUsd;
    }

    public function setCostEstimatedUsd(?string $costEstimatedUsd): self
    {
        $this->costEstimatedUsd = $costEstimatedUsd;

        return $this;
    }

    public function getCostActualUsd(): string
    {
        return $this->costActualUsd;
    }

    public function setCostActualUsd(string $costActualUsd): self
    {
        $this->costActualUsd = $costActualUsd;
        $this->touch();

        return $this;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): self
    {
        $this->severity = $severity;

        return $this;
    }

    public function getStartedAt(): ?int
    {
        return $this->startedAt;
    }

    public function setStartedAt(?int $startedAt): self
    {
        $this->startedAt = $startedAt;
        $this->touch();

        return $this;
    }

    public function getFinishedAt(): ?int
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?int $finishedAt): self
    {
        $this->finishedAt = $finishedAt;
        $this->touch();

        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function setCreated(int $created): self
    {
        $this->created = $created;

        return $this;
    }

    public function getUpdated(): int
    {
        return $this->updated;
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

    private function touch(): void
    {
        $this->updated = time();
    }
}
