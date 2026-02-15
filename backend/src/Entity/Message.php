<?php

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

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

    #[ORM\Column(name: 'BFILEPATH', type: 'text')]
    private string $filePath = '';

    #[ORM\Column(name: 'BFILETYPE', length: 8)]
    private string $fileType = '';

    #[ORM\Column(name: 'BTOPIC', length: 255, options: ['default' => 'UNKNOWN'])]
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
        // Truncate to column length (64) to prevent DB errors
        $this->topic = mb_substr($topic, 0, 64);

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

    // MessageMeta Relation
    #[ORM\OneToMany(targetEntity: MessageMeta::class, mappedBy: 'message', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $metadata;

    // File Many-to-Many Relation via Junction Table
    #[ORM\ManyToMany(targetEntity: File::class)]
    #[ORM\JoinTable(
        name: 'BMESSAGE_FILE_ATTACHMENTS',
        joinColumns: [new ORM\JoinColumn(name: 'BMESSAGEID', referencedColumnName: 'BID', onDelete: 'CASCADE')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'BFILEID', referencedColumnName: 'BID', onDelete: 'CASCADE')]
    )]
    private Collection $files;

    public function __construct()
    {
        $this->metadata = new ArrayCollection();
        $this->files = new ArrayCollection();
    }

    public function getMetadata(): Collection
    {
        return $this->metadata;
    }

    /**
     * @return Collection<int, File>
     */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(File $file): self
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
        }

        return $this;
    }

    public function removeFile(File $file): self
    {
        $this->files->removeElement($file);

        return $this;
    }

    /**
     * Check if message has any files attached.
     */
    public function hasFiles(): bool
    {
        return $this->files->count() > 0 || $this->file > 0;
    }

    /**
     * Get concatenated text from all attached files.
     */
    public function getAllFilesText(): string
    {
        $texts = [];

        // Legacy single file
        if ($this->file > 0 && !empty($this->fileText)) {
            $texts[] = $this->fileText;
        }

        // New multiple files
        foreach ($this->files as $file) {
            if (!empty($file->getFileText())) {
                $texts[] = $file->getFileText();
            }
        }

        return implode("\n\n---\n\n", $texts);
    }

    /**
     * Get meta value by key.
     */
    public function getMeta(string $key, ?string $default = null): ?string
    {
        foreach ($this->metadata as $meta) {
            if ($meta->getMetaKey() === $key) {
                return $meta->getMetaValue();
            }
        }

        return $default;
    }

    /**
     * Set meta value (creates or updates).
     */
    public function setMeta(string $key, string $value): self
    {
        // Update existing
        foreach ($this->metadata as $meta) {
            if ($meta->getMetaKey() === $key) {
                $meta->setMetaValue($value);

                return $this;
            }
        }

        // Create new
        $meta = new MessageMeta();
        $meta->setMessage($this);
        $meta->setMetaKey($key);
        $meta->setMetaValue($value);
        $this->metadata->add($meta);

        return $this;
    }

    /**
     * Remove meta by key.
     */
    public function removeMeta(string $key): self
    {
        foreach ($this->metadata as $meta) {
            if ($meta->getMetaKey() === $key) {
                $this->metadata->removeElement($meta);
                break;
            }
        }

        return $this;
    }

    // File Sharing Helpers

    /**
     * Check if file is publicly accessible.
     */
    public function isPublic(): bool
    {
        return '1' === $this->getMeta('file.is_public');
    }

    /**
     * Set public/private status.
     */
    public function setPublic(bool $public): self
    {
        return $this->setMeta('file.is_public', $public ? '1' : '0');
    }

    /**
     * Get share token.
     */
    public function getShareToken(): ?string
    {
        return $this->getMeta('file.share_token');
    }

    /**
     * Generate new share token.
     */
    public function generateShareToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->setMeta('file.share_token', $token);
        $this->setMeta('file.share_created_at', (string) time());

        return $token;
    }

    /**
     * Set share expiry timestamp.
     */
    public function setShareExpires(?int $timestamp): self
    {
        if ($timestamp) {
            $this->setMeta('file.share_expires', (string) $timestamp);
        } else {
            $this->removeMeta('file.share_expires');
        }

        return $this;
    }

    /**
     * Get share expiry timestamp.
     */
    public function getShareExpires(): ?int
    {
        $expires = $this->getMeta('file.share_expires');

        return $expires ? (int) $expires : null;
    }

    /**
     * Check if share link has expired.
     */
    public function isShareExpired(): bool
    {
        $expires = $this->getShareExpires();
        if (!$expires) {
            return false;
        }

        return time() > $expires;
    }

    /**
     * Revoke public access (remove share data).
     */
    public function revokeShare(): self
    {
        $this->setPublic(false);
        $this->removeMeta('file.share_token');
        $this->removeMeta('file.share_expires');
        $this->removeMeta('file.share_created_at');

        return $this;
    }
}
