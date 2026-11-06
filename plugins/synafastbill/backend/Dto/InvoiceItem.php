<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Dto;

/**
 * A single line item on a FastBill invoice.
 */
final readonly class InvoiceItem
{
    public function __construct(
        public string $description,
        public float $quantity,
        public string $unit,
        public float $unitPrice,
        public float $vatPercent,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            description: (string) ($data['DESCRIPTION'] ?? ''),
            quantity: (float) ($data['QUANTITY'] ?? 0),
            unit: (string) ($data['UNIT'] ?? ''),
            unitPrice: (float) ($data['UNIT_PRICE'] ?? 0),
            vatPercent: (float) ($data['VAT_PERCENT'] ?? 0),
        );
    }

    /**
     * FastBill `DATA.ITEMS[]` payload for `invoice.create`.
     *
     * @return array<string, string|float>
     */
    public function toApiArray(): array
    {
        return [
            'DESCRIPTION' => $this->description,
            'QUANTITY' => $this->quantity,
            'UNIT_PRICE' => $this->unitPrice,
            'VAT_PERCENT' => $this->vatPercent,
        ];
    }

    public function netTotal(): float
    {
        return round($this->quantity * $this->unitPrice, 2);
    }
}
