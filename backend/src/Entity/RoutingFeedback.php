<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoutingFeedbackRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RoutingFeedbackRepository::class)]
#[ORM\Table(name: 'BROUTING_FEEDBACKS')]
#[ORM\Index(columns: ['BUSER_ID'], name: 'idx_feedback_user')]
#[ORM\Index(columns: ['BSTATUS'], name: 'idx_feedback_status')]
#[ORM\Index(columns: ['BSUGGESTED_TOPIC'], name: 'idx_feedback_suggested')]
class RoutingFeedback
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSER_ID', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'BMESSAGE_ID', type: 'bigint')]
    private int $messageId;

    #[ORM\Column(name: 'BORIGINAL_TOPIC', length: 64)]
    private string $originalTopic;

    #[ORM\Column(name: 'BSUGGESTED_TOPIC', length: 64)]
    private string $suggestedTopic;

    #[ORM\Column(name: 'BSTATUS', length: 16, options: ['default' => 'pending'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'BVERIFICATION_REASON', type: 'text', nullable: true)]
    private ?string $verificationReason = null;

    #[ORM\Column(name: 'BCREATED_AT', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getMessageId(): int
    {
        return $this->messageId;
    }

    public function setMessageId(int $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function getOriginalTopic(): string
    {
        return $this->originalTopic;
    }

    public function setOriginalTopic(string $originalTopic): self
    {
        $this->originalTopic = $originalTopic;

        return $this;
    }

    public function getSuggestedTopic(): string
    {
        return $this->suggestedTopic;
    }

    public function setSuggestedTopic(string $suggestedTopic): self
    {
        $this->suggestedTopic = $suggestedTopic;

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

    public function isVerified(): bool
    {
        return self::STATUS_VERIFIED === $this->status;
    }

    public function getVerificationReason(): ?string
    {
        return $this->verificationReason;
    }

    public function setVerificationReason(?string $verificationReason): self
    {
        $this->verificationReason = $verificationReason;

        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
