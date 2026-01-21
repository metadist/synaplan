<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PluginDataRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Generic plugin data storage.
 *
 * Allows plugins to store structured JSON data without requiring
 * plugin-specific database tables or schema changes.
 */
#[ORM\Entity(repositoryClass: PluginDataRepository::class)]
#[ORM\Table(name: 'plugin_data')]
#[ORM\UniqueConstraint(name: 'idx_user_plugin_type_key', columns: ['user_id', 'plugin_name', 'data_type', 'data_key'])]
#[ORM\Index(name: 'idx_user_plugin', columns: ['user_id', 'plugin_name'])]
#[ORM\Index(name: 'idx_plugin_type', columns: ['plugin_name', 'data_type'])]
#[ORM\HasLifecycleCallbacks]
class PluginData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'plugin_name', length: 64)]
    private string $pluginName;

    #[ORM\Column(name: 'data_type', length: 64)]
    private string $dataType;

    #[ORM\Column(name: 'data_key', length: 255, nullable: true)]
    private ?string $dataKey = null;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'data', type: 'json')]
    private array $data = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

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

    public function getPluginName(): string
    {
        return $this->pluginName;
    }

    public function setPluginName(string $pluginName): self
    {
        $this->pluginName = $this->sanitizeKey($pluginName);

        return $this;
    }

    public function getDataType(): string
    {
        return $this->dataType;
    }

    public function setDataType(string $dataType): self
    {
        $this->dataType = $this->sanitizeKey($dataType);

        return $this;
    }

    public function getDataKey(): ?string
    {
        return $this->dataKey;
    }

    public function setDataKey(?string $dataKey): self
    {
        $this->dataKey = $dataKey !== null ? $this->sanitizeKey($dataKey) : null;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    /** @param array<string, mixed> $data */
    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Sanitize key to alphanumeric + underscore only.
     */
    private function sanitizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9_]/', '', strtolower(trim($key))) ?? '';
    }
}
