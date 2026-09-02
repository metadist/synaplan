<?php

declare(strict_types=1);

namespace App\Service\Document\Model;

/**
 * One spreadsheet cell. The model is the truth; the binary is a render.
 */
final class CellModel
{
    public function __construct(
        public mixed $value = null,
        public string $type = 'string',
        public ?string $formula = null,
        public ?string $numberFormat = null,
        public ?StyleModel $style = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'value' => $this->value,
            'type' => $this->type,
        ];
        if (null !== $this->formula) {
            $out['formula'] = $this->formula;
        }
        if (null !== $this->numberFormat) {
            $out['numberFormat'] = $this->numberFormat;
        }
        if (null !== $this->style) {
            $out['style'] = $this->style->toArray();
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $style = isset($data['style']) && is_array($data['style'])
            ? StyleModel::fromArray($data['style'])
            : null;

        return new self(
            $data['value'] ?? null,
            is_string($data['type'] ?? null) ? $data['type'] : 'string',
            isset($data['formula']) && is_string($data['formula']) ? $data['formula'] : null,
            isset($data['numberFormat']) && is_string($data['numberFormat']) ? $data['numberFormat'] : null,
            $style,
        );
    }
}
