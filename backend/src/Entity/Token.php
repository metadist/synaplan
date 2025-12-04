<?php

namespace App\Entity;

use App\Repository\TokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TokenRepository::class)]
#[ORM\Table(name: 'BTOKENS')]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_token_user')]
#[ORM\Index(columns: ['BTOKEN'], name: 'idx_token_token')]
#[ORM\Index(columns: ['BTYPE'], name: 'idx_token_type')]
#[ORM\Index(columns: ['BEXPIRES'], name: 'idx_token_expires')]
class Token
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'BUSERID', referencedColumnName: 'BID')]
    private ?User $user = null;

    #[ORM\Column(name: 'BTOKEN', length: 255, unique: true)]
    private string $token;

    #[ORM\Column(name: 'BTYPE', length: 32)]
    private string $type; // 'jwt', 'refresh', 'reset', 'verify'

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BEXPIRES', type: 'bigint')]
    private int $expires;

    #[ORM\Column(name: 'BUSED', type: 'boolean', options: ['default' => false])]
    private bool $used = false;

    #[ORM\Column(name: 'BUSEDDATE', type: 'bigint', nullable: true)]
    private ?int $usedDate = null;

    #[ORM\Column(name: 'BIPADDRESS', length: 45, options: ['default' => ''])]
    private string $ipAddress = '';

    public function __construct()
    {
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

    public function setUserId(int $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        if ($user) {
            $this->userId = $user->getId();
        }

        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

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

    public function getExpires(): int
    {
        return $this->expires;
    }

    public function setExpires(int $expires): self
    {
        $this->expires = $expires;

        return $this;
    }

    public function isExpired(): bool
    {
        return time() > $this->expires;
    }

    public function isUsed(): bool
    {
        return $this->used;
    }

    public function setUsed(bool $used): self
    {
        $this->used = $used;
        if ($used && !$this->usedDate) {
            $this->usedDate = time();
        }

        return $this;
    }

    public function markAsUsed(): self
    {
        $this->used = true;
        $this->usedDate = time();

        return $this;
    }

    public function getUsedDate(): ?int
    {
        return $this->usedDate;
    }

    public function setUsedDate(?int $usedDate): self
    {
        $this->usedDate = $usedDate;

        return $this;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isUsed();
    }
}
