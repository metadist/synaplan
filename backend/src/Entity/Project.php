<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A project: a name, instructions and a knowledge folder that holds its
 * sources. Chats join it through `BCHATS.BPROJECTID`.
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'BPROJECTS')]
#[ORM\UniqueConstraint(name: 'uniq_project_uuid', columns: ['BUUID'])]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_project_user')]
class Project
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUUID', type: 'string', length: 36, options: ['fixed' => true])]
    private string $uuid;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'BNAME', type: 'string', length: 120)]
    private string $name;

    #[ORM\Column(name: 'BDESCRIPTION', type: 'string', length: 500, nullable: true)]
    private ?string $description = null;

    /** length 65535 pins the DBAL comparator to TEXT, the migration DDL, instead of LONGTEXT. */
    #[ORM\Column(name: 'BINSTRUCTIONS', type: 'text', length: 65535, nullable: true)]
    private ?string $instructions = null;

    #[ORM\Column(name: 'BGROUPKEY', type: 'string', length: 64)]
    private string $groupKey;

    #[ORM\Column(name: 'BCREATED', type: 'datetime_immutable')]
    private \DateTimeInterface $created;

    #[ORM\Column(name: 'BUPDATED', type: 'datetime_immutable')]
    private \DateTimeInterface $updated;

    public function __construct(int $userId, string $name, string $uuid, string $groupKey)
    {
        $this->userId = $userId;
        $this->name = $name;
        $this->uuid = $uuid;
        $this->groupKey = $groupKey;
        $this->created = new \DateTimeImmutable();
        $this->updated = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return null === $this->id ? null : (int) $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getUserId(): int
    {
        return (int) $this->userId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function describe(?string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    public function getInstructions(): ?string
    {
        return $this->instructions;
    }

    public function instruct(?string $instructions): void
    {
        $this->instructions = $instructions;
        $this->touch();
    }

    public function getGroupKey(): string
    {
        return $this->groupKey;
    }

    public function getCreated(): \DateTimeInterface
    {
        return $this->created;
    }

    public function getUpdated(): \DateTimeInterface
    {
        return $this->updated;
    }

    private function touch(): void
    {
        $this->updated = new \DateTimeImmutable();
    }
}
