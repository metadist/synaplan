<?php

declare(strict_types=1);

namespace App\Entity;

use App\DTO\UserMemoryDTO;
use App\Repository\UserMemoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserMemoryRepository::class)]
#[ORM\Table(name: 'BUSERMEMORIES')]
#[ORM\Index(columns: ['BUSERID', 'BACTIVE', 'BUPDATED'], name: 'idx_memory_user_active_updated')]
#[ORM\Index(columns: ['BUSERID', 'BCATEGORY'], name: 'idx_memory_user_category')]
class UserMemory
{
    public const SOURCE_AUTO_DETECTED = 'auto_detected';
    public const SOURCE_USER_CREATED = 'user_created';
    public const SOURCE_USER_EDITED = 'user_edited';
    public const SOURCE_AI_EDITED = 'ai_edited';

    public const SOURCES = [
        self::SOURCE_AUTO_DETECTED,
        self::SOURCE_USER_CREATED,
        self::SOURCE_USER_EDITED,
        self::SOURCE_AI_EDITED,
    ];

    #[ORM\Id]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private int $id;

    #[ORM\Column(name: 'BUSERID', type: 'integer')]
    private int $userId;

    #[ORM\Column(name: 'BCATEGORY', length: 100)]
    private string $category;

    #[ORM\Column(name: 'BKEY', length: 255)]
    private string $key;

    #[ORM\Column(name: 'BVALUE', type: 'text')]
    private string $value;

    #[ORM\Column(name: 'BSOURCE', length: 32)]
    private string $source;

    #[ORM\Column(name: 'BMESSAGEID', type: 'bigint', nullable: true)]
    private ?int $messageId;

    #[ORM\Column(name: 'BNAMESPACE', length: 100, nullable: true)]
    private ?string $namespace;

    #[ORM\Column(name: 'BACTIVE', type: 'boolean', options: ['default' => true])]
    private bool $active;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BUPDATED', type: 'bigint')]
    private int $updated;

    public function __construct(
        int $id,
        int $userId,
        string $category,
        string $key,
        string $value,
        string $source,
        ?int $messageId = null,
        ?string $namespace = null,
        bool $active = true,
        ?int $created = null,
        ?int $updated = null,
    ) {
        $now = time();
        $this->id = $id;
        $this->userId = $userId;
        $this->category = $category;
        $this->key = $key;
        $this->value = $value;
        $this->source = $source;
        $this->messageId = $messageId;
        $this->namespace = $namespace;
        $this->active = $active;
        $this->created = $created ?? $now;
        $this->updated = $updated ?? $now;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getMessageId(): ?int
    {
        return $this->messageId;
    }

    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function getUpdated(): int
    {
        return $this->updated;
    }

    public function update(
        string $category,
        string $key,
        string $value,
        string $source,
        ?int $messageId,
        ?string $namespace,
    ): void {
        $this->category = $category;
        $this->key = $key;
        $this->value = $value;
        $this->source = $source;
        $this->messageId = $messageId;
        $this->namespace = $namespace;
        $this->updated = time();
    }

    public function toDTO(): UserMemoryDTO
    {
        return new UserMemoryDTO(
            id: $this->id,
            userId: $this->userId,
            category: $this->category,
            key: $this->key,
            value: $this->value,
            source: $this->source,
            messageId: $this->messageId,
            created: $this->created,
            updated: $this->updated,
            active: $this->active,
        );
    }
}
