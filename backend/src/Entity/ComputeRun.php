<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ComputeRunRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit row for one sidecar run. Metadata only — no script or stdout.
 */
#[ORM\Entity(repositoryClass: ComputeRunRepository::class)]
#[ORM\Table(name: 'BCOMPUTERUNS')]
#[ORM\UniqueConstraint(name: 'uq_computerun_runid', columns: ['BRUNID'])]
#[ORM\Index(columns: ['BUSERID', 'BCREATED'], name: 'idx_computerun_user_created')]
#[ORM\Index(columns: ['BSTATUS'], name: 'idx_computerun_status')]
class ComputeRun
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'integer')]
    private int $userId;

    #[ORM\Column(name: 'BPROMPTID', type: 'integer', nullable: true)]
    private ?int $promptId = null;

    #[ORM\Column(name: 'BMESSAGEID', type: 'bigint', nullable: true)]
    private ?int $messageId = null;

    #[ORM\Column(name: 'BSAVEDTASKRUNID', type: 'bigint', nullable: true)]
    private ?int $savedTaskRunId = null;

    #[ORM\Column(name: 'BRUNID', length: 26)]
    private string $runId;

    #[ORM\Column(name: 'BINVOKEDVIA', length: 32)]
    private string $invokedVia;

    #[ORM\Column(name: 'BIMAGE', length: 32)]
    private string $image;

    #[ORM\Column(name: 'BPROGRAM', length: 32)]
    private string $program;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'BLIMITS', type: 'json')]
    private array $limits;

    #[ORM\Column(name: 'BSTATUS', length: 16)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(name: 'BEXITCODE', type: 'integer', nullable: true)]
    private ?int $exitCode = null;

    #[ORM\Column(name: 'BREASON', length: 32, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'BDURATIONMS', type: 'integer', nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(name: 'BBYTESIN', type: 'bigint', options: ['default' => 0])]
    private int $bytesIn = 0;

    #[ORM\Column(name: 'BBYTESOUT', type: 'bigint', options: ['default' => 0])]
    private int $bytesOut = 0;

    /** @var list<int>|null */
    #[ORM\Column(name: 'BARTEFACTIDS', type: 'json', nullable: true)]
    private ?array $artefactIds = null;

    /** @var list<string>|null */
    #[ORM\Column(name: 'BEGRESSHOSTS', type: 'json', nullable: true)]
    private ?array $egressHosts = null;

    #[ORM\Column(name: 'BWORKSPACEID', length: 26, nullable: true)]
    private ?string $workspaceId = null;

    #[ORM\Column(name: 'BCREATED', type: 'datetime_immutable')]
    private \DateTimeImmutable $created;

    #[ORM\Column(name: 'BFINISHED', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finished = null;

    /**
     * @param array<string, mixed> $limits
     */
    public function __construct(int $userId, string $runId, string $invokedVia, string $image, string $program, array $limits)
    {
        $this->userId = $userId;
        $this->runId = $runId;
        $this->invokedVia = $invokedVia;
        $this->image = $image;
        $this->program = $program;
        $this->limits = $limits;
        $this->created = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function setRunId(string $runId): void
    {
        $this->runId = $runId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function setMessageId(?int $messageId): void
    {
        $this->messageId = $messageId;
    }

    public function setPromptId(?int $promptId): void
    {
        $this->promptId = $promptId;
    }

    public function setExitCode(?int $exitCode): void
    {
        $this->exitCode = $exitCode;
    }

    public function setReason(?string $reason): void
    {
        $this->reason = $reason;
    }

    public function setDurationMs(?int $durationMs): void
    {
        $this->durationMs = $durationMs;
    }

    public function setBytesIn(int $bytesIn): void
    {
        $this->bytesIn = $bytesIn;
    }

    public function setBytesOut(int $bytesOut): void
    {
        $this->bytesOut = $bytesOut;
    }

    /**
     * @param list<int> $ids
     */
    public function setArtefactIds(array $ids): void
    {
        $this->artefactIds = $ids;
    }

    public function markFinished(string $status): void
    {
        $this->status = $status;
        $this->finished = new \DateTimeImmutable();
    }
}
