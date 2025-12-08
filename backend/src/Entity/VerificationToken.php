<?php

namespace App\Entity;

use App\Repository\VerificationTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VerificationTokenRepository::class)]
#[ORM\Table(name: 'BVERIFICATION_TOKENS')]
#[ORM\Index(columns: ['BTOKEN'], name: 'idx_token')]
#[ORM\Index(columns: ['BEXPIRES'], name: 'idx_expires')]
class VerificationToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUID', type: 'bigint')]
    private int $userId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'BUID', referencedColumnName: 'BID', nullable: false)]
    private User $user;

    #[ORM\Column(name: 'BTOKEN', length: 64, unique: true)]
    private string $token;

    #[ORM\Column(name: 'BTYPE', length: 32)]
    private string $type; // 'email_verification' or 'password_reset'

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BEXPIRES', type: 'bigint')]
    private int $expires;

    #[ORM\Column(name: 'BUSED', type: 'boolean', options: ['default' => false])]
    private bool $used = false;

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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        $this->userId = $user->getId();

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

    public function isUsed(): bool
    {
        return $this->used;
    }

    public function setUsed(bool $used): self
    {
        $this->used = $used;

        return $this;
    }

    public function isExpired(): bool
    {
        return time() > $this->expires;
    }

    public function isValid(): bool
    {
        return !$this->used && !$this->isExpired();
    }
}
