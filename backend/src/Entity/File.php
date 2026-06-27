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
#[ORM\Index(columns: ['BUSERID', 'BSOURCE'], name: 'idx_file_user_source')]
class File
{
    /**
     * Allowed provenance values for {@see self::$source}. Keep in sync with the
     * `source` whitelist in FileController and the file-world plan
     * (03_file-management.md §3.1).
     */
    public const SOURCES = [
        'web_upload',
        'chat_attachment',
        'outlook',
        'nextcloud',
        'opencloud',
        'whatsapp',
        'widget',
        'api',
        'generated',
    ];

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

    /**
     * Provenance: where this file came from. One of {@see self::SOURCES}.
     * Defaults to web_upload so legacy rows and plain uploads read sanely.
     */
    #[ORM\Column(name: 'BSOURCE', length: 32, options: ['default' => 'web_upload'])]
    private string $source = 'web_upload';

    /**
     * The file's original name at the source (e.g. the Nextcloud basename or
     * Outlook attachment name), preserved even if the stored name is
     * normalised. Null falls back to {@see self::$fileName}.
     */
    #[ORM\Column(name: 'BORIGINALNAME', length: 255, nullable: true)]
    private ?string $originalName = null;

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

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = in_array($source, self::SOURCES, true) ? $source : 'web_upload';

        return $this;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(?string $originalName): self
    {
        $originalName = null !== $originalName ? trim($originalName) : null;
        $this->originalName = ('' === $originalName) ? null : $originalName;

        return $this;
    }
}
