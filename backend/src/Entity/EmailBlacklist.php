<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Email Blacklist for Spam Protection
 */
#[ORM\Entity]
#[ORM\Table(name: 'BEMAILBLACKLIST')]
#[ORM\Index(name: 'idx_email', columns: ['BEMAIL'])]
class EmailBlacklist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BEMAIL', type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(name: 'BREASON', type: 'string', length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'BCREATED', type: 'string', length: 20)]
    private string $created;

    #[ORM\Column(name: 'BBLACKLISTED_BY', type: 'bigint', nullable: true)]
    private ?int $blacklistedBy = null;

    public function __construct()
    {
        $this->created = date('YmdHis');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = strtolower(trim($email));
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $this->reason = $reason;
        return $this;
    }

    public function getCreated(): string
    {
        return $this->created;
    }

    public function getBlacklistedBy(): ?int
    {
        return $this->blacklistedBy;
    }

    public function setBlacklistedBy(?int $blacklistedBy): self
    {
        $this->blacklistedBy = $blacklistedBy;
        return $this;
    }
}

