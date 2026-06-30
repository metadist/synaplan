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
#[ORM\Index(columns: ['BUSERID', 'BGROUPKEY'], name: 'idx_file_user_group')]
#[ORM\Index(columns: ['BUSERID', 'BVECTORSTATE'], name: 'idx_file_user_vstate')]
#[ORM\Index(columns: ['BUSERID', 'BINCOMING'], name: 'idx_file_user_incoming')]
#[ORM\Index(columns: ['BUSERID', 'BCREATEDAT'], name: 'idx_file_user_created')]
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

    /**
     * Sources that are *pushed in* by an external integration for the user to
     * use as knowledge. Files from these sources arrive in the "Incoming inbox"
     * ({@see self::$incoming}) so the user can triage them (03_file-management.md
     * §3.3) before they join the curated library.
     */
    public const INCOMING_SOURCES = [
        'outlook',
        'nextcloud',
        'opencloud',
    ];

    /**
     * Kind of a generated artefact, for {@see self::$source} === 'generated'.
     * Null for non-generated files (03_file-management.md §3.1, `BORIGINKIND`).
     */
    public const ORIGIN_KINDS = [
        'image',
        'video',
        'audio',
        'calendar',
        'document',
    ];

    /**
     * Authoritative vectorization state ({@see self::$vectorState}), decoupled
     * from {@see self::$status} which mixes the upload/extraction lifecycle
     * (03_file-management.md §3.1, `BVECTORSTATE`).
     */
    public const VECTOR_STATE_NONE = 'none';
    public const VECTOR_STATE_PENDING = 'pending';
    public const VECTOR_STATE_VECTORIZED = 'vectorized';
    public const VECTOR_STATE_FAILED = 'failed';
    public const VECTOR_STATE_NOT_APPLICABLE = 'not_applicable';

    public const VECTOR_STATES = [
        self::VECTOR_STATE_NONE,
        self::VECTOR_STATE_PENDING,
        self::VECTOR_STATE_VECTORIZED,
        self::VECTOR_STATE_FAILED,
        self::VECTOR_STATE_NOT_APPLICABLE,
    ];

    /**
     * File types (extensions / handler kinds) that are media and therefore not
     * RAG documents — their vector state is {@see self::VECTOR_STATE_NOT_APPLICABLE}.
     */
    public const MEDIA_TYPES = [
        'image', 'video', 'audio',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'heic', 'heif',
        'mp4', 'webm', 'mov', 'avi', 'mkv',
        'mp3', 'wav', 'ogg', 'm4a',
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

    /**
     * For generated files: the artefact kind, one of {@see self::ORIGIN_KINDS};
     * null otherwise (03_file-management.md §3.1, `BORIGINKIND`).
     */
    #[ORM\Column(name: 'BORIGINKIND', length: 24, nullable: true)]
    private ?string $originKind = null;

    /**
     * 1 while the file is a freshly-arrived external push awaiting triage in the
     * Incoming inbox; cleared once the user keeps/files it (03_file-management.md
     * §3.3, `BINCOMING`).
     */
    #[ORM\Column(name: 'BINCOMING', type: 'boolean', options: ['default' => 0])]
    private bool $incoming = false;

    /**
     * Relative path in the separate incoming/staging area where external pushes
     * land before promotion to the canonical tree; null once promoted
     * (03_file-management.md §3.3, `BSTAGEPATH`).
     */
    #[ORM\Column(name: 'BSTAGEPATH', length: 255, nullable: true)]
    private ?string $stagePath = null;

    /**
     * Link to the originating BMESSAGES.BID (generated media + chat attachments),
     * enabling "jump to chat" (03_file-management.md §3.1, `BMESSAGEID`).
     */
    #[ORM\Column(name: 'BMESSAGEID', type: 'bigint', nullable: true)]
    private ?int $messageId = null;

    /**
     * Authoritative vectorization state, one of {@see self::VECTOR_STATES}
     * (03_file-management.md §3.1, `BVECTORSTATE`).
     */
    #[ORM\Column(name: 'BVECTORSTATE', length: 16, options: ['default' => self::VECTOR_STATE_NONE])]
    private string $vectorState = self::VECTOR_STATE_NONE;

    /**
     * Cached chunk count kept in sync with the vector store so the list needs no
     * per-row Qdrant call (03_file-management.md §3.1, `BCHUNKCOUNT`).
     */
    #[ORM\Column(name: 'BCHUNKCOUNT', type: 'integer', options: ['default' => 0])]
    private int $chunkCount = 0;

    /**
     * Generating provider/model for generated media (03_file-management.md §3.1,
     * `BPROVIDER`).
     */
    #[ORM\Column(name: 'BPROVIDER', length: 48, nullable: true)]
    private ?string $provider = null;

    /**
     * Optional generated thumbnail/poster for fast grids (03_file-management.md
     * §3.1, `BTHUMBPATH`).
     */
    #[ORM\Column(name: 'BTHUMBPATH', length: 255, nullable: true)]
    private ?string $thumbPath = null;

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

    /**
     * The name shown to the user: the source's original name when present,
     * otherwise the stored name (03_file-management.md §4.4).
     */
    public function getDisplayName(): string
    {
        return $this->originalName ?? $this->fileName;
    }

    public function getOriginKind(): ?string
    {
        return $this->originKind;
    }

    public function setOriginKind(?string $originKind): self
    {
        $this->originKind = (null !== $originKind && in_array($originKind, self::ORIGIN_KINDS, true)) ? $originKind : null;

        return $this;
    }

    public function isIncoming(): bool
    {
        return $this->incoming;
    }

    public function setIncoming(bool $incoming): self
    {
        $this->incoming = $incoming;

        return $this;
    }

    public function getStagePath(): ?string
    {
        return $this->stagePath;
    }

    public function setStagePath(?string $stagePath): self
    {
        $stagePath = null !== $stagePath ? trim($stagePath) : null;
        $this->stagePath = ('' === $stagePath) ? null : $stagePath;

        return $this;
    }

    public function getMessageId(): ?int
    {
        return $this->messageId;
    }

    public function setMessageId(?int $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function getVectorState(): string
    {
        return $this->vectorState;
    }

    public function setVectorState(string $vectorState): self
    {
        $this->vectorState = in_array($vectorState, self::VECTOR_STATES, true) ? $vectorState : self::VECTOR_STATE_NONE;

        return $this;
    }

    public function getChunkCount(): int
    {
        return $this->chunkCount;
    }

    public function setChunkCount(int $chunkCount): self
    {
        $this->chunkCount = max(0, $chunkCount);

        return $this;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): self
    {
        $provider = null !== $provider ? trim($provider) : null;
        $this->provider = ('' === $provider) ? null : $provider;

        return $this;
    }

    public function getThumbPath(): ?string
    {
        return $this->thumbPath;
    }

    public function setThumbPath(?string $thumbPath): self
    {
        $thumbPath = null !== $thumbPath ? trim($thumbPath) : null;
        $this->thumbPath = ('' === $thumbPath) ? null : $thumbPath;

        return $this;
    }

    /**
     * Whether this file's type is media (image/video/audio) and therefore not a
     * RAG document. Used to derive {@see self::VECTOR_STATE_NOT_APPLICABLE}.
     */
    public function isMedia(): bool
    {
        return in_array(strtolower($this->fileType), self::MEDIA_TYPES, true)
            || 'generated' === $this->source && null !== $this->originKind && 'document' !== $this->originKind;
    }
}
