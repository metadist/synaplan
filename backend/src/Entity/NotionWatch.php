<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotionWatchRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One Notion page a person watches. On a change Synaplan either refreshes
 * the copy in a knowledge folder or runs one saved task.
 */
#[ORM\Entity(repositoryClass: NotionWatchRepository::class)]
#[ORM\Table(name: 'BNOTIONWATCHES')]
#[ORM\UniqueConstraint(name: 'uq_notionwatch_user_page', columns: ['BUSERID', 'BPAGEID'])]
#[ORM\Index(columns: ['BENABLED', 'BNEXTCHECKAT'], name: 'idx_notionwatch_due')]
class NotionWatch
{
    public const ACTION_SEARCHABLE = 'searchable';
    public const ACTION_TASK = 'task';
    public const ACTIONS = [self::ACTION_SEARCHABLE, self::ACTION_TASK];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUSERID', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'BPAGEID', length: 64)]
    private string $pageId;

    #[ORM\Column(name: 'BURL', length: 1024)]
    private string $url;

    #[ORM\Column(name: 'BTITLE', length: 255, options: ['default' => ''])]
    private string $title = '';

    #[ORM\Column(name: 'BACTION', length: 16)]
    private string $action;

    #[ORM\Column(name: 'BGROUPKEY', length: 64, nullable: true)]
    private ?string $groupKey = null;

    #[ORM\Column(name: 'BTASKID', type: 'bigint', nullable: true)]
    private ?int $taskId = null;

    #[ORM\Column(name: 'BENABLED', type: 'boolean', options: ['default' => true])]
    private bool $enabled = true;

    /** Notion's last edit time, the version Synaplan already handled. */
    #[ORM\Column(name: 'BVERSION', length: 64, nullable: true)]
    private ?string $version = null;

    #[ORM\Column(name: 'BFILEID', type: 'bigint', nullable: true)]
    private ?int $fileId = null;

    #[ORM\Column(name: 'BCHECKEDAT', type: 'bigint', nullable: true)]
    private ?int $checkedAt = null;

    #[ORM\Column(name: 'BCHANGEDAT', type: 'bigint', nullable: true)]
    private ?int $changedAt = null;

    #[ORM\Column(name: 'BNEXTCHECKAT', type: 'bigint', options: ['default' => 0])]
    private int $nextCheckAt = 0;

    #[ORM\Column(name: 'BLASTERROR', length: 512, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    public function __construct(int $userId, string $pageId, string $url, string $action)
    {
        $this->userId = $userId;
        $this->pageId = $pageId;
        $this->url = mb_substr($url, 0, 1024);
        $this->action = $action;
        $this->created = time();
    }

    public function getId(): ?int
    {
        return null === $this->id ? null : (int) $this->id;
    }

    public function getUserId(): int
    {
        return (int) $this->userId;
    }

    public function getPageId(): string
    {
        return $this->pageId;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = mb_substr(trim($title), 0, 255);

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getGroupKey(): ?string
    {
        return $this->groupKey;
    }

    public function setGroupKey(?string $groupKey): self
    {
        $this->groupKey = null === $groupKey ? null : mb_substr($groupKey, 0, 64);

        return $this;
    }

    public function getTaskId(): ?int
    {
        return null === $this->taskId ? null : (int) $this->taskId;
    }

    public function setTaskId(?int $taskId): self
    {
        $this->taskId = $taskId;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function getFileId(): ?int
    {
        return null === $this->fileId ? null : (int) $this->fileId;
    }

    public function setFileId(?int $fileId): self
    {
        $this->fileId = $fileId;

        return $this;
    }

    public function getCheckedAt(): ?int
    {
        return null === $this->checkedAt ? null : (int) $this->checkedAt;
    }

    public function getChangedAt(): ?int
    {
        return null === $this->changedAt ? null : (int) $this->changedAt;
    }

    public function getNextCheckAt(): int
    {
        return (int) $this->nextCheckAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getCreated(): int
    {
        return (int) $this->created;
    }

    public function recordCheck(int $now, int $nextCheckAt, bool $changed): self
    {
        $this->checkedAt = $now;
        $this->nextCheckAt = $nextCheckAt;
        $this->lastError = null;
        if ($changed) {
            $this->changedAt = $now;
        }

        return $this;
    }

    /**
     * Not checked now (Notion is turned off); look again later without
     * touching the last check or error.
     */
    public function deferTo(int $nextCheckAt): self
    {
        $this->nextCheckAt = $nextCheckAt;

        return $this;
    }

    public function recordFailure(int $now, int $nextCheckAt, string $reason): self
    {
        $this->checkedAt = $now;
        $this->nextCheckAt = $nextCheckAt;
        $this->lastError = mb_substr($reason, 0, 512);

        return $this;
    }
}
