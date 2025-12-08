<?php

namespace App\Entity;

use App\Repository\ApiKeyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table(name: 'BAPIKEYS')]
#[ORM\Index(columns: ['BKEY'], name: 'idx_apikey_key')]
#[ORM\Index(columns: ['BOWNERID'], name: 'idx_apikey_owner')]
#[ORM\Index(columns: ['BSTATUS'], name: 'idx_apikey_status')]
class ApiKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'BOWNERID', referencedColumnName: 'BID')]
    private ?User $owner = null;

    #[ORM\Column(name: 'BKEY', length: 64, unique: true)]
    private string $key;

    #[ORM\Column(name: 'BSTATUS', length: 16)]
    private string $status = 'active';

    #[ORM\Column(name: 'BLASTUSED', type: 'bigint', options: ['default' => 0])]
    private int $lastUsed = 0;

    #[ORM\Column(name: 'BSCOPES', type: 'json')]
    private array $scopes = [];

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BNAME', length: 128, options: ['default' => ''])]
    private string $name = '';

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

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): self
    {
        $this->owner = $owner;
        if ($owner) {
            $this->ownerId = $owner->getId();
        }

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = $key;

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
        return 'active' === $this->status;
    }

    public function getLastUsed(): int
    {
        return $this->lastUsed;
    }

    public function setLastUsed(int $lastUsed): self
    {
        $this->lastUsed = $lastUsed;

        return $this;
    }

    public function updateLastUsed(): self
    {
        $this->lastUsed = time();

        return $this;
    }

    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function setScopes(array $scopes): self
    {
        $this->scopes = $scopes;

        return $this;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes);
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }
}
