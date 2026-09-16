<?php

declare(strict_types=1);

namespace App\Service\Document\Model;

final class ChartModel
{
    public function __construct(
        public string $id,
        public string $type = 'bar',
        public string $title = '',
        public string $categoriesRange = '',
        public string $valuesRange = '',
        public string $anchor = 'D2',
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
            'title' => $this->title,
            'categoriesRange' => $this->categoriesRange,
            'valuesRange' => $this->valuesRange,
            'anchor' => $this->anchor,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : uniqid('chart_', true),
            is_string($data['type'] ?? null) ? $data['type'] : 'bar',
            is_string($data['title'] ?? null) ? $data['title'] : '',
            is_string($data['categoriesRange'] ?? null) ? $data['categoriesRange'] : '',
            is_string($data['valuesRange'] ?? null) ? $data['valuesRange'] : '',
            is_string($data['anchor'] ?? null) ? $data['anchor'] : 'D2',
        );
    }
}
