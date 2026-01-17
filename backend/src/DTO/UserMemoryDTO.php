<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * UserMemoryDTO - Data Transfer Object for user memories.
 *
 * All memory data lives in Qdrant microservice (not in MariaDB).
 * This DTO is used for API responses and internal data transfer.
 */
final class UserMemoryDTO
{
    public function __construct(
        public ?int $id = null,
        public ?int $userId = null,
        public string $category = '',
        public string $key = '',
        public string $value = '',
        public string $source = 'user_created',
        public ?int $messageId = null,
        public int $created = 0,
        public int $updated = 0,
        public bool $active = true,
    ) {
        if (0 === $this->created) {
            $this->created = time();
        }
        if (0 === $this->updated) {
            $this->updated = time();
        }
    }

    /**
     * Create from Qdrant payload.
     */
    public static function fromQdrantPayload(array $payload, string $pointId): self
    {
        // Extract memory ID from point ID (format: "mem_{userId}_{memoryId}")
        $memoryId = null;
        if (preg_match('/^mem_\d+_(\d+)$/', $pointId, $matches)) {
            $memoryId = (int) $matches[1];
        }

        return new self(
            id: $memoryId,
            userId: $payload['user_id'] ?? null,
            category: $payload['category'] ?? 'personal',
            key: $payload['key'] ?? '',
            value: $payload['value'] ?? '',
            source: $payload['source'] ?? 'auto_detected',
            messageId: $payload['message_id'] ?? null,
            created: $payload['created'] ?? time(),
            updated: $payload['updated'] ?? time(),
            active: $payload['active'] ?? true,
        );
    }

    /**
     * Convert to array for API response.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->userId,
            'category' => $this->category,
            'key' => $this->key,
            'value' => $this->value,
            'source' => $this->source,
            'messageId' => $this->messageId,
            'created' => $this->created, // Keep as timestamp
            'updated' => $this->updated, // Keep as timestamp
            'active' => $this->active,
        ];
    }
}
