<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\MessageRepository;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'BMESSAGES')]
#[ORM\Index(columns: ['BUSERID'], name: 'BUSERID')]
#[ORM\Index(columns: ['BTRACKID'], name: 'BTRACKID')]
#[ORM\Index(columns: ['BMESSTYPE'], name: 'BMESSTYPE')]
#[ORM\Index(columns: ['BFILE'], name: 'BFILE')]
#[ORM\Index(columns: ['BDIRECT'], name: 'BDIRECT')]
#[ORM\Index(columns: ['BLANG'], name: 'BLANG')]
#[ORM\Index(columns: ['BTOPIC'], name: 'BTOPIC')]
#[ORM\Index(columns: ['BCHATID'], name: 'idx_message_chat')]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'BCHATID', type: 'integer', nullable: true)]
    private ?int $chatId = null;

    #[ORM\ManyToOne(targetEntity: Chat::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'BCHATID', referencedColumnName: 'BID', onDelete: 'SET NULL')]
    private ?Chat $chat = null;

    #[ORM\Column(name: 'BTRACKID', type: 'bigint')]
    private int $trackingId;

    #[ORM\Column(name: 'BPROVIDX', length: 96)]
    private string $providerIndex = '';

    #[ORM\Column(name: 'BUNIXTIMES', type: 'bigint')]
    private int $unixTimestamp = 0;

    #[ORM\Column(name: 'BDATETIME', length: 20)]
    private string $dateTime = '';

    #[ORM\Column(name: 'BMESSTYPE', length: 4, options: ['default' => 'WA'])]
    private string $messageType = 'WA';

    #[ORM\Column(name: 'BFILE', type: 'smallint')]
    private int $file = 0;

    #[ORM\Column(name: 'BFILEPATH', length: 255)]
    private string $filePath = '';

    #[ORM\Column(name: 'BFILETYPE', length: 8)]
    private string $fileType = '';

    #[ORM\Column(name: 'BTOPIC', length: 16, options: ['default' => 'UNKNOWN'])]
    private string $topic = 'UNKNOWN';

    #[ORM\Column(name: 'BLANG', length: 2, options: ['default' => 'NN'])]
    private string $language = 'NN';

    #[ORM\Column(name: 'BTEXT', type: 'text')]
    private string $text = '';

    #[ORM\Column(name: 'BDIRECT', length: 3, options: ['default' => 'OUT'])]
    private string $direction = 'OUT';

    #[ORM\Column(name: 'BSTATUS', length: 24)]
    private string $status = '';

    #[ORM\Column(name: 'BFILETEXT', type: 'text')]
    private string $fileText = '';

    // Getters/Setters
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

    public function getTrackingId(): int
    {
        return $this->trackingId;
    }

    public function setTrackingId(int $trackingId): self
    {
        $this->trackingId = $trackingId;
        return $this;
    }

    public function getProviderIndex(): string
    {
        return $this->providerIndex;
    }

    public function setProviderIndex(string $providerIndex): self
    {
        $this->providerIndex = $providerIndex;
        return $this;
    }

    public function getUnixTimestamp(): int
    {
        return $this->unixTimestamp;
    }

    public function setUnixTimestamp(int $unixTimestamp): self
    {
        $this->unixTimestamp = $unixTimestamp;
        return $this;
    }

    public function getDateTime(): string
    {
        return $this->dateTime;
    }

    public function setDateTime(string $dateTime): self
    {
        $this->dateTime = $dateTime;
        return $this;
    }

    public function getMessageType(): string
    {
        return $this->messageType;
    }

    public function setMessageType(string $messageType): self
    {
        $this->messageType = $messageType;
        return $this;
    }

    public function getFile(): int
    {
        return $this->file;
    }

    public function setFile(int $file): self
    {
        $this->file = $file;
        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function setTopic(string $topic): self
    {
        $this->topic = $topic;
        return $this;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): self
    {
        $this->language = $language;
        return $this;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }

    public function setDirection(string $direction): self
    {
        $this->direction = $direction;
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

    public function getFileText(): string
    {
        return $this->fileText;
    }

    public function setFileText(string $fileText): self
    {
        $this->fileText = $fileText;
        return $this;
    }

    public function hasFile(): bool
    {
        return $this->file > 0;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): self
    {
        $this->filePath = $filePath;
        return $this;
    }

    public function getFileType(): string
    {
        return $this->fileType;
    }

    public function setFileType(string $fileType): self
    {
        $this->fileType = $fileType;
        return $this;
    }

    public function getChatId(): ?int
    {
        return $this->chatId;
    }

    public function setChatId(?int $chatId): self
    {
        $this->chatId = $chatId;
        return $this;
    }

    public function getChat(): ?Chat
    {
        return $this->chat;
    }

    public function setChat(?Chat $chat): self
    {
        $this->chat = $chat;
        $this->chatId = $chat?->getId();
        return $this;
    }
}
