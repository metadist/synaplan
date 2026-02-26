<?php

namespace App\Entity;

use App\Repository\FileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * File Entity (Table: BFILES).
 */
#[ORM\Entity(repositoryClass: FileRepository::class)]
#[ORM\Table(name: 'BFILES')]
#[ORM\Index(columns: ['BUSERID'], name: 'idx_file_user')]
#[ORM\Index(columns: ['BUSERSESSIONID'], name: 'idx_file_session')]
#[ORM\Index(columns: ['BFILETYPE'], name: 'idx_file_type')]
#[ORM\Index(columns: ['BSTATUS'], name: 'idx_file_status')]
#[ORM\Index(columns: ['BGROUPKEY'], name: 'idx_file_groupkey')]
class File
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    /**
     * For widget uploads: BUSERID=0, BUSERSESSIONID=session_id from BWIDGET_SESSIONS
     * For regular user uploads: BUSERID=user_id, BUSERSESSIONID=null.
     */
    #[ORM\Column(name: 'BUSERSESSIONID', type: 'bigint', nullable: true)]
    private ?int $userSessionId = null;

    #[ORM\Column(name: 'BFILEPATH', length: 255)]
    private string $filePath = '';

    #[ORM\Column(name: 'BFILETYPE', length: 16)]
    private string $fileType = '';

    #[ORM\Column(name: 'BFILENAME', length: 255)]
    private string $fileName = '';

    #[ORM\Column(name: 'BFILESIZE', type: 'integer')]
    private int $fileSize = 0;

    #[ORM\Column(name: 'BFILEMIME', length: 128)]
    private string $fileMime = '';

    #[ORM\Column(name: 'BFILETEXT', type: 'text')]
    private string $fileText = '';

    #[ORM\Column(name: 'BSTATUS', length: 32, options: ['default' => 'uploaded'])]
    private string $status = 'uploaded';

    #[ORM\Column(name: 'BGROUPKEY', length: 128, nullable: true)]
    private ?string $groupKey = null;

    #[ORM\Column(name: 'BCREATEDAT', type: 'bigint')]
    private int $createdAt;

    public function __construct()
    {
        $this->createdAt = time();
    }

    // Getters and Setters

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

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function setFileName(string $fileName): self
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): self
    {
        $this->fileSize = $fileSize;

        return $this;
    }

    public function getFileMime(): string
    {
        return $this->fileMime;
    }

    public function setFileMime(string $fileMime): self
    {
        $this->fileMime = $fileMime;

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function setCreatedAt(int $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getGroupKey(): ?string
    {
        return $this->groupKey;
    }

    public function setGroupKey(?string $groupKey): self
    {
        $this->groupKey = $groupKey;

        return $this;
    }

    public function getUserSessionId(): ?int
    {
        return $this->userSessionId;
    }

    public function setUserSessionId(?int $userSessionId): self
    {
        $this->userSessionId = $userSessionId;

        return $this;
    }
}
