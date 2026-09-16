<?php

declare(strict_types=1);

namespace App\Service\Document\Model;

use App\Service\Document\DocumentKind;

final class WordModel
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param list<WordBlock> $blocks
     */
    public function __construct(
        public array $blocks = [],
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function kind(): string
    {
        return DocumentKind::DOCX;
    }

    public function findBlock(string $id): ?WordBlock
    {
        foreach ($this->blocks as $block) {
            if ($block->id === $id) {
                return $block;
            }
        }

        return null;
    }

    public function findBlockIndex(string $id): ?int
    {
        foreach ($this->blocks as $i => $block) {
            if ($block->id === $id) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'kind' => DocumentKind::DOCX,
            'blocks' => array_map(static fn (WordBlock $b): array => $b->toArray(), $this->blocks),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $blocks = [];
        if (isset($data['blocks']) && is_array($data['blocks'])) {
            foreach ($data['blocks'] as $blockData) {
                if (is_array($blockData)) {
                    $blocks[] = WordBlock::fromArray($blockData);
                }
            }
        }

        return new self($blocks);
    }
}
