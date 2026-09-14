<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ComputeWorkspaceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * PHP mapping of a user to an opaque sidecar workspace id. Never a host path.
 */
#[ORM\Entity(repositoryClass: ComputeWorkspaceRepository::class)]
#[ORM\Table(name: 'BCOMPUTEWORKSPACES')]
#[ORM\UniqueConstraint(name: 'uq_computews_user', columns: ['BUSERID'])]
#[ORM\UniqueConstraint(name: 'uq_computews_wsid', columns: ['BWORKSPACEID'])]
#[ORM\Index(columns: ['BEXPIRESAT'], name: 'idx_computews_expires')]
class ComputeWorkspace
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DELETED = 'deleted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'integer')]
    private int $userId;

    #[ORM\Column(name: 'BWORKSPACEID', length: 26)]
    private string $workspaceId;

    #[ORM\Column(name: 'BQUOTAMB', type: 'integer')]
    private int $quotaMb;

    #[ORM\Column(name: 'BUSEDMB', type: 'integer', options: ['default' => 0])]
    private int $usedMb = 0;

    #[ORM\Column(name: 'BSTATUS', length: 16, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(name: 'BCREATED', type: 'datetime_immutable')]
    private \DateTimeImmutable $created;

    #[ORM\Column(name: 'BLASTUSED', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsed = null;

    #[ORM\Column(name: 'BEXPIRESAT', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct(int $userId, string $workspaceId, int $quotaMb, ?\DateTimeImmutable $expiresAt = null)
    {
        $this->userId = $userId;
        $this->workspaceId = $workspaceId;
        $this->quotaMb = $quotaMb;
        $this->created = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getWorkspaceId(): string
    {
        return $this->workspaceId;
    }

    public function getQuotaMb(): int
    {
        return $this->quotaMb;
    }

    public function getUsedMb(): int
    {
        return $this->usedMb;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt instanceof \DateTimeImmutable
            && $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    public function applyUsage(int $usedMb, ?\DateTimeImmutable $lastUsed): void
    {
        $this->usedMb = max(0, $usedMb);
        $this->lastUsed = $lastUsed;
    }

    public function markDeleted(): void
    {
        $this->status = self::STATUS_DELETED;
    }
}
