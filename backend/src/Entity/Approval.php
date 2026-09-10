<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApprovalRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ApprovalRepository::class)]
#[ORM\Table(name: 'BAPPROVALS')]
#[ORM\Index(columns: ['BOWNERID', 'BSTATUS'], name: 'idx_approvals_owner_status')]
#[ORM\Index(columns: ['BSTATUS', 'BEXPIRESAT'], name: 'idx_approvals_expires')]
class Approval
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BREQUESTEDBY', length: 96)]
    private string $requestedBy;

    #[ORM\Column(name: 'BTOOL', length: 191)]
    private string $tool;

    #[ORM\Column(name: 'BSIDEEFFECT', length: 16)]
    private string $sideEffect;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'BARGS', type: 'json', nullable: true)]
    private ?array $args = null;

    #[ORM\Column(name: 'BPREVIEW', type: 'text', nullable: true)]
    private ?string $preview = null;

    #[ORM\Column(name: 'BSTATUS', length: 16, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'BEXPIRESAT', type: 'bigint')]
    private int $expiresAt;

    #[ORM\Column(name: 'BDECIDEDBY', type: 'bigint', nullable: true)]
    private ?int $decidedBy = null;

    #[ORM\Column(name: 'BDECIDEDAT', type: 'bigint', nullable: true)]
    private ?int $decidedAt = null;

    #[ORM\Column(name: 'BRESULTREF', length: 191, nullable: true)]
    private ?string $resultRef = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    public function __construct(
        int $ownerId,
        string $requestedBy,
        string $tool,
        string $sideEffect,
        int $expiresAt,
    ) {
        $this->ownerId = $ownerId;
        $this->requestedBy = $requestedBy;
        $this->tool = $tool;
        $this->sideEffect = $sideEffect;
        $this->expiresAt = $expiresAt;
        $this->created = time();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function getRequestedBy(): string
    {
        return $this->requestedBy;
    }

    public function getTool(): string
    {
        return $this->tool;
    }

    public function getSideEffect(): string
    {
        return $this->sideEffect;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getArgs(): ?array
    {
        return $this->args;
    }

    /**
     * @param array<string, mixed>|null $args
     */
    public function setArgs(?array $args): void
    {
        $this->args = $args;
    }

    public function getPreview(): ?string
    {
        return $this->preview;
    }

    public function setPreview(?string $preview): void
    {
        $this->preview = $preview;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    public function getDecidedBy(): ?int
    {
        return $this->decidedBy;
    }

    public function getDecidedAt(): ?int
    {
        return $this->decidedAt;
    }

    public function getResultRef(): ?string
    {
        return $this->resultRef;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function markApproved(int $decidedBy): void
    {
        $this->status = self::STATUS_APPROVED;
        $this->decidedBy = $decidedBy;
        $this->decidedAt = time();
    }

    public function markRejected(int $decidedBy): void
    {
        $this->status = self::STATUS_REJECTED;
        $this->decidedBy = $decidedBy;
        $this->decidedAt = time();
    }

    public function markExpired(): void
    {
        $this->status = self::STATUS_EXPIRED;
        $this->decidedAt = time();
    }

    public function markExecuted(?string $resultRef): void
    {
        $this->status = self::STATUS_EXECUTED;
        $this->resultRef = $resultRef;
    }

    public function markFailed(?string $resultRef): void
    {
        $this->status = self::STATUS_FAILED;
        $this->resultRef = $resultRef;
    }

    public function isPending(): bool
    {
        return self::STATUS_PENDING === $this->status;
    }
}
