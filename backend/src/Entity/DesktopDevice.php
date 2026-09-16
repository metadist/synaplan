<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DesktopDeviceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A computer a user paired with Synaplan Desktop.
 *
 * The row is created by the pairing exchange (Sprint A2). It owns no key
 * material — {@see $apiKeyId} points at the scoped {@see ApiKey} minted at
 * pairing, which is where the secret lives (shown once, then only in the
 * client's OS secret store).
 */
#[ORM\Entity(repositoryClass: DesktopDeviceRepository::class)]
#[ORM\Table(name: 'BDESKTOPDEVICES')]
#[ORM\Index(columns: ['BOWNERID'], name: 'idx_desktop_owner')]
#[ORM\Index(columns: ['BAPIKEYID'], name: 'idx_desktop_apikey')]
class DesktopDevice
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BNAME', length: 128, options: ['default' => ''])]
    private string $name = '';

    #[ORM\Column(name: 'BAPIKEYID', type: 'bigint')]
    private int $apiKeyId;

    #[ORM\Column(name: 'BSTATUS', length: 16, options: ['default' => self::STATUS_ACTIVE])]
    private string $status = self::STATUS_ACTIVE;

    /** @var list<string>|null */
    #[ORM\Column(name: 'BCAPABILITIES', type: 'json', nullable: true)]
    private ?array $capabilities = null;

    #[ORM\Column(name: 'BLASTSEEN', type: 'bigint', options: ['default' => 0])]
    private int $lastSeen = 0;

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

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function setOwnerId(int $ownerId): self
    {
        $this->ownerId = $ownerId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getApiKeyId(): int
    {
        return $this->apiKeyId;
    }

    public function setApiKeyId(int $apiKeyId): self
    {
        $this->apiKeyId = $apiKeyId;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    /**
     * @return list<string>
     */
    public function getCapabilities(): array
    {
        return $this->capabilities ?? [];
    }

    /**
     * @param list<string>|null $capabilities
     */
    public function setCapabilities(?array $capabilities): self
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    public function getLastSeen(): int
    {
        return $this->lastSeen;
    }

    public function setLastSeen(int $lastSeen): self
    {
        $this->lastSeen = $lastSeen;

        return $this;
    }

    public function touchLastSeen(): self
    {
        $this->lastSeen = time();

        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function setCreated(int $created): self
    {
        $this->created = $created;

        return $this;
    }
}
