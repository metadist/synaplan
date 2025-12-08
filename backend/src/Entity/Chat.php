<?php

namespace App\Entity;

use App\Repository\ChatRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChatRepository::class)]
#[ORM\Table(name: 'BCHATS')]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_chat_user')]
#[ORM\Index(columns: ['BSHARETOKEN'], name: 'idx_chat_share')]
class Chat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'integer')]
    private int $userId;

    #[ORM\Column(name: 'BTITLE', type: 'string', length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(name: 'BCREATEDAT', type: 'datetime')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'BUPDATEDAT', type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    #[ORM\Column(name: 'BSHARETOKEN', type: 'string', length: 64, nullable: true, unique: true)]
    private ?string $shareToken = null;

    #[ORM\Column(name: 'BISPUBLIC', type: 'boolean', options: ['default' => false])]
    private bool $isPublic = false;

    #[ORM\OneToMany(mappedBy: 'chat', targetEntity: Message::class)]
    private Collection $messages;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

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

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getShareToken(): ?string
    {
        return $this->shareToken;
    }

    public function setShareToken(?string $shareToken): self
    {
        $this->shareToken = $shareToken;

        return $this;
    }

    public function generateShareToken(): self
    {
        $this->shareToken = bin2hex(random_bytes(32));

        return $this;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): self
    {
        $this->isPublic = $isPublic;

        return $this;
    }

    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): self
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setChat($this);
        }

        return $this;
    }

    public function removeMessage(Message $message): self
    {
        if ($this->messages->removeElement($message)) {
            if ($message->getChat() === $this) {
                $message->setChat(null);
            }
        }

        return $this;
    }

    public function updateTimestamp(): self
    {
        $this->updatedAt = new \DateTime();

        return $this;
    }
}
