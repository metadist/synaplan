<?php

namespace App\Entity;

use App\Repository\WidgetRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WidgetRepository::class)]
#[ORM\Table(name: 'BWIDGETS')]
#[ORM\Index(columns: ['BWIDGETID'], name: 'idx_widget_id')]
#[ORM\Index(columns: ['BOWNERID'], name: 'idx_widget_owner')]
#[ORM\Index(columns: ['BSTATUS'], name: 'idx_widget_status')]
class Widget
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'BOWNERID', referencedColumnName: 'BID')]
    private ?User $owner = null;

    #[ORM\Column(name: 'BWIDGETID', length: 64, unique: true)]
    private string $widgetId;

    #[ORM\Column(name: 'BTASKPROMPT', length: 128)]
    private string $taskPromptTopic;

    #[ORM\Column(name: 'BNAME', length: 128)]
    private string $name;

    #[ORM\Column(name: 'BSTATUS', length: 16)]
    private string $status = 'active';

    #[ORM\Column(name: 'BCONFIG', type: 'json')]
    private array $config = [];

    #[ORM\Column(name: 'BALLOWED_DOMAINS', type: 'json')]
    private array $allowedDomains = [];

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint')]
    private int $updated;

    public function __construct()
    {
        $this->created = time();
        $this->updated = time();
        $this->widgetId = 'wdg_'.bin2hex(random_bytes(16));
        $this->allowedDomains = [];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function setOwnerId(int $ownerId): self
    {
        $this->ownerId = $ownerId;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): self
    {
        $this->owner = $owner;
        if ($owner) {
            $this->ownerId = $owner->getId();
        }

        return $this;
    }

    public function getWidgetId(): string
    {
        return $this->widgetId;
    }

    public function setWidgetId(string $widgetId): self
    {
        $this->widgetId = $widgetId;

        return $this;
    }

    public function getTaskPromptTopic(): string
    {
        return $this->taskPromptTopic;
    }

    public function setTaskPromptTopic(string $taskPromptTopic): self
    {
        $this->taskPromptTopic = $taskPromptTopic;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

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

    public function isActive(): bool
    {
        return 'active' === $this->status;
    }

    public function getConfig(): array
    {
        if (!isset($this->config['allowedDomains'])) {
            $this->config['allowedDomains'] = $this->allowedDomains;
        }

        return $this->config;
    }

    public function setConfig(array $config): self
    {
        $this->config = $config;
        if (isset($config['allowedDomains']) && is_array($config['allowedDomains'])) {
            $this->setAllowedDomains($config['allowedDomains']);
        }

        return $this;
    }

    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function setConfigValue(string $key, mixed $value): self
    {
        $this->config[$key] = $value;

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

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function setUpdated(int $updated): self
    {
        $this->updated = $updated;

        return $this;
    }

    public function touch(): self
    {
        $this->updated = time();

        return $this;
    }

    public function getAllowedDomains(): array
    {
        return $this->allowedDomains;
    }

    public function setAllowedDomains(array $allowedDomains): self
    {
        $normalized = array_values($allowedDomains);
        $this->allowedDomains = $normalized;

        if (!isset($this->config['allowedDomains']) || !is_array($this->config['allowedDomains'])) {
            $this->config['allowedDomains'] = [];
        }
        $this->config['allowedDomains'] = $normalized;

        $this->config = array_merge($this->config, [
            'allowedDomains' => $normalized,
        ]);

        return $this;
    }

    public function syncAllowedDomainsFromConfig(): void
    {
        if (!isset($this->config['allowedDomains']) || !is_array($this->config['allowedDomains'])) {
            return;
        }

        $normalized = array_values(array_unique(array_map(
            static fn ($domain) => is_string($domain) ? strtolower(trim($domain)) : null,
            $this->config['allowedDomains']
        )));

        $normalized = array_filter($normalized, static fn ($domain) => !empty($domain));

        $this->allowedDomains = $normalized;
        $this->config['allowedDomains'] = $normalized;
    }
}
