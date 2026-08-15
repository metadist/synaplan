<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConnectionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * User-owned connection to an external system (mailbox, MCP, WebDAV, …).
 */
#[ORM\Entity(repositoryClass: ConnectionRepository::class)]
#[ORM\Table(name: 'BCONNECTIONS')]
#[ORM\Index(columns: ['BOWNERID', 'BTYPE'], name: 'idx_connection_owner_type')]
class Connection
{
    public const STATUS_NEVER_TESTED = 'never_tested';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_ERROR = 'error';
    public const STATUS_REAUTH_REQUIRED = 'reauth_required';
    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUSES = [
        self::STATUS_NEVER_TESTED,
        self::STATUS_CONNECTED,
        self::STATUS_ERROR,
        self::STATUS_REAUTH_REQUIRED,
        self::STATUS_DISCONNECTED,
    ];

    public const TYPES = ['generic', 'mailbox', 'mcp', 'webdav', 'webhook', 'caldav'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BTYPE', length: 32)]
    private string $type;

    #[ORM\Column(name: 'BNAME', length: 191)]
    private string $name;

    #[ORM\Column(name: 'BSTATUS', length: 32)]
    private string $status = self::STATUS_NEVER_TESTED;

    #[ORM\Column(name: 'BLASTCHECKED', type: 'bigint', nullable: true)]
    private ?int $lastChecked = null;

    /** @var list<string>|null */
    #[ORM\Column(name: 'BSCOPES', type: 'json', nullable: true)]
    private ?array $scopes = null;

    #[ORM\Column(name: 'BCREDENTIALID', type: 'bigint', nullable: true)]
    private ?int $credentialId = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'BCONFIG', type: 'json', nullable: true)]
    private ?array $config = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint')]
    private int $updated;

    public function __construct(int $ownerId, string $type, string $name)
    {
        $now = time();
        $this->ownerId = $ownerId;
        $this->type = $type;
        $this->name = $name;
        $this->created = $now;
        $this->updated = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function getType(): string
    {
        return $this->type;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function getLastChecked(): ?int
    {
        return $this->lastChecked;
    }

    public function markChecked(string $status): self
    {
        $this->status = $status;
        $this->lastChecked = time();
        $this->touch();

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getScopes(): ?array
    {
        return $this->scopes;
    }

    /**
     * @param list<string>|null $scopes
     */
    public function setScopes(?array $scopes): self
    {
        $this->scopes = $scopes;
        $this->touch();

        return $this;
    }

    public function getCredentialId(): ?int
    {
        return $this->credentialId;
    }

    public function setCredentialId(?int $credentialId): self
    {
        $this->credentialId = $credentialId;
        $this->touch();

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getConfig(): ?array
    {
        return $this->config;
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public function setConfig(?array $config): self
    {
        $this->config = $config;
        $this->touch();

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

    private function touch(): void
    {
        $this->updated = time();
    }
}
