<?php

declare(strict_types=1);

namespace App\Tests\Plugin\Synafastbill\Intent;

use PHPUnit\Framework\TestCase;
use Plugin\Synafastbill\Intent\BankStatementParser;

final class BankStatementParserTest extends TestCase
{
    private BankStatementParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BankStatementParser();
    }

    public function testGermanStatementWithInvoiceNumber(): void
    {
        $result = $this->parser->parse('Zahlungseingang 1.190,00 EUR am 28.07.2026 Verwendungszweck Rechnung 2026-042');

        self::assertSame(1190.0, $result['amount']);
        self::assertSame('EUR', $result['currency']);
        self::assertSame('2026-07-28', $result['date']);
        self::assertSame('2026-042', $result['invoice_number']);
    }

    public function testEnglishStatementAmountOnly(): void
    {
        $result = $this->parser->parse('Received 500.00 EUR on 2026-07-30 from Jane Doe');

        self::assertSame(500.0, $result['amount']);
        self::assertSame('2026-07-30', $result['date']);
        self::assertNull($result['invoice_number'], 'A yyyy-mm-dd date must not be read as an invoice number');
    }

    public function testDateIsNotReadAsTheAmount(): void
    {
        $result = $this->parser->parse('Received 10.00 EUR on 28.07.2026');

        self::assertSame(10.0, $result['amount']);
        self::assertSame('2026-07-28', $result['date']);
    }

    public function testDebitKeepsItsSign(): void
    {
        $result = $this->parser->parse('Lastschrift -500.00 EUR am 28.07.2026');

        self::assertSame(-500.0, $result['amount']);
        self::assertSame('EUR', $result['currency']);
    }

    public function testUnreadableTextYieldsNulls(): void
    {
        $result = $this->parser->parse('payment received, thanks');

        self::assertNull($result['amount']);
        self::assertNull($result['date']);
    }
}
