<?php

namespace App\Entity;

use App\Repository\RagDocumentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RagDocumentRepository::class)]
#[ORM\Table(name: 'BRAG')]
#[ORM\Index(columns: ['BUID'], name: 'idx_rag_user')]
#[ORM\Index(columns: ['BMID'], name: 'idx_rag_message')]
#[ORM\Index(columns: ['BGROUPKEY'], name: 'idx_rag_group')]
#[ORM\Index(columns: ['BTYPE'], name: 'idx_rag_type')]
class RagDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUID', type: 'bigint')]
    private int $userId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'BUID', referencedColumnName: 'BID')]
    private ?User $user = null;

    /**
     * BMID can reference EITHER:
     * - BMESSAGES.BID (for chat message attachments)
     * - BMESSAGEFILES.BID (for standalone file uploads)
     * 
     * We store only the ID without FK constraint to support both cases flexibly.
     * Application logic determines which table this ID references based on context.
     */
    #[ORM\Column(name: 'BMID', type: 'bigint')]
    private int $messageId;

    #[ORM\Column(name: 'BGROUPKEY', length: 64)]
    private string $groupKey;

    #[ORM\Column(name: 'BTYPE', type: 'integer')]
    private int $fileType; // File type identifier

    #[ORM\Column(name: 'BSTART', type: 'integer')]
    private int $startLine;

    #[ORM\Column(name: 'BEND', type: 'integer')]
    private int $endLine;

    #[ORM\Column(name: 'BTEXT', type: 'text')]
    private string $text;

    // MariaDB 11.7+ Vector Type (1024 dimensions)
    #[ORM\Column(name: 'BEMBED', type: 'vector', length: 1024)]
    private ?array $embedding = null;

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

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        if ($user) {
            $this->userId = $user->getId();
        }
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

    public function getGroupKey(): string
    {
        return $this->groupKey;
    }

    public function setGroupKey(string $groupKey): self
    {
        $this->groupKey = $groupKey;
        return $this;
    }

    public function getFileType(): int
    {
        return $this->fileType;
    }

    public function setFileType(int $fileType): self
    {
        $this->fileType = $fileType;
        return $this;
    }

    public function getStartLine(): int
    {
        return $this->startLine;
    }

    public function setStartLine(int $startLine): self
    {
        $this->startLine = $startLine;
        return $this;
    }

    public function getEndLine(): int
    {
        return $this->endLine;
    }

    public function setEndLine(int $endLine): self
    {
        $this->endLine = $endLine;
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

    public function getEmbedding(): ?array
    {
        return $this->embedding;
    }

    public function setEmbedding(?array $embedding): self
    {
        $this->embedding = $embedding;
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

