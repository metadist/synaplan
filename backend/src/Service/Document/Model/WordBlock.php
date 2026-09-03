<?php

declare(strict_types=1);

namespace App\Service\Document\Model;

final class WordBlock
{
    public const TYPE_HEADING = 'heading';
    public const TYPE_PARAGRAPH = 'paragraph';
    public const TYPE_TABLE = 'table';
    public const TYPE_IMAGE = 'image';
    public const TYPE_TOC = 'toc';
    public const TYPE_PAGE_BREAK = 'pagebreak';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $payload = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'payload' => $this->payload,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : uniqid('blk_', true),
            is_string($data['type'] ?? null) ? $data['type'] : self::TYPE_PARAGRAPH,
            isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : [],
        );
    }
}
