<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlatformInstanceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlatformInstanceRepository::class)]
#[ORM\Table(name: 'BPLATFORMINSTANCES')]
#[ORM\UniqueConstraint(name: 'uq_platform_instance', columns: ['BINSTANCEID'])]
#[ORM\Index(columns: ['BCLIENT', 'BHOST'], name: 'idx_platform_client_host')]
class PlatformInstance
{
    public const CLIENT_NEXTCLOUD = 'nextcloud';
    public const CLIENT_OWNCLOUD = 'owncloud';
    public const CLIENT_OUTLOOK = 'outlook';
    public const CLIENT_OPENCLOUD = 'opencloud';

    public const CLIENTS = [
        self::CLIENT_NEXTCLOUD,
        self::CLIENT_OWNCLOUD,
        self::CLIENT_OUTLOOK,
        self::CLIENT_OPENCLOUD,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING = 'pending';
    public const STATUS_REVOKED = 'revoked';

    public const OUTLOOK_BUILTIN_ID = 'outlook-builtin';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BCLIENT', length: 32)]
    private string $client;

    #[ORM\Column(name: 'BINSTANCEID', length: 64)]
    private string $instanceId;

    #[ORM\Column(name: 'BHOST', length: 255)]
    private string $host;

    #[ORM\Column(name: 'BSECRETHASH', length: 255, options: ['default' => ''])]
    private string $secretHash = '';

    /** @var list<string>|null */
    #[ORM\Column(name: 'BREDIRECTURIS', type: 'json', nullable: true)]
    private ?array $redirectUris = null;

    #[ORM\Column(name: 'BREGISTEREDBY', type: 'bigint', options: ['default' => 0])]
    private int $registeredBy = 0;

    #[ORM\Column(name: 'BSTATUS', length: 16, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BLASTSEEN', type: 'bigint', options: ['default' => 0])]
    private int $lastSeen = 0;

    /**
     * @param list<string>|null $redirectUris
     */
    public function __construct(
        string $client,
        string $instanceId,
        string $host,
        ?array $redirectUris = null,
        string $status = self::STATUS_PENDING,
        int $registeredBy = 0,
    ) {
        $this->client = $client;
        $this->instanceId = $instanceId;
        $this->host = $host;
        $this->redirectUris = $redirectUris;
        $this->status = $status;
        $this->registeredBy = $registeredBy;
        $this->created = time();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): string
    {
        return $this->client;
    }

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getSecretHash(): string
    {
        return $this->secretHash;
    }

    public function setSecretHash(string $secretHash): void
    {
        $this->secretHash = $secretHash;
    }

    /**
     * @return list<string>
     */
    public function getRedirectUris(): array
    {
        return $this->redirectUris ?? [];
    }

    public function getRegisteredBy(): int
    {
        return $this->registeredBy;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getLastSeen(): int
    {
        return $this->lastSeen;
    }

    public function touchLastSeen(): void
    {
        $this->lastSeen = time();
    }
}
