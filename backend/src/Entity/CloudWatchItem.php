<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CloudWatchItemRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One version of one cloud file handled by one watch. The same version twice
 * is the same row.
 */
#[ORM\Entity(repositoryClass: CloudWatchItemRepository::class)]
#[ORM\Table(name: 'BCLOUDWATCHITEMS')]
#[ORM\UniqueConstraint(name: 'uniq_cloudwatch_item', columns: ['BWATCHID', 'BSOURCEID', 'BVERSION'])]
#[ORM\Index(columns: ['BWATCHID', 'BSTATE', 'BUPDATED'], name: 'idx_cloudwatch_item_state')]
class CloudWatchItem
{
    public const STATE_WAITING = 'waiting';
    public const STATE_RUNNING = 'running';
    public const STATE_DONE = 'done';
    public const STATE_SKIPPED = 'skipped';
    public const STATE_FAILED = 'failed';

    public const REASON_TOO_LARGE = 'too_large';
    public const REASON_TYPE_NOT_READ = 'type_not_read';
    public const REASON_NO_TEXT = 'no_text';
    public const REASON_GONE = 'gone';
    public const REASON_LIMIT_WAITING = 'limit_waiting';
    public const REASON_FAILED_RUN = 'failed_run';
    public const REASON_OWN_WRITE = 'own_write';
    public const REASON_LANGUAGE_SUFFIX = 'language_suffix';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BWATCHID', type: 'bigint')]
    private int $watchId;

    #[ORM\Column(name: 'BSOURCEID', length: 255)]
    private string $sourceId;

    #[ORM\Column(name: 'BVERSION', length: 255)]
    private string $version;

    #[ORM\Column(name: 'BSTATE', length: 16)]
    private string $state;

    #[ORM\Column(name: 'BREASON', length: 32, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'BFILEID', type: 'bigint', nullable: true)]
    private ?int $fileId = null;

    #[ORM\Column(name: 'BRUNID', type: 'bigint', nullable: true)]
    private ?int $runId = null;

    #[ORM\Column(name: 'BDELIVERYID', type: 'bigint', nullable: true)]
    private ?int $deliveryId = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint')]
    private int $updated;

    public function __construct(int $watchId, string $sourceId, string $version, string $state)
    {
        $this->watchId = $watchId;
        $this->sourceId = $sourceId;
        $this->version = $version;
        $this->state = $state;
        $this->created = time();
        $this->updated = $this->created;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWatchId(): int
    {
        return $this->watchId;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function mark(string $state, ?string $reason): self
    {
        $this->state = $state;
        $this->reason = $reason;
        $this->updated = time();

        return $this;
    }

    public function getFileId(): ?int
    {
        return $this->fileId;
    }

    public function setFileId(?int $fileId): self
    {
        $this->fileId = $fileId;

        return $this;
    }

    public function setRunId(?int $runId): self
    {
        $this->runId = $runId;

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
}
