<?php

declare(strict_types=1);

namespace App\Bundle;

/**
 * One preview row. Codes are stable; UI copy lives in i18n (`bundle.*`).
 */
final readonly class ChecklistItem
{
    public function __construct(
        public string $code,
        public string $itemKey,
        public ?string $detail = null,
    ) {
    }

    /**
     * @return array{code: string, itemKey: string, detail: string|null}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'itemKey' => $this->itemKey,
            'detail' => $this->detail,
        ];
    }
}
