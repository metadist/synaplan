<?php

declare(strict_types=1);

namespace App\Bundle;

final readonly class SectionPreview
{
    /**
     * @param list<ChecklistItem> $items
     */
    public function __construct(
        public string $kind,
        public array $items = [],
        public int $itemCount = 0,
    ) {
    }

    /**
     * @return array{kind: string, itemCount: int, items: list<array{code: string, itemKey: string, detail: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'itemCount' => $this->itemCount,
            'items' => array_map(static fn (ChecklistItem $item): array => $item->toArray(), $this->items),
        ];
    }
}
