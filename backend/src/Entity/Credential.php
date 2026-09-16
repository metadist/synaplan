<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CredentialRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Encrypted secret for a connection (password, token, HMAC).
 * Plaintext never leaves {@see \App\Service\Credential\CredentialVaultInterface}.
 */
#[ORM\Entity(repositoryClass: CredentialRepository::class)]
#[ORM\Table(name: 'BCREDENTIALS')]
#[ORM\Index(columns: ['BOWNERID'], name: 'idx_credential_owner')]
class Credential
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BKIND', length: 32)]
    private string $kind;

    #[ORM\Column(name: 'BSECRET', type: 'text')]
    private string $secret = '';

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint')]
    private int $updated;

    public function __construct(int $ownerId, string $kind)
    {
        $now = time();
        $this->ownerId = $ownerId;
        $this->kind = $kind;
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

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function setSecret(string $ciphertext): self
    {
        $this->secret = $ciphertext;
        $this->updated = time();

        return $this;
    }
}
