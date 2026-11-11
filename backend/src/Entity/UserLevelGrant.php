<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserLevelGrantRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One reason an account has a level. Unique per account and source, so a
 * webhook and an administrator cannot overwrite each other's row.
 */
#[ORM\Entity(repositoryClass: UserLevelGrantRepository::class)]
#[ORM\Table(name: 'BUSER_LEVEL_GRANTS')]
#[ORM\UniqueConstraint(name: 'uniq_level_grant_user_source', columns: ['BUSERID', 'BSOURCE'])]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_level_grant_user')]
class UserLevelGrant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'BSOURCE', length: 16)]
    private string $source;

    #[ORM\Column(name: 'BLEVEL', length: 32)]
    private string $level;

    #[ORM\Column(name: 'BREF', length: 191, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BCREATEDBY', type: 'bigint', nullable: true)]
    private ?int $createdBy = null;

    public function __construct(int $userId, string $source, string $level)
    {
        $this->userId = $userId;
        $this->source = $source;
        $this->level = $level;
        $this->created = time();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): void
    {
        $this->level = $level;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): void
    {
        $this->reference = $reference;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function setCreated(int $created): void
    {
        $this->created = $created;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?int $createdBy): void
    {
        $this->createdBy = $createdBy;
    }
}
