<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GroupMemberRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Membership of one user in one group. Composite PK (groupId, userId).
 */
#[ORM\Entity(repositoryClass: GroupMemberRepository::class)]
#[ORM\Table(name: 'BGROUPMEMBERS')]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_groupmember_user')]
class GroupMember
{
    public const ROLE_MEMBER = 'member';
    public const ROLE_MANAGER = 'manager';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_DIRECTORY = 'directory';

    /** @var list<string> */
    public const ROLES = [self::ROLE_MEMBER, self::ROLE_MANAGER];

    #[ORM\Id]
    #[ORM\Column(name: 'BGROUPID', type: 'bigint')]
    private int $groupId;

    #[ORM\Id]
    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'BROLE', length: 16, options: ['default' => self::ROLE_MEMBER])]
    private string $role = self::ROLE_MEMBER;

    #[ORM\Column(name: 'BSOURCE', length: 16, options: ['default' => self::SOURCE_MANUAL])]
    private string $source = self::SOURCE_MANUAL;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    public function __construct(int $groupId, int $userId)
    {
        $this->groupId = $groupId;
        $this->userId = $userId;
        $this->created = time();
    }

    public function getGroupId(): int
    {
        return $this->groupId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }
}
