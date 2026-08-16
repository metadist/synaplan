<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SavedTaskRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SavedTaskRepository::class)]
#[ORM\Table(name: 'BSAVEDTASKS')]
#[ORM\Index(columns: ['BOWNERID', 'BENABLED'], name: 'idx_saved_task_owner_enabled')]
#[ORM\Index(columns: ['BNEXTRUNAT'], name: 'idx_saved_task_next_run')]
class SavedTask
{
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_CHAT = 'chat';
    public const TRIGGER_SCHEDULE = 'schedule';
    public const TRIGGER_INBOUND_EMAIL = 'inbound_email';
    public const TRIGGER_WEBHOOK = 'webhook';

    /**
     * TRIGGER_WEBHOOK is deliberately excluded: no ingress endpoint exists yet
     * (Sprint 4, work breakdown E22+). Accepting it would store a task that can
     * never fire. Add it back together with the webhook route.
     */
    public const TRIGGER_TYPES = [
        self::TRIGGER_MANUAL,
        self::TRIGGER_CHAT,
        self::TRIGGER_SCHEDULE,
        self::TRIGGER_INBOUND_EMAIL,
    ];

    public const AUTO_PAUSE_AFTER = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BPROMPTID', type: 'bigint')]
    private int $promptId;

    #[ORM\Column(name: 'BNAME', length: 191)]
    private string $name;

    #[ORM\Column(name: 'BENABLED', type: 'boolean')]
    private bool $enabled = true;

    #[ORM\Column(name: 'BTRIGGERTYPE', length: 32)]
    private string $triggerType = self::TRIGGER_MANUAL;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'BTRIGGERCONFIG', type: 'json', nullable: true)]
    private ?array $triggerConfig = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'BGRAPH', type: 'json', nullable: true)]
    private ?array $graph = null;

    #[ORM\Column(name: 'BALLOWUNATTENDED', type: 'boolean')]
    private bool $allowUnattended = false;

    #[ORM\Column(name: 'BCHATID', type: 'bigint', nullable: true)]
    private ?int $chatId = null;

    #[ORM\Column(name: 'BNEXTRUNAT', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextRunAt = null;

    #[ORM\Column(name: 'BLASTRUNAT', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(name: 'BCONSECUTIVEFAILURES', type: 'integer')]
    private int $consecutiveFailures = 0;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint')]
    private int $updated;

    public function __construct(int $ownerId, int $promptId, string $name)
    {
        $now = time();
        $this->ownerId = $ownerId;
        $this->promptId = $promptId;
        $this->name = $name;
        $this->created = $now;
        $this->updated = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function getPromptId(): int
    {
        return $this->promptId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        $this->touch();

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        $this->touch();

        return $this;
    }

    public function getTriggerType(): string
    {
        return $this->triggerType;
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public function setTrigger(string $type, ?array $config): self
    {
        if (!in_array($type, self::TRIGGER_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported trigger type "%s"', $type));
        }
        $this->triggerType = $type;
        $this->triggerConfig = $config;
        $this->touch();

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTriggerConfig(): ?array
    {
        return $this->triggerConfig;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getGraph(): ?array
    {
        return $this->graph;
    }

    /**
     * @param array<string, mixed>|null $graph
     */
    public function setGraph(?array $graph): self
    {
        $this->graph = $graph;
        $this->touch();

        return $this;
    }

    public function allowsUnattended(): bool
    {
        return $this->allowUnattended;
    }

    public function setAllowUnattended(bool $allow): self
    {
        $this->allowUnattended = $allow;
        $this->touch();

        return $this;
    }

    public function getChatId(): ?int
    {
        return $this->chatId;
    }

    public function setChatId(int $chatId): self
    {
        $this->chatId = $chatId;
        $this->touch();

        return $this;
    }

    public function getNextRunAt(): ?\DateTimeImmutable
    {
        return $this->nextRunAt;
    }

    public function setNextRunAt(?\DateTimeImmutable $nextRunAt): self
    {
        $this->nextRunAt = $nextRunAt;
        $this->touch();

        return $this;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?\DateTimeImmutable $lastRunAt): self
    {
        $this->lastRunAt = $lastRunAt;
        $this->touch();

        return $this;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function recordSuccess(): self
    {
        $this->consecutiveFailures = 0;
        $this->lastRunAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->touch();

        return $this;
    }

    public function recordFailure(): self
    {
        ++$this->consecutiveFailures;
        $this->lastRunAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($this->consecutiveFailures >= self::AUTO_PAUSE_AFTER) {
            $this->enabled = false;
        }
        $this->touch();

        return $this;
    }

    public function resume(): self
    {
        $this->enabled = true;
        $this->consecutiveFailures = 0;
        $this->touch();

        return $this;
    }

    public function isAutoPaused(): bool
    {
        return !$this->enabled && $this->consecutiveFailures >= self::AUTO_PAUSE_AFTER;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    private function touch(): void
    {
        $this->updated = time();
    }
}
