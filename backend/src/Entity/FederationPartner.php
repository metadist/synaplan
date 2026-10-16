<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FederationPartnerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One partner company, or a pending invite that has not been accepted yet.
 */
#[ORM\Entity(repositoryClass: FederationPartnerRepository::class)]
#[ORM\Table(name: 'BFEDERATIONPARTNER')]
#[ORM\Index(columns: ['BPEERDOMAIN'], name: 'idx_federation_partner_domain')]
#[ORM\UniqueConstraint(name: 'uq_federation_partner_token', columns: ['BTOKENHASH'])]
class FederationPartner
{
    public const STATUS_INVITED = 'invited';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ENDED = 'ended';

    public const PAUSE_LOCAL = 'local';
    public const PAUSE_REMOTE = 'remote';
    public const PAUSE_BOTH = 'both';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BSTATUS', length: 16)]
    private string $status = self::STATUS_INVITED;

    #[ORM\Column(name: 'BPEERDOMAIN', length: 255, nullable: true)]
    private ?string $peerDomain = null;

    #[ORM\Column(name: 'BPEERAPI', length: 255, nullable: true)]
    private ?string $peerApi = null;

    #[ORM\Column(name: 'BPEERKEY', length: 128, nullable: true)]
    private ?string $peerKey = null;

    #[ORM\Column(name: 'BPEERNAME', length: 80, nullable: true)]
    private ?string $peerName = null;

    #[ORM\Column(name: 'BTOKENHASH', length: 64, nullable: true)]
    private ?string $tokenHash = null;

    #[ORM\Column(name: 'BEXPIRES', type: 'bigint', nullable: true)]
    private ?int $expiresAt = null;

    #[ORM\Column(name: 'BPAUSEDBY', length: 16, nullable: true)]
    private ?string $pausedBy = null;

    #[ORM\Column(name: 'BACCEPTEDBY', type: 'bigint', nullable: true)]
    private ?int $acceptedBy = null;

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

    public function getPeerDomain(): ?string
    {
        return $this->peerDomain;
    }

    public function setPeerDomain(?string $peerDomain): self
    {
        $this->peerDomain = $peerDomain;

        return $this;
    }

    public function getPeerApi(): ?string
    {
        return $this->peerApi;
    }

    public function setPeerApi(?string $peerApi): self
    {
        $this->peerApi = $peerApi;

        return $this;
    }

    public function getPeerKey(): ?string
    {
        return $this->peerKey;
    }

    public function setPeerKey(?string $peerKey): self
    {
        $this->peerKey = $peerKey;

        return $this;
    }

    public function getPeerName(): ?string
    {
        return $this->peerName;
    }

    public function setPeerName(?string $peerName): self
    {
        $this->peerName = $peerName;

        return $this;
    }

    public function getTokenHash(): ?string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(?string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;

        return $this;
    }

    public function getExpiresAt(): ?int
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?int $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getPausedBy(): ?string
    {
        return $this->pausedBy;
    }

    public function setPausedBy(?string $pausedBy): self
    {
        $this->pausedBy = $pausedBy;

        return $this;
    }

    public function getAcceptedBy(): ?int
    {
        return $this->acceptedBy;
    }

    public function setAcceptedBy(?int $acceptedBy): self
    {
        $this->acceptedBy = $acceptedBy;

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

    public function isLive(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_PAUSED], true);
    }
}
