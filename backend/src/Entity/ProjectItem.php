<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectItemRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One thing on a project's board: a task, a date, a decision, a cost or a
 * note. Times are stored in UTC; an all-day date is stored at midnight UTC
 * of its calendar day, so it never moves across a DST change.
 *
 * The entity holds state only. Which kind may carry which field, and which
 * state change is allowed, is checked by `App\Service\Project\Item`.
 */
#[ORM\Entity(repositoryClass: ProjectItemRepository::class)]
#[ORM\Table(name: 'BPROJECTITEMS')]
#[ORM\UniqueConstraint(name: 'uniq_project_item_uuid', columns: ['BUUID'])]
#[ORM\Index(columns: ['BPROJECTID', 'BKIND', 'BSTATE'], name: 'idx_project_item_kind_state')]
#[ORM\Index(columns: ['BPROJECTID', 'BSTARTAT'], name: 'idx_project_item_start')]
#[ORM\Index(columns: ['BPARENTID'], name: 'idx_project_item_parent')]
class ProjectItem
{
    public const KIND_TASK = 'task';
    public const KIND_DATE = 'date';
    public const KIND_DECISION = 'decision';
    public const KIND_COST = 'cost';
    public const KIND_NOTE = 'note';
    public const KINDS = [self::KIND_TASK, self::KIND_DATE, self::KIND_DECISION, self::KIND_COST, self::KIND_NOTE];

    public const STATE_PROPOSED = 'proposed';
    public const STATE_OPEN = 'open';
    public const STATE_DONE = 'done';
    public const STATE_DROPPED = 'dropped';
    public const STATES = [self::STATE_PROPOSED, self::STATE_OPEN, self::STATE_DONE, self::STATE_DROPPED];

    public const VISIBILITY_PROJECT = 'project';
    public const VISIBILITY_PRIVATE = 'private';

    public const SOURCE_MESSAGE = 'message';
    public const SOURCE_FILE = 'file';
    public const SOURCE_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BUUID', type: 'string', length: 36, options: ['fixed' => true])]
    private string $uuid;

    #[ORM\Column(name: 'BPROJECTID', type: 'bigint')]
    private int $projectId;

    #[ORM\Column(name: 'BKIND', type: 'string', length: 16)]
    private string $kind;

    #[ORM\Column(name: 'BSTATE', type: 'string', length: 16)]
    private string $state;

    #[ORM\Column(name: 'BPARENTID', type: 'bigint', nullable: true)]
    private ?int $parentId = null;

    #[ORM\Column(name: 'BSORT', type: 'integer', nullable: true)]
    private ?int $sort = null;

    #[ORM\Column(name: 'BVISIBILITY', type: 'string', length: 8, options: ['default' => 'project'])]
    private string $visibility = self::VISIBILITY_PROJECT;

    #[ORM\Column(name: 'BWAITINGSINCE', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $waitingSince = null;

    #[ORM\Column(name: 'BTITLE', type: 'string', length: 200)]
    private string $title;

    #[ORM\Column(name: 'BDETAIL', type: 'string', length: 1000, nullable: true)]
    private ?string $detail = null;

    #[ORM\Column(name: 'BSTARTAT', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startAt = null;

    #[ORM\Column(name: 'BENDAT', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endAt = null;

    #[ORM\Column(name: 'BALLDAY', type: 'boolean', options: ['default' => false])]
    private bool $allDay = false;

    #[ORM\Column(name: 'BRRULE', type: 'string', length: 255, nullable: true)]
    private ?string $rrule = null;

    #[ORM\Column(name: 'BASSIGNEEID', type: 'bigint', nullable: true)]
    private ?int $assigneeId = null;

    #[ORM\Column(name: 'BCONTACT', type: 'string', length: 200, nullable: true)]
    private ?string $contact = null;

    #[ORM\Column(name: 'BAMOUNT', type: 'decimal', precision: 12, scale: 2, nullable: true)]
    private ?string $amount = null;

    #[ORM\Column(name: 'BESTIMATED', type: 'boolean', options: ['default' => false])]
    private bool $estimated = false;

    #[ORM\Column(name: 'BSOURCEKIND', type: 'string', length: 16)]
    private string $sourceKind;

    #[ORM\Column(name: 'BSOURCEID', type: 'bigint', nullable: true)]
    private ?int $sourceId = null;

    #[ORM\Column(name: 'BCREATEDBY', type: 'bigint')]
    private int $createdBy;

    #[ORM\Column(name: 'BCREATED', type: 'datetime_immutable')]
    private \DateTimeImmutable $created;

    #[ORM\Column(name: 'BUPDATED', type: 'datetime_immutable')]
    private \DateTimeImmutable $updated;

    public function __construct(string $uuid, int $projectId, string $kind, string $state, string $title, string $sourceKind, int $createdBy)
    {
        $this->uuid = $uuid;
        $this->projectId = $projectId;
        $this->kind = $kind;
        $this->state = $state;
        $this->title = $title;
        $this->sourceKind = $sourceKind;
        $this->createdBy = $createdBy;
        $this->created = new \DateTimeImmutable();
        $this->updated = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return null === $this->id ? null : (int) $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getProjectId(): int
    {
        return (int) $this->projectId;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): void
    {
        $this->state = $state;
        $this->touch();
    }

    public function getParentId(): ?int
    {
        return null === $this->parentId ? null : (int) $this->parentId;
    }

    public function setParentId(?int $parentId): void
    {
        $this->parentId = $parentId;
        $this->touch();
    }

    public function getSort(): ?int
    {
        return $this->sort;
    }

    public function setSort(?int $sort): void
    {
        $this->sort = $sort;
        $this->touch();
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    /** `private` for an item from a member's private chat in a shared project (§0 #99). */
    public function setVisibility(string $visibility): void
    {
        $this->visibility = $visibility;
        $this->touch();
    }

    public function getWaitingSince(): ?\DateTimeImmutable
    {
        return self::asUtc($this->waitingSince);
    }

    public function setWaitingSince(?\DateTimeImmutable $since): void
    {
        $this->waitingSince = self::toUtc($since);
        $this->touch();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->touch();
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function setDetail(?string $detail): void
    {
        $this->detail = $detail;
        $this->touch();
    }

    public function getStartAt(): ?\DateTimeImmutable
    {
        return self::asUtc($this->startAt);
    }

    public function setStartAt(?\DateTimeImmutable $startAt): void
    {
        $this->startAt = self::toUtc($startAt);
        $this->touch();
    }

    public function getEndAt(): ?\DateTimeImmutable
    {
        return self::asUtc($this->endAt);
    }

    public function setEndAt(?\DateTimeImmutable $endAt): void
    {
        $this->endAt = self::toUtc($endAt);
        $this->touch();
    }

    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    public function setAllDay(bool $allDay): void
    {
        $this->allDay = $allDay;
        $this->touch();
    }

    public function getRrule(): ?string
    {
        return $this->rrule;
    }

    public function setRrule(?string $rrule): void
    {
        $this->rrule = $rrule;
        $this->touch();
    }

    public function getAssigneeId(): ?int
    {
        return null === $this->assigneeId ? null : (int) $this->assigneeId;
    }

    public function getContact(): ?string
    {
        return $this->contact;
    }

    public function setContact(?string $contact): void
    {
        $this->contact = $contact;
        $this->touch();
    }

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(?string $amount): void
    {
        $this->amount = $amount;
        $this->touch();
    }

    public function isEstimated(): bool
    {
        return $this->estimated;
    }

    public function setEstimated(bool $estimated): void
    {
        $this->estimated = $estimated;
        $this->touch();
    }

    public function getSourceKind(): string
    {
        return $this->sourceKind;
    }

    public function getSourceId(): ?int
    {
        return null === $this->sourceId ? null : (int) $this->sourceId;
    }

    public function getCreatedBy(): int
    {
        return (int) $this->createdBy;
    }

    public function getCreated(): \DateTimeImmutable
    {
        return $this->created;
    }

    public function getUpdated(): \DateTimeImmutable
    {
        return $this->updated;
    }

    /** Stored as UTC wall-clock time, whatever PHP's default zone is. */
    private static function toUtc(?\DateTimeImmutable $at): ?\DateTimeImmutable
    {
        return $at?->setTimezone(new \DateTimeZone('UTC'));
    }

    /** Doctrine reads the stored wall-clock time in PHP's default zone; it was written in UTC. */
    private static function asUtc(?\DateTimeImmutable $at): ?\DateTimeImmutable
    {
        return null === $at ? null : new \DateTimeImmutable($at->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }

    private function touch(): void
    {
        $this->updated = new \DateTimeImmutable();
    }
}
