<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CloudWatchRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One person watching one folder or one file in one cloud.
 */
#[ORM\Entity(repositoryClass: CloudWatchRepository::class)]
#[ORM\Table(name: 'BCLOUDWATCHES')]
#[ORM\Index(columns: ['BOWNERID', 'BSTATE'], name: 'idx_cloudwatch_owner')]
#[ORM\Index(columns: ['BIDENTITYID'], name: 'idx_cloudwatch_identity')]
#[ORM\Index(columns: ['BTARGETID'], name: 'idx_cloudwatch_target')]
class CloudWatch
{
    public const KIND_FOLDER = 'folder';
    public const KIND_FILE = 'file';

    public const ACTION_SEARCHABLE = 'searchable';
    public const ACTION_TASK = 'task';

    public const PRESET_SEARCHABLE = 'keep_searchable';
    public const PRESET_TASK = 'task';
    public const PRESET_TRANSLATE = 'translate';
    public const PRESET_SUMMARIZE = 'summarize_mail';

    public const SET_NEW_FILE = 'new_file';
    public const SET_TOGETHER = 'together';
    public const SET_WHOLE_FOLDER = 'whole_folder';

    public const STATE_ACTIVE = 'active';
    public const STATE_PAUSED = 'paused';
    public const STATE_ATTENTION = 'attention';
    public const STATE_STOPPED = 'stopped';

    public const REASON_BY_OWNER = 'by_owner';
    public const REASON_IDENTITY_OFF = 'identity_off';
    public const REASON_PLATFORM_USER_DISABLED = 'platform_user_disabled';
    public const REASON_PERMISSION = 'permission';
    public const REASON_TARGET_GONE = 'target_gone';
    public const REASON_NO_ACCESS = 'no_access';
    public const REASON_TASK_GONE = 'task_gone';
    public const REASON_POLICY_OFF = 'policy_off';
    public const REASON_FAILING = 'failing';
    public const REASON_IDENTITY_DELETED = 'identity_deleted';

    public const CREATED_CLOUD = 'cloud';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BIDENTITYID', type: 'bigint', nullable: true)]
    private ?int $identityId = null;

    #[ORM\Column(name: 'BCONNECTIONID', type: 'bigint', nullable: true)]
    private ?int $connectionId = null;

    #[ORM\Column(name: 'BTARGETID', length: 255)]
    private string $targetId;

    #[ORM\Column(name: 'BTARGETKIND', length: 16)]
    private string $targetKind;

    #[ORM\Column(name: 'BTARGETPATH', length: 255, options: ['default' => ''])]
    private string $targetPath = '';

    #[ORM\Column(name: 'BRECURSIVE', type: 'boolean', options: ['default' => false])]
    private bool $recursive = false;

    /** @var list<string>|null */
    #[ORM\Column(name: 'BEVENTS', type: 'json', nullable: true)]
    private ?array $events = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'BFILTER', type: 'json', nullable: true)]
    private ?array $filter = null;

    #[ORM\Column(name: 'BACTION', length: 16)]
    private string $action;

    #[ORM\Column(name: 'BPRESET', length: 32, nullable: true)]
    private ?string $preset = null;

    #[ORM\Column(name: 'BFILESET', length: 16, options: ['default' => self::SET_NEW_FILE])]
    private string $fileSet = self::SET_NEW_FILE;

    #[ORM\Column(name: 'BWINDOWSEC', type: 'integer', options: ['default' => 600])]
    private int $windowSec = 600;

    #[ORM\Column(name: 'BMAXFILES', type: 'integer', options: ['default' => 50])]
    private int $maxFiles = 50;

    #[ORM\Column(name: 'BGROUPKEY', length: 64, nullable: true)]
    private ?string $groupKey = null;

    #[ORM\Column(name: 'BTASKID', type: 'bigint', nullable: true)]
    private ?int $taskId = null;

    #[ORM\Column(name: 'BSTATE', length: 16)]
    private string $state = self::STATE_ACTIVE;

    #[ORM\Column(name: 'BREASON', length: 32, nullable: true)]
    private ?string $reason = null;

    /** @var array{searchable: int, done: int, failed: int, waiting: int} */
    #[ORM\Column(name: 'BCOUNTS', type: 'json', nullable: true)]
    private ?array $counts = null;

    #[ORM\Column(name: 'BLASTEVENTAT', type: 'bigint', nullable: true)]
    private ?int $lastEventAt = null;

    #[ORM\Column(name: 'BLASTRUNAT', type: 'bigint', nullable: true)]
    private ?int $lastRunAt = null;

    #[ORM\Column(name: 'BLASTSTATUS', length: 32, nullable: true)]
    private ?string $lastStatus = null;

    #[ORM\Column(name: 'BCREATEDIN', length: 16, options: ['default' => self::CREATED_CLOUD])]
    private string $createdIn = self::CREATED_CLOUD;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint')]
    private int $updated;

    public function __construct(int $ownerId, string $targetKind, string $targetId)
    {
        $this->ownerId = $ownerId;
        $this->targetKind = $targetKind;
        $this->targetId = $targetId;
        $this->created = time();
        $this->updated = $this->created;
        $this->counts = ['searchable' => 0, 'done' => 0, 'failed' => 0, 'waiting' => 0];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function getIdentityId(): ?int
    {
        return $this->identityId;
    }

    public function setIdentityId(?int $identityId): self
    {
        $this->identityId = $identityId;

        return $this;
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getTargetKind(): string
    {
        return $this->targetKind;
    }

    public function getTargetPath(): string
    {
        return $this->targetPath;
    }

    public function setTargetPath(string $targetPath): self
    {
        $this->targetPath = $targetPath;

        return $this;
    }

    public function isRecursive(): bool
    {
        return $this->recursive;
    }

    public function setRecursive(bool $recursive): self
    {
        $this->recursive = $recursive;

        return $this;
    }

    /** @return list<string> */
    public function getEvents(): array
    {
        return $this->events ?? [];
    }

    /** @param list<string> $events */
    public function setEvents(array $events): self
    {
        $this->events = $events;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getFilter(): array
    {
        return $this->filter ?? [];
    }

    /** @param array<string, mixed> $filter */
    public function setFilter(array $filter): self
    {
        $this->filter = $filter;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;

        return $this;
    }

    public function getPreset(): ?string
    {
        return $this->preset;
    }

    public function setPreset(?string $preset): self
    {
        $this->preset = $preset;

        return $this;
    }

    public function getFileSet(): string
    {
        return $this->fileSet;
    }

    public function setFileSet(string $fileSet): self
    {
        $this->fileSet = $fileSet;

        return $this;
    }

    public function getWindowSec(): int
    {
        return $this->windowSec;
    }

    public function setWindowSec(int $windowSec): self
    {
        $this->windowSec = $windowSec;

        return $this;
    }

    public function getMaxFiles(): int
    {
        return $this->maxFiles;
    }

    public function setMaxFiles(int $maxFiles): self
    {
        $this->maxFiles = $maxFiles;

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

    public function getTaskId(): ?int
    {
        return $this->taskId;
    }

    public function setTaskId(?int $taskId): self
    {
        $this->taskId = $taskId;

        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function mark(string $state, ?string $reason): self
    {
        $this->state = $state;
        $this->reason = $reason;
        $this->updated = time();

        return $this;
    }

    /** @return array{searchable: int, done: int, failed: int, waiting: int} */
    public function getCounts(): array
    {
        return $this->counts ?? ['searchable' => 0, 'done' => 0, 'failed' => 0, 'waiting' => 0];
    }

    public function addCount(string $bucket): self
    {
        $counts = $this->getCounts();
        if ('searchable' === $bucket) {
            ++$counts['searchable'];
        } elseif ('done' === $bucket) {
            ++$counts['done'];
        } elseif ('failed' === $bucket) {
            ++$counts['failed'];
        } elseif ('waiting' === $bucket) {
            ++$counts['waiting'];
        }
        $this->counts = $counts;
        $this->updated = time();

        return $this;
    }

    public function getLastEventAt(): ?int
    {
        return $this->lastEventAt;
    }

    public function setLastEventAt(?int $lastEventAt): self
    {
        $this->lastEventAt = $lastEventAt;
        $this->updated = time();

        return $this;
    }

    public function getLastRunAt(): ?int
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?int $lastRunAt): self
    {
        $this->lastRunAt = $lastRunAt;

        return $this;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function isSearchable(): bool
    {
        return self::ACTION_SEARCHABLE === $this->action;
    }

    public function occupiesASlot(): bool
    {
        return self::STATE_STOPPED !== $this->state;
    }
}
