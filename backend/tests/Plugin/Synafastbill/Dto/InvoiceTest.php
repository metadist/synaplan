<?php

declare(strict_types=1);

namespace App\Tests\Plugin\Synafastbill\Dto;

use PHPUnit\Framework\TestCase;
use Plugin\Synafastbill\Dto\Invoice;

final class InvoiceTest extends TestCase
{
    private const TODAY = '2026-08-01';

    public function testUnpaidPastDueIsOverdue(): void
    {
        $invoice = Invoice::fromArray([
            'INVOICE_ID' => 4102, 'TYPE' => 'outgoing', 'DUE_DATE' => '2026-06-15', 'PAID_DATE' => '', 'TOTAL' => 1190,
        ]);

        self::assertTrue($invoice->isOverdue(new \DateTimeImmutable(self::TODAY)));
        self::assertSame(47, $invoice->daysOverdue(new \DateTimeImmutable(self::TODAY)));
    }

    public function testPaidInvoiceIsNotOverdue(): void
    {
        $invoice = Invoice::fromArray(['TYPE' => 'outgoing', 'DUE_DATE' => '2026-06-15', 'PAID_DATE' => '2026-06-10']);

        self::assertTrue($invoice->isPaid());
        self::assertFalse($invoice->isOverdue(new \DateTimeImmutable(self::TODAY)));
    }

    public function testDraftIsNeverOverdue(): void
    {
        $invoice = Invoice::fromArray(['TYPE' => 'draft', 'DUE_DATE' => '2026-01-01', 'PAID_DATE' => '']);

        self::assertTrue($invoice->isDraft());
        self::assertFalse($invoice->isOverdue(new \DateTimeImmutable(self::TODAY)));
    }

    public function testFutureDueIsNotOverdue(): void
    {
        $invoice = Invoice::fromArray(['TYPE' => 'outgoing', 'DUE_DATE' => '2026-08-20', 'PAID_DATE' => '']);

        self::assertFalse($invoice->isOverdue(new \DateTimeImmutable(self::TODAY)));
    }

    public function testFastBillDateTimeFormatIsHandled(): void
    {
        // FastBill returns datetime strings and "0000-00-00 00:00:00" for unpaid.
        $invoice = Invoice::fromArray([
            'INVOICE_NUMBER' => '202605-167',
            'TYPE' => 'outgoing',
            'INVOICE_DATE' => '2026-05-27',
            'DUE_DATE' => '2026-06-10 00:00:00',
            'PAID_DATE' => '0000-00-00 00:00:00',
            'IS_CANCELED' => '0',
            'TOTAL' => 1756.86,
        ]);

        self::assertFalse($invoice->isPaid(), 'A 0000-00-00 datetime must count as unpaid');
        self::assertTrue($invoice->isOverdue(new \DateTimeImmutable(self::TODAY)));
        self::assertSame(52, $invoice->daysOverdue(new \DateTimeImmutable(self::TODAY)));
    }
}
