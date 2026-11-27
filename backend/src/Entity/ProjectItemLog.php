<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectItemLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One change of a project item. `before` holds the item's fields before the
 * change (null on `created`), so a change can be undone. The action and the
 * stage are what the board's undo and accept rates are counted from; the
 * log holds no message text.
 */
#[ORM\Entity(repositoryClass: ProjectItemLogRepository::class)]
#[ORM\Table(name: 'BPROJECTITEMLOG')]
#[ORM\Index(columns: ['BITEMID'], name: 'idx_project_item_log_item')]
#[ORM\Index(columns: ['BPROJECTID', 'BCREATED'], name: 'idx_project_item_log_project')]
#[ORM\Index(columns: ['BMESSAGEID'], name: 'idx_project_item_log_message')]
class ProjectItemLog
{
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DONE = 'done';
    public const ACTION_DROPPED = 'dropped';
    public const ACTION_RESTORED = 'restored';
    public const ACTION_ACCEPTED = 'accepted';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_UNDONE = 'undone';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BPROJECTID', type: 'bigint')]
    private int $projectId;

    #[ORM\Column(name: 'BITEMID', type: 'bigint')]
    private int $itemId;

    #[ORM\Column(name: 'BACTION', type: 'string', length: 16)]
    private string $action;

    /** 1 written, 2 proposed in the chat, 3 to confirm (`05` §3); null for a change the person made by hand. */
    #[ORM\Column(name: 'BSTAGE', type: 'smallint', nullable: true)]
    private ?int $stage;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'BBEFORE', type: 'json', nullable: true)]
    private ?array $before;

    #[ORM\Column(name: 'BBYUSERID', type: 'bigint', nullable: true)]
    private ?int $byUserId;

    #[ORM\Column(name: 'BMESSAGEID', type: 'bigint', nullable: true)]
    private ?int $messageId = null;

    #[ORM\Column(name: 'BRUNID', type: 'bigint', nullable: true)]
    private ?int $runId = null;

    #[ORM\Column(name: 'BCREATED', type: 'datetime_immutable')]
    private \DateTimeImmutable $created;

    /**
     * @param array<string, mixed>|null $before
     */
    public function __construct(int $projectId, int $itemId, string $action, ?array $before, ?int $byUserId, ?int $stage = null)
    {
        $this->projectId = $projectId;
        $this->itemId = $itemId;
        $this->action = $action;
        $this->before = $before;
        $this->byUserId = $byUserId;
        $this->stage = $stage;
        $this->created = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return null === $this->id ? null : (int) $this->id;
    }

    public function getProjectId(): int
    {
        return (int) $this->projectId;
    }

    public function getItemId(): int
    {
        return (int) $this->itemId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getStage(): ?int
    {
        return $this->stage;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBefore(): ?array
    {
        return $this->before;
    }

    public function getByUserId(): ?int
    {
        return null === $this->byUserId ? null : (int) $this->byUserId;
    }

    public function getMessageId(): ?int
    {
        return null === $this->messageId ? null : (int) $this->messageId;
    }

    public function getRunId(): ?int
    {
        return null === $this->runId ? null : (int) $this->runId;
    }

    public function getCreated(): \DateTimeImmutable
    {
        return $this->created;
    }
}
