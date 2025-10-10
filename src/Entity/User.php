<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'BUSER')]
#[ORM\Index(columns: ['BMAIL'], name: 'BMAIL')]
#[ORM\Index(columns: ['BINTYPE'], name: 'BINTYPE')]
#[ORM\Index(columns: ['BPROVIDERID'], name: 'BPROVIDERID')]
#[ORM\Index(columns: ['BUSERLEVEL'], name: 'BUSERLEVEL')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BCREATED', length: 20)]
    private string $created = '';

    #[ORM\Column(name: 'BINTYPE', length: 4, options: ['default' => 'WEB'])]
    private string $type = 'WEB';

    #[ORM\Column(name: 'BMAIL', length: 128)]
    private string $mail = '';

    #[ORM\Column(name: 'BPW', length: 64)]
    private string $pw = '';

    #[ORM\Column(name: 'BPROVIDERID', length: 32)]
    private string $providerId = '';

    #[ORM\Column(name: 'BUSERLEVEL', length: 32, options: ['default' => 'NEW'])]
    private string $userLevel = 'NEW';

    #[ORM\Column(name: 'BEMAILVERIFIED', type: 'boolean', options: ['default' => false])]
    private bool $emailVerified = false;

    #[ORM\Column(name: 'BUSERDETAILS', type: 'json')]
    private array $userDetails = [];

    #[ORM\Column(name: 'BPAYMENTDETAILS', type: 'json')]
    private array $paymentDetails = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreated(): string
    {
        return $this->created;
    }

    public function setCreated(string $created): self
    {
        $this->created = $created;
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

    public function getMail(): string
    {
        return $this->mail;
    }

    public function setMail(string $mail): self
    {
        $this->mail = $mail;
        return $this;
    }

    public function getPw(): string
    {
        return $this->pw;
    }

    public function setPw(string $pw): self
    {
        $this->pw = $pw;
        return $this;
    }

    public function getProviderId(): string
    {
        return $this->providerId;
    }

    public function setProviderId(string $providerId): self
    {
        $this->providerId = $providerId;
        return $this;
    }

    public function getUserLevel(): string
    {
        return $this->userLevel;
    }

    public function setUserLevel(string $userLevel): self
    {
        $this->userLevel = $userLevel;
        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function setEmailVerified(bool $emailVerified): self
    {
        $this->emailVerified = $emailVerified;
        return $this;
    }

    public function getUserDetails(): array
    {
        return $this->userDetails;
    }

    public function setUserDetails(array $userDetails): self
    {
        $this->userDetails = $userDetails;
        return $this;
    }

    public function getPaymentDetails(): array
    {
        return $this->paymentDetails;
    }

    public function setPaymentDetails(array $paymentDetails): self
    {
        $this->paymentDetails = $paymentDetails;
        return $this;
    }

    // UserInterface implementation
    public function getUserIdentifier(): string
    {
        return $this->mail;
    }

    public function getRoles(): array
    {
        // Map user level to roles
        $roles = ['ROLE_USER'];
        
        if (in_array($this->userLevel, ['PRO', 'TEAM', 'BUSINESS'])) {
            $roles[] = 'ROLE_PRO';
        }
        
        if ($this->userLevel === 'BUSINESS') {
            $roles[] = 'ROLE_BUSINESS';
        }

        return array_unique($roles);
    }

    public function eraseCredentials(): void
    {
        // Nothing to do - we don't store sensitive temp data
    }

    // PasswordAuthenticatedUserInterface
    public function getPassword(): string
    {
        return $this->pw;
    }
}

