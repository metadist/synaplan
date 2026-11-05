<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AiLiteracyAckRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One person's confirmation of a version of "Using AI well".
 * A new version asks again. The row is erased with the account.
 */
#[ORM\Entity(repositoryClass: AiLiteracyAckRepository::class)]
#[ORM\Table(name: 'BAI_LITERACY_ACK')]
#[ORM\UniqueConstraint(name: 'uq_ai_literacy_ack', columns: ['BUSERID', 'BVERSION'])]
class AiLiteracyAck
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'BVERSION', length: 32)]
    private string $version;

    #[ORM\Column(name: 'BCONFIRMED', type: 'bigint', nullable: true)]
    private ?int $confirmedAt = null;

    #[ORM\Column(name: 'BREMINDAT', type: 'bigint', nullable: true)]
    private ?int $remindAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): void
    {
        $this->userId = $userId;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    public function getConfirmedAt(): ?int
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?int $confirmedAt): void
    {
        $this->confirmedAt = $confirmedAt;
    }

    public function getRemindAt(): ?int
    {
        return $this->remindAt;
    }

    public function setRemindAt(?int $remindAt): void
    {
        $this->remindAt = $remindAt;
    }
}
