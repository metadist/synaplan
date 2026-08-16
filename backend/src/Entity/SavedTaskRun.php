<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SavedTaskRunRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SavedTaskRunRepository::class)]
#[ORM\Table(name: 'BSAVEDTASK_RUNS')]
#[ORM\Index(columns: ['BSAVEDTASKID', 'BCREATED'], name: 'idx_saved_task_run_task_created')]
class SavedTaskRun
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BSAVEDTASKID', type: 'bigint')]
    private int $savedTaskId;

    #[ORM\Column(name: 'BSTATUS', length: 16, options: ['default' => self::STATUS_QUEUED])]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(name: 'BTRIGGER', length: 32, options: ['default' => 'manual'])]
    private string $trigger = 'manual';

    #[ORM\Column(name: 'BMESSAGEID', type: 'bigint', nullable: true)]
    private ?int $messageId = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'BPLANSNAPSHOT', type: 'json', nullable: true)]
    private ?array $planSnapshot = null;

    /** length 65535 pins the DBAL comparator to TEXT — the migration DDL — instead of LONGTEXT. */
    #[ORM\Column(name: 'BERROR', type: 'text', length: 65535, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(name: 'BSTARTED', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $started = null;

    #[ORM\Column(name: 'BFINISHED', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finished = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    public function __construct(int $savedTaskId, string $trigger)
    {
        $this->savedTaskId = $savedTaskId;
        $this->trigger = $trigger;
        $this->created = time();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSavedTaskId(): int
    {
        return $this->savedTaskId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTrigger(): string
    {
        return $this->trigger;
    }

    public function getMessageId(): ?int
    {
        return $this->messageId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPlanSnapshot(): ?array
    {
        return $this->planSnapshot;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getStarted(): ?\DateTimeImmutable
    {
        return $this->started;
    }

    public function getFinished(): ?\DateTimeImmutable
    {
        return $this->finished;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function markRunning(): self
    {
        $this->status = self::STATUS_RUNNING;
        $this->started = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }

    /**
     * @param array<string, mixed>|null $planSnapshot
     */
    public function markCompleted(?int $messageId, ?array $planSnapshot): self
    {
        $this->status = self::STATUS_COMPLETED;
        $this->messageId = $messageId;
        $this->planSnapshot = $planSnapshot;
        $this->error = null;
        $this->finished = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }

    /**
     * @param array<string, mixed>|null $planSnapshot
     */
    public function markFailed(string $error, ?int $messageId = null, ?array $planSnapshot = null): self
    {
        $this->status = self::STATUS_FAILED;
        $this->error = $error;
        $this->messageId = $messageId;
        $this->planSnapshot = $planSnapshot;
        $this->finished = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this;
    }
}
