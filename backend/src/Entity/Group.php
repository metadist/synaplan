<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GroupRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A named list of people (manual or synced from the company login).
 *
 * Groups own nothing — they are only share subjects (IAM S2).
 */
#[ORM\Entity(repositoryClass: GroupRepository::class)]
#[ORM\Table(name: 'BGROUPS')]
#[ORM\UniqueConstraint(name: 'uniq_group_slug', columns: ['BSLUG'])]
#[ORM\UniqueConstraint(name: 'uniq_group_external', columns: ['BEXTERNALSOURCE', 'BEXTERNALID'])]
#[ORM\Index(columns: ['BKIND'], name: 'idx_group_kind')]
class Group
{
    public const KIND_MANUAL = 'manual';
    public const KIND_DIRECTORY = 'directory';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BNAME', length: 128)]
    private string $name = '';

    #[ORM\Column(name: 'BSLUG', length: 128)]
    private string $slug = '';

    #[ORM\Column(name: 'BDESCRIPTION', length: 512, options: ['default' => ''])]
    private string $description = '';

    #[ORM\Column(name: 'BKIND', length: 16, options: ['default' => self::KIND_MANUAL])]
    private string $kind = self::KIND_MANUAL;

    #[ORM\Column(name: 'BEXTERNALSOURCE', length: 191, nullable: true)]
    private ?string $externalSource = null;

    #[ORM\Column(name: 'BEXTERNALID', length: 191, nullable: true)]
    private ?string $externalId = null;

    /** Reserved for v2 nested groups; unused in v1. */
    #[ORM\Column(name: 'BPARENTID', type: 'bigint', nullable: true)]
    private ?int $parentId = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint')]
    private int $updated;

    public function __construct()
    {
        $now = time();
        $this->created = $now;
        $this->updated = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->touch();

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;
        $this->touch();

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        $this->touch();

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): self
    {
        $this->kind = $kind;
        $this->touch();

        return $this;
    }

    public function isDirectory(): bool
    {
        return self::KIND_DIRECTORY === $this->kind;
    }

    public function getExternalSource(): ?string
    {
        return $this->externalSource;
    }

    public function setExternalSource(?string $externalSource): self
    {
        $this->externalSource = $externalSource;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    public function setParentId(?int $parentId): self
    {
        $this->parentId = $parentId;

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

    public function touch(): self
    {
        $this->updated = time();

        return $this;
    }
}
