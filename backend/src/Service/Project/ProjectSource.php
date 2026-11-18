<?php

declare(strict_types=1);

namespace App\Service\Project;

/**
 * One source of a project as the API shows it.
 */
final readonly class ProjectSource
{
    public const KIND_FILE = 'file';
    public const KIND_LINK = 'link';
    public const KIND_TEXT = 'text';

    public const STATE_READING = 'reading';
    public const STATE_READY = 'ready';
    public const STATE_FAILED = 'failed';

    public function __construct(
        public int $id,
        public string $name,
        public string $kind,
        public string $state,
        public ?string $reason,
        public ?string $sourceUrl,
        public ?int $fetchedAt,
        public int $size,
        public int $createdAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'state' => $this->state,
            'reason' => $this->reason,
            'sourceUrl' => $this->sourceUrl,
            'fetchedAt' => $this->fetchedAt,
            'size' => $this->size,
            'createdAt' => $this->createdAt,
        ];
    }
}
