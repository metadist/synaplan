<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DesktopJobRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One out-of-band desktop job.
 *
 * The web app (or, later, a Saved Task) enqueues a `skill.run` job for one of a
 * user's paired computers. The device leases it over MCP check-in, runs the
 * named skill locally, and reports a result — which may reference an uploaded
 * file the server posts back into the originating chat.
 *
 * The `type` and `status` enums are closed and frozen at `protocol: 1`
 * (Sprint A3 / DS18). No `shell.exec`, no server-supplied command: the input is
 * only ever `{skill, prompt, fileIds}` and a device MUST ignore any other key.
 */
#[ORM\Entity(repositoryClass: DesktopJobRepository::class)]
#[ORM\Table(name: 'BDESKTOPJOBS')]
#[ORM\Index(columns: ['BOWNERID'], name: 'idx_desktop_job_owner')]
#[ORM\Index(columns: ['BDEVICEID'], name: 'idx_desktop_job_device')]
#[ORM\Index(columns: ['BSTATUS', 'BDEVICEID'], name: 'idx_desktop_job_lease')]
class DesktopJob
{
    public const TYPE_SKILL_RUN = 'skill.run';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_LEASED = 'leased';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const TERMINAL_STATUSES = [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BDEVICEID', type: 'bigint', nullable: true)]
    private ?int $deviceId = null;

    #[ORM\Column(name: 'BTYPE', length: 32, options: ['default' => self::TYPE_SKILL_RUN])]
    private string $type = self::TYPE_SKILL_RUN;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'BINPUT', type: 'json', nullable: true)]
    private ?array $input = null;

    #[ORM\Column(name: 'BSTATUS', length: 16, options: ['default' => self::STATUS_QUEUED])]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(name: 'BLEASETOKEN', length: 64, nullable: true)]
    private ?string $leaseToken = null;

    #[ORM\Column(name: 'BLEASEEXPIRES', type: 'bigint', options: ['default' => 0])]
    private int $leaseExpires = 0;

    #[ORM\Column(name: 'BATTEMPT', type: 'integer', options: ['default' => 0])]
    private int $attempt = 0;

    #[ORM\Column(name: 'BMAXATTEMPTS', type: 'integer', options: ['default' => 3])]
    private int $maxAttempts = 3;

    #[ORM\Column(name: 'BIDEMPOTENCY', length: 128, nullable: true)]
    private ?string $idempotency = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'BRESULT', type: 'json', nullable: true)]
    private ?array $result = null;

    #[ORM\Column(name: 'BERRORCODE', length: 32, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(name: 'BCHATID', type: 'bigint', nullable: true)]
    private ?int $chatId = null;

    #[ORM\Column(name: 'BMESSAGEID', type: 'bigint', nullable: true)]
    private ?int $messageId = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint', options: ['default' => 0])]
    private int $updated = 0;

    public function __construct()
    {
        $this->created = time();
        $this->updated = time();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function setOwnerId(int $ownerId): self
    {
        $this->ownerId = $ownerId;

        return $this;
    }

    public function getDeviceId(): ?int
    {
        return $this->deviceId;
    }

    public function setDeviceId(?int $deviceId): self
    {
        $this->deviceId = $deviceId;

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

    /**
     * @return array<string, mixed>
     */
    public function getInput(): array
    {
        return $this->input ?? [];
    }

    /**
     * @param array<string, mixed>|null $input
     */
    public function setInput(?array $input): self
    {
        $this->input = $input;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isTerminal(): bool
    {
        return \in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function getLeaseToken(): ?string
    {
        return $this->leaseToken;
    }

    public function setLeaseToken(?string $leaseToken): self
    {
        $this->leaseToken = $leaseToken;

        return $this;
    }

    public function getLeaseExpires(): int
    {
        return $this->leaseExpires;
    }

    public function setLeaseExpires(int $leaseExpires): self
    {
        $this->leaseExpires = $leaseExpires;

        return $this;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function setAttempt(int $attempt): self
    {
        $this->attempt = $attempt;

        return $this;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function setMaxAttempts(int $maxAttempts): self
    {
        $this->maxAttempts = $maxAttempts;

        return $this;
    }

    public function getIdempotency(): ?string
    {
        return $this->idempotency;
    }

    public function setIdempotency(?string $idempotency): self
    {
        $this->idempotency = $idempotency;

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

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function setErrorCode(?string $errorCode): self
    {
        $this->errorCode = $errorCode;

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

    public function setUpdated(int $updated): self
    {
        $this->updated = $updated;

        return $this;
    }

    public function touch(): self
    {
        $this->updated = time();

        return $this;
    }
}
