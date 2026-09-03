<?php

declare(strict_types=1);

namespace App\Service\Document\Model;

final class StyleModel
{
    public function __construct(
        public bool $bold = false,
        public bool $italic = false,
        public ?string $fill = null,
        public ?string $color = null,
        public ?string $align = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bold' => $this->bold,
            'italic' => $this->italic,
            'fill' => $this->fill,
            'color' => $this->color,
            'align' => $this->align,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['bold'] ?? false),
            (bool) ($data['italic'] ?? false),
            isset($data['fill']) && is_string($data['fill']) ? $data['fill'] : null,
            isset($data['color']) && is_string($data['color']) ? $data['color'] : null,
            isset($data['align']) && is_string($data['align']) ? $data['align'] : null,
        );
    }
}
