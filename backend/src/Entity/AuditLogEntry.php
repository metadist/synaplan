<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only IAM audit row (share/unshare, group changes, impersonation, …).
 */
#[ORM\Entity(repositoryClass: AuditLogEntryRepository::class)]
#[ORM\Table(name: 'BAUDITLOG')]
#[ORM\Index(columns: ['BACTORID', 'BCREATED'], name: 'idx_audit_actor_created')]
#[ORM\Index(columns: ['BRESOURCEKIND', 'BRESOURCEID'], name: 'idx_audit_resource')]
#[ORM\Index(columns: ['BCREATED'], name: 'idx_audit_created')]
class AuditLogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BACTORID', type: 'bigint')]
    private int $actorId;

    #[ORM\Column(name: 'BACTION', length: 64)]
    private string $action;

    #[ORM\Column(name: 'BRESOURCEKIND', length: 64, options: ['default' => ''])]
    private string $resourceKind = '';

    #[ORM\Column(name: 'BRESOURCEID', length: 191, options: ['default' => ''])]
    private string $resourceId = '';

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'BSUBJECT', type: 'json', nullable: true)]
    private ?array $subject = null;

    #[ORM\Column(name: 'BIP', length: 45, options: ['default' => ''])]
    private string $ip = '';

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

    public function getActorId(): int
    {
        return $this->actorId;
    }

    public function setActorId(int $actorId): self
    {
        $this->actorId = $actorId;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;

        return $this;
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

    /**
     * @return array<string, mixed>|null
     */
    public function getSubject(): ?array
    {
        return $this->subject;
    }

    /**
     * @param array<string, mixed>|null $subject
     */
    public function setSubject(?array $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function setIp(string $ip): self
    {
        $this->ip = $ip;

        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }
}
