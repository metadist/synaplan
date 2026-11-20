<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RunRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One run of the run kernel: an assistant run, a research run or a chat run.
 * The durable part lives here for the retention window; the live checkpoint,
 * claim and events live in Redis. The ledger is written here after every
 * write step, so it outlives the Redis copies and a Redis flush.
 */
#[ORM\Entity(repositoryClass: RunRepository::class)]
#[ORM\Table(name: 'BRUNS')]
#[ORM\UniqueConstraint(name: 'uniq_run_uuid', columns: ['BUUID'])]
#[ORM\Index(columns: ['BUSERID', 'BSTATUS', 'BCREATED'], name: 'idx_run_user_status')]
#[ORM\Index(columns: ['BPARENTID'], name: 'idx_run_parent')]
#[ORM\Index(columns: ['BAGENTID', 'BCREATED'], name: 'idx_run_agent')]
class Run
{
    public const KIND_ASSISTANT = 'assistant';
    public const KIND_RESEARCH = 'research';
    public const KIND_CHAT = 'chat';

    public const KINDS = [self::KIND_ASSISTANT, self::KIND_RESEARCH, self::KIND_CHAT];

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_WAITING = 'waiting_for_you';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_BUDGET_USED = 'budget_used';
    public const STATUS_INTERRUPTED = 'interrupted';
    public const STATUS_FAILED = 'failed';

    public const TERMINAL = [
        self::STATUS_COMPLETED,
        self::STATUS_STOPPED,
        self::STATUS_BUDGET_USED,
        self::STATUS_INTERRUPTED,
        self::STATUS_FAILED,
    ];

    public const MAX_JOB_LENGTH = 8000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUUID', type: 'string', length: 26, options: ['fixed' => true])]
    private string $uuid;

    #[ORM\Column(name: 'BKIND', type: 'string', length: 16)]
    private string $kind;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'BSTARTEDBY', type: 'bigint', nullable: true)]
    private ?int $startedBy = null;

    #[ORM\Column(name: 'BAGENTID', type: 'bigint', nullable: true)]
    private ?int $agentId = null;

    #[ORM\Column(name: 'BAGENTVERSIONID', type: 'bigint', nullable: true)]
    private ?int $agentVersionId = null;

    #[ORM\Column(name: 'BPARENTID', type: 'bigint', nullable: true)]
    private ?int $parentId = null;

    #[ORM\Column(name: 'BCHATID', type: 'bigint', nullable: true)]
    private ?int $chatId = null;

    #[ORM\Column(name: 'BORIGIN', type: 'string', length: 32)]
    private string $origin;

    #[ORM\Column(name: 'BSTATUS', type: 'string', length: 24)]
    private string $status = self::STATUS_QUEUED;

    /** Run notes of a chat run; length 16777215 pins the comparator to MEDIUMTEXT. */
    #[ORM\Column(name: 'BNOTES', type: 'text', length: 16777215, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'BWAITREASON', type: 'string', length: 16, nullable: true)]
    private ?string $waitReason = null;

    /** length 65535 pins the comparator to TEXT, the migration DDL. */
    #[ORM\Column(name: 'BJOB', type: 'text', length: 65535)]
    private string $job;

    #[ORM\Column(name: 'BOUTCOME', type: 'text', length: 65535, nullable: true)]
    private ?string $outcome = null;

    /** @var array{done: list<string>, notDone: list<string>}|null */
    #[ORM\Column(name: 'BLEDGER', type: 'json', nullable: true)]
    private ?array $ledger = null;

    #[ORM\Column(name: 'BSTEPS', type: 'integer')]
    private int $steps = 0;

    #[ORM\Column(name: 'BCOSTMICRO', type: 'bigint')]
    private int $costMicro = 0;

    #[ORM\Column(name: 'BCREATED', type: 'integer')]
    private int $created;

    #[ORM\Column(name: 'BSTARTED', type: 'integer', nullable: true)]
    private ?int $started = null;

    #[ORM\Column(name: 'BFINISHED', type: 'integer', nullable: true)]
    private ?int $finished = null;

    /** The start's idempotency key; the 24-hour window is enforced in code, so a key may come back later. */
    #[ORM\Column(name: 'BIDEMPOTENCY', type: 'string', length: 64, nullable: true)]
    private ?string $idempotency = null;

    public function __construct(string $uuid, string $kind, int $userId, string $origin, string $job, int $created)
    {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown run kind.');
        }
        if (mb_strlen($job) > self::MAX_JOB_LENGTH) {
            throw new \InvalidArgumentException('A job is at most 8,000 characters.');
        }
        $this->uuid = $uuid;
        $this->kind = $kind;
        $this->userId = $userId;
        $this->origin = $origin;
        $this->job = $job;
        $this->created = $created;
    }

    public function getId(): ?int
    {
        return null === $this->id ? null : (int) $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getUserId(): int
    {
        return (int) $this->userId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isTerminal(): bool
    {
        return \in_array($this->status, self::TERMINAL, true);
    }

    public function getStartedBy(): ?int
    {
        return null === $this->startedBy ? null : (int) $this->startedBy;
    }

    public function setStartedBy(?int $startedBy): void
    {
        $this->startedBy = $startedBy;
    }

    public function setIdempotency(?string $key): void
    {
        $this->idempotency = $key;
    }

    public function getWaitReason(): ?string
    {
        return $this->waitReason;
    }

    public function getOutcome(): ?string
    {
        return $this->outcome;
    }

    public function getSteps(): int
    {
        return $this->steps;
    }

    public function getCostMicro(): int
    {
        return (int) $this->costMicro;
    }

    public function getStarted(): ?int
    {
        return $this->started;
    }

    public function getFinished(): ?int
    {
        return $this->finished;
    }

    /**
     * Where the run stands after a unit. A terminal status stamps the finish
     * time once; $outcome is the short reason it ended.
     */
    public function progress(string $status, ?string $waitReason, int $steps, int $costMicro, ?string $outcome, int $now): void
    {
        if (!\in_array($status, [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_WAITING, ...self::TERMINAL], true)) {
            throw new \InvalidArgumentException('Unknown run status.');
        }
        $this->status = $status;
        $this->waitReason = $waitReason;
        $this->steps = max(0, $steps);
        $this->costMicro = max(0, $costMicro);
        $this->started ??= $now;
        if (null !== $outcome) {
            $this->outcome = $outcome;
        }
        if ($this->isTerminal()) {
            $this->finished ??= $now;
        }
    }

    public function getChatId(): ?int
    {
        return null === $this->chatId ? null : (int) $this->chatId;
    }

    public function setChatId(?int $chatId): void
    {
        $this->chatId = $chatId;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * @return array{done: list<string>, notDone: list<string>}|null
     */
    public function getLedger(): ?array
    {
        return $this->ledger;
    }

    public function getCreated(): int
    {
        return $this->created;
    }
}
