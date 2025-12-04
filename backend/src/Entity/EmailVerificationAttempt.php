<?php

namespace App\Entity;

use App\Repository\EmailVerificationAttemptRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailVerificationAttemptRepository::class)]
#[ORM\Table(name: 'BEMAILVERIFICATION')]
#[ORM\Index(columns: ['BEMAIL'], name: 'idx_email')]
#[ORM\Index(columns: ['BCREATEDAT'], name: 'idx_created')]
class EmailVerificationAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'BEMAIL', type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(name: 'BATTEMPTS', type: 'integer', options: ['default' => 1])]
    private int $attempts = 1;

    #[ORM\Column(name: 'BLASTATTEMPTAT', type: 'datetime')]
    private \DateTimeInterface $lastAttemptAt;

    #[ORM\Column(name: 'BCREATEDAT', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'BIPADDRESS', type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->lastAttemptAt = new \DateTime();
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
        $this->email = $email;

        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function incrementAttempts(): self
    {
        ++$this->attempts;
        $this->lastAttemptAt = new \DateTime();

        return $this;
    }

    public function resetAttempts(): self
    {
        $this->attempts = 1;
        $this->lastAttemptAt = new \DateTime();

        return $this;
    }

    public function getLastAttemptAt(): \DateTimeInterface
    {
        return $this->lastAttemptAt;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function canResend(int $cooldownMinutes = 2, int $maxAttempts = 5): bool
    {
        // Check max attempts
        if ($this->attempts >= $maxAttempts) {
            return false;
        }

        // Check cooldown
        $now = new \DateTime();
        $diff = $now->getTimestamp() - $this->lastAttemptAt->getTimestamp();

        return $diff >= ($cooldownMinutes * 60);
    }

    public function getNextAvailableAt(int $cooldownMinutes = 2): ?\DateTimeInterface
    {
        $nextAvailable = \DateTime::createFromInterface($this->lastAttemptAt);
        $nextAvailable->modify("+{$cooldownMinutes} minutes");

        return $nextAvailable;
    }

    public function getRemainingAttempts(int $maxAttempts = 5): int
    {
        return max(0, $maxAttempts - $this->attempts);
    }
}
