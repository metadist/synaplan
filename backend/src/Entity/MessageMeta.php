<?php

namespace App\Entity;

use App\Repository\MessageMetaRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MessageMetaRepository::class)]
#[ORM\Table(name: 'BMESSAGEMETA')]
#[ORM\Index(columns: ['BMESSAGEID'], name: 'idx_messagemeta_message')]
#[ORM\Index(columns: ['BMETAKEY'], name: 'idx_messagemeta_key')]
class MessageMeta
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BMESSAGEID', type: 'bigint')]
    private int $messageId;

    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(name: 'BMESSAGEID', referencedColumnName: 'BID')]
    private ?Message $message = null;

    #[ORM\Column(name: 'BMETAKEY', length: 64)]
    private string $metaKey;

    #[ORM\Column(name: 'BMETAVALUE', type: 'text')]
    private string $metaValue;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    public function __construct()
    {
        $this->created = time();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): self
    {
        $this->message = $message;
        if ($message) {
            $this->messageId = $message->getId();
        }
        return $this;
    }

    public function getMetaKey(): string
    {
        return $this->metaKey;
    }

    public function setMetaKey(string $metaKey): self
    {
        $this->metaKey = $metaKey;
        return $this;
    }

    public function getMetaValue(): string
    {
        return $this->metaValue;
    }

    public function setMetaValue(string $metaValue): self
    {
        $this->metaValue = $metaValue;
        return $this;
    }

    public function getMetaValueArray(): ?array
    {
        $decoded = json_decode($this->metaValue, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function setMetaValueArray(array $value): self
    {
        $this->metaValue = json_encode($value);
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
}

