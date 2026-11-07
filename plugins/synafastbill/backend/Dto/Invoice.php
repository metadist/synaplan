<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Dto;

/**
 * A FastBill invoice, as returned by the `invoice.get` service.
 */
final readonly class Invoice
{
    /**
     * @param array<int, InvoiceItem> $items
     */
    public function __construct(
        public int $id,
        public string $invoiceNumber,
        public int $customerId,
        public string $type,
        public string $invoiceDate,
        public string $dueDate,
        public string $paidDate,
        public bool $canceled,
        public float $subTotal,
        public float $vatTotal,
        public float $total,
        public string $currency,
        public array $items,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $items = [];
        $rawItems = $data['ITEMS'] ?? [];
        if (is_array($rawItems)) {
            foreach ($rawItems as $rawItem) {
                if (is_array($rawItem)) {
                    /* @var array<string, mixed> $rawItem */
                    $items[] = InvoiceItem::fromArray($rawItem);
                }
            }
        }

        return new self(
            id: (int) ($data['INVOICE_ID'] ?? 0),
            invoiceNumber: (string) ($data['INVOICE_NUMBER'] ?? ''),
            customerId: (int) ($data['CUSTOMER_ID'] ?? 0),
            type: (string) ($data['TYPE'] ?? ''),
            invoiceDate: self::dateOnly((string) ($data['INVOICE_DATE'] ?? '')),
            dueDate: self::dateOnly((string) ($data['DUE_DATE'] ?? '')),
            paidDate: self::dateOnly((string) ($data['PAID_DATE'] ?? '')),
            canceled: !in_array((string) ($data['IS_CANCELED'] ?? '0'), ['0', ''], true),
            subTotal: (float) ($data['SUB_TOTAL'] ?? 0),
            vatTotal: (float) ($data['VAT_TOTAL'] ?? 0),
            total: (float) ($data['TOTAL'] ?? 0),
            currency: (string) ($data['CURRENCY_CODE'] ?? 'EUR'),
            items: $items,
        );
    }

    public function isDraft(): bool
    {
        return 'draft' === strtolower($this->type);
    }

    /**
     * FastBill returns dates as datetime strings ("YYYY-MM-DD HH:MM:SS") and
     * uses "0000-00-00 00:00:00" for "no date". Normalise to the date part so
     * date parsing and the unpaid check ("0000-00-00") work reliably.
     */
    private static function dateOnly(string $value): string
    {
        $value = trim($value);

        return '' === $value ? '' : explode(' ', $value)[0];
    }

    public function isPaid(): bool
    {
        return '' !== $this->paidDate && '0000-00-00' !== $this->paidDate;
    }

    /**
     * Open (unpaid, not cancelled, not a draft) with a due date strictly before
     * the given reference date.
     */
    public function isOverdue(\DateTimeImmutable $today): bool
    {
        if ($this->isPaid() || $this->canceled || $this->isDraft()) {
            return false;
        }
        $due = $this->dueDateObject();

        return null !== $due && $due < $today->setTime(0, 0);
    }

    public function daysOverdue(\DateTimeImmutable $today): int
    {
        $due = $this->dueDateObject();
        if (null === $due) {
            return 0;
        }
        $diff = $today->setTime(0, 0)->diff($due);

        return 1 === $diff->invert ? $diff->days : 0;
    }

    private function dueDateObject(): ?\DateTimeImmutable
    {
        if ('' === trim($this->dueDate)) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->dueDate);

        return false === $parsed ? null : $parsed;
    }
}
