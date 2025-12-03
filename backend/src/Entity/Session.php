<?php

namespace App\Entity;

use App\Repository\SessionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SessionRepository::class)]
#[ORM\Table(name: 'BSESSIONS')]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_session_user')]
#[ORM\Index(columns: ['BTOKEN'], name: 'idx_session_token')]
#[ORM\Index(columns: ['BEXPIRES'], name: 'idx_session_expires')]
class Session
{
    #[ORM\Id]
    #[ORM\Column(name: 'BID', type: 'string', length: 128)]
    private string $id;

    #[ORM\Column(name: 'BUSERID', type: 'bigint', nullable: true)]
    private ?int $userId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'BUSERID', referencedColumnName: 'BID', nullable: true)]
    private ?User $user = null;

    #[ORM\Column(name: 'BTOKEN', length: 128, unique: true)]
    private string $token;

    #[ORM\Column(name: 'BDATA', type: 'text')]
    private string $data = '';

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BLASTACTIVITY', type: 'bigint')]
    private int $lastActivity;

    #[ORM\Column(name: 'BEXPIRES', type: 'bigint')]
    private int $expires;

    #[ORM\Column(name: 'BIPADDRESS', length: 45, options: ['default' => ''])]
    private string $ipAddress = '';

    #[ORM\Column(name: 'BUSERAGENT', length: 255, options: ['default' => ''])]
    private string $userAgent = '';

    public function __construct()
    {
        $now = time();
        $this->created = $now;
        $this->lastActivity = $now;
        $this->expires = $now + 86400; // 24h default
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(?int $userId): self
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
        $this->userId = $user?->getId();
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

    public function getData(): string
    {
        return $this->data;
    }

    public function setData(string $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function getDataArray(): array
    {
        return json_decode($this->data, true) ?? [];
    }

    public function setDataArray(array $data): self
    {
        $this->data = json_encode($data);
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

    public function getLastActivity(): int
    {
        return $this->lastActivity;
    }

    public function setLastActivity(int $lastActivity): self
    {
        $this->lastActivity = $lastActivity;
        return $this;
    }

    public function updateLastActivity(): self
    {
        $this->lastActivity = time();
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

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function setUserAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }
}

