<?php

declare(strict_types=1);

namespace App\Tests\Plugin\Synafastbill\Intent;

use PHPUnit\Framework\TestCase;
use Plugin\Synafastbill\Intent\CommandType;
use Plugin\Synafastbill\Intent\IntentParser;

final class IntentParserTest extends TestCase
{
    private IntentParser $parser;

    protected function setUp(): void
    {
        $this->parser = new IntentParser();
    }

    public function testEmptyInputIsHelp(): void
    {
        self::assertSame(CommandType::Help, $this->parser->parse('')->type);
        self::assertSame(CommandType::Help, $this->parser->parse('/fastbill help')->type);
    }

    public function testCreateInvoiceExtractsCustomerHint(): void
    {
        $command = $this->parser->parse('/fastbill create a new draft invoice for customer XYZ GmbH');

        self::assertSame(CommandType::CreateDraftInvoice, $command->type);
        self::assertSame('XYZ GmbH', $command->stringParam('customer_hint'));
    }

    public function testCreateInvoiceWithoutCustomerKeyword(): void
    {
        $command = $this->parser->parse('create invoice for ACME Ltd');

        self::assertSame(CommandType::CreateDraftInvoice, $command->type);
        self::assertSame('ACME Ltd', $command->stringParam('customer_hint'));
    }

    public function testOverdue(): void
    {
        self::assertSame(CommandType::ShowOverdue, $this->parser->parse('/fastbill show me overdue payments')->type);
        self::assertSame(CommandType::ShowOverdue, $this->parser->parse('zeige überfällige Rechnungen')->type);
    }

    public function testRegisterPaymentKeepsBankText(): void
    {
        $command = $this->parser->parse('/fastbill register payment: 1.190,00 EUR Rechnung 2026-042');

        self::assertSame(CommandType::RegisterPayment, $command->type);
        self::assertSame('1.190,00 EUR Rechnung 2026-042', $command->stringParam('bank_text'));
    }

    public function testAffirmationsAndNegations(): void
    {
        self::assertSame(CommandType::Confirm, $this->parser->parse('yes')->type);
        self::assertSame(CommandType::Confirm, $this->parser->parse('ja, passt')->type);
        self::assertSame(CommandType::Cancel, $this->parser->parse('no')->type);
        self::assertSame(CommandType::Cancel, $this->parser->parse('cancel')->type);
        self::assertSame(CommandType::Cancel, $this->parser->parse('do not go ahead')->type);
        self::assertSame(CommandType::Cancel, $this->parser->parse("don't do it")->type);
        self::assertSame(CommandType::Confirm, $this->parser->parse('/fastbill yes')->type);
    }

    public function testSelectionByNumberAndOrdinal(): void
    {
        $byNumber = $this->parser->parse('2');
        self::assertSame(CommandType::Confirm, $byNumber->type);
        self::assertSame(2, $byNumber->params['selection']);
        self::assertSame(12, $this->parser->parse('12')->params['selection']);
        self::assertArrayNotHasKey('selection', $this->parser->parse('500.00 EUR')->params);
        self::assertArrayNotHasKey('selection', $this->parser->parse('/fastbill 500.00 EUR')->params);

        $byOrdinal = $this->parser->parse('take the first one');
        self::assertSame(CommandType::Confirm, $byOrdinal->type);
        self::assertSame(1, $byOrdinal->params['selection']);
    }

    public function testUnrecognisedInputIsUnknown(): void
    {
        self::assertSame(CommandType::Unknown, $this->parser->parse('what is the weather today')->type);
    }
}
