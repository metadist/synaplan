<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ShareRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One share of a resource with a user, a group, or everyone on the instance.
 */
#[ORM\Entity(repositoryClass: ShareRepository::class)]
#[ORM\Table(name: 'BSHARES')]
#[ORM\UniqueConstraint(name: 'uniq_share_subject', columns: ['BRESOURCEKIND', 'BRESOURCEID', 'BSUBJECTTYPE', 'BSUBJECTID'])]
#[ORM\Index(columns: ['BSUBJECTTYPE', 'BSUBJECTID', 'BRESOURCEKIND'], name: 'idx_share_lookup')]
class Share
{
    public const SUBJECT_USER = 'user';
    public const SUBJECT_GROUP = 'group';
    public const SUBJECT_EVERYONE = 'everyone';

    /** @var list<string> */
    public const SUBJECT_TYPES = [self::SUBJECT_USER, self::SUBJECT_GROUP, self::SUBJECT_EVERYONE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BRESOURCEKIND', length: 64)]
    private string $resourceKind = '';

    #[ORM\Column(name: 'BRESOURCEID', length: 191)]
    private string $resourceId = '';

    #[ORM\Column(name: 'BSUBJECTTYPE', length: 16)]
    private string $subjectType = self::SUBJECT_USER;

    #[ORM\Column(name: 'BSUBJECTID', type: 'bigint', options: ['default' => 0])]
    private int $subjectId = 0;

    #[ORM\Column(name: 'BPERMISSION', length: 16)]
    private string $permission = '';

    #[ORM\Column(name: 'BGRANTEDBY', type: 'bigint')]
    private int $grantedBy = 0;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    public function __construct()
    {
        $this->created = time();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResourceKind(): string
    {
        return $this->resourceKind;
    }

    public function setResourceKind(string $resourceKind): self
    {
        $this->resourceKind = $resourceKind;

        return $this;
    }

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function setResourceId(string $resourceId): self
    {
        $this->resourceId = $resourceId;

        return $this;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function setSubjectType(string $subjectType): self
    {
        $this->subjectType = $subjectType;

        return $this;
    }

    public function getSubjectId(): int
    {
        return $this->subjectId;
    }

    public function setSubjectId(int $subjectId): self
    {
        $this->subjectId = $subjectId;

        return $this;
    }

    public function getPermission(): string
    {
        return $this->permission;
    }

    public function setPermission(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    public function getGrantedBy(): int
    {
        return $this->grantedBy;
    }

    public function setGrantedBy(int $grantedBy): self
    {
        $this->grantedBy = $grantedBy;

        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }
}
