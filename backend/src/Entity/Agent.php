<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AgentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgentRepository::class)]
#[ORM\Table(name: 'BAGENTS')]
#[ORM\UniqueConstraint(name: 'uq_agents_owner_slug', columns: ['BOWNERID', 'BSLUG'])]
#[ORM\Index(columns: ['BOWNERID'], name: 'idx_agents_owner')]
#[ORM\Index(columns: ['BPROMPTID'], name: 'idx_agents_prompt')]
class Agent
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_PLUGIN_PREFIX = 'plugin:';

    /**
     * Topic prefix of the BPROMPTS row that carries an assistant's instructions.
     * Such prompts belong to the assistant: the classifier only sees them
     * when the assistant is published and marked routable.
     */
    public const TOPIC_PREFIX = 'agent:';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_ARCHIVED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BPROMPTID', type: 'bigint')]
    private int $promptId;

    #[ORM\Column(name: 'BSLUG', length: 96)]
    private string $slug;

    #[ORM\Column(name: 'BNAME', length: 128)]
    private string $name;

    #[ORM\Column(name: 'BDESCRIPTION', type: 'text', length: 65535, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'BICON', length: 64, options: ['default' => ''])]
    private string $icon = '';

    #[ORM\Column(name: 'BSTATUS', length: 16, options: ['default' => self::STATUS_DRAFT])]
    private string $status = self::STATUS_DRAFT;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'BDRAFT', type: 'json')]
    private array $draft;

    #[ORM\Column(name: 'BPUBLISHEDVERSIONID', type: 'bigint', nullable: true)]
    private ?int $publishedVersionId = null;

    #[ORM\Column(name: 'BPARENTID', type: 'bigint', nullable: true)]
    private ?int $parentId = null;

    #[ORM\Column(name: 'BSOURCE', length: 64, options: ['default' => self::SOURCE_MANUAL])]
    private string $source = self::SOURCE_MANUAL;

    #[ORM\Column(name: 'BROUTABLE', type: 'boolean', options: ['default' => 0])]
    private bool $routable = false;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint')]
    private int $updated;

    /**
     * @param array<string, mixed> $draft
     */
    public function __construct(int $ownerId, int $promptId, string $slug, string $name, array $draft)
    {
        $now = time();
        $this->ownerId = $ownerId;
        $this->promptId = $promptId;
        $this->slug = $slug;
        $this->name = $name;
        $this->draft = $draft;
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

    public function getSlug(): string
    {
        return $this->slug;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        $this->touch();

        return $this;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): self
    {
        $this->icon = $icon;
        $this->touch();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isDraft(): bool
    {
        return self::STATUS_DRAFT === $this->status;
    }

    public function isPublished(): bool
    {
        return self::STATUS_PUBLISHED === $this->status;
    }

    public function isArchived(): bool
    {
        return self::STATUS_ARCHIVED === $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid assistant status "%s"', $status));
        }
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function hasPublishedVersion(): bool
    {
        return null !== $this->publishedVersionId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDraft(): array
    {
        return $this->draft;
    }

    /**
     * @param array<string, mixed> $draft
     */
    public function setDraft(array $draft): self
    {
        $this->draft = $draft;
        $this->touch();

        return $this;
    }

    public function getPublishedVersionId(): ?int
    {
        return $this->publishedVersionId;
    }

    public function setPublishedVersionId(?int $publishedVersionId): self
    {
        $this->publishedVersionId = $publishedVersionId;
        $this->touch();

        return $this;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    public function setParentId(?int $parentId): self
    {
        $this->parentId = $parentId;
        $this->touch();

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        $this->touch();

        return $this;
    }

    public static function sourceForPlugin(string $pluginId): string
    {
        return self::SOURCE_PLUGIN_PREFIX.$pluginId;
    }

    public function isRoutable(): bool
    {
        return $this->routable;
    }

    public function setRoutable(bool $routable): self
    {
        $this->routable = $routable;
        $this->touch();

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

    private function touch(): void
    {
        $this->updated = time();
    }
}
