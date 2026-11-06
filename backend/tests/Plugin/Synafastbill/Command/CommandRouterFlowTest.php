<?php

declare(strict_types=1);

namespace App\Tests\Plugin\Synafastbill\Command;

use App\Tests\Plugin\Synafastbill\Fake\FakeFastBillHttpClient;
use App\Tests\Plugin\Synafastbill\Fake\FixedClock;
use App\Tests\Plugin\Synafastbill\Fake\Scenario;
use PHPUnit\Framework\TestCase;
use Plugin\Synafastbill\Api\FastBillClient;
use Plugin\Synafastbill\Api\FastBillCredentials;
use Plugin\Synafastbill\Command\PluginConfig;
use Plugin\Synafastbill\Command\ResultType;
use Plugin\Synafastbill\Command\RouterFactory;
use Plugin\Synafastbill\Command\Session\InMemorySessionStore;

final class CommandRouterFlowTest extends TestCase
{
    private const KEY = 'chat_1';

    public function testHelp(): void
    {
        $router = Scenario::router(Scenario::httpClient());
        $result = $router->dispatch('/fastbill help', self::KEY);

        self::assertSame(ResultType::Info, $result->type);
        self::assertStringContainsString('Synafastbill', $result->message);
    }

    public function testGermanLanguageResponses(): void
    {
        $http = Scenario::httpClient();
        $router = Scenario::router($http, null, new PluginConfig('de'));

        $help = $router->dispatch('/fastbill help', self::KEY);
        self::assertStringContainsString('sprich mit deinem FastBill-Konto', $help->message);

        $overdue = $router->dispatch('/fastbill show overdue', self::KEY);
        self::assertStringContainsString('überfällig', $overdue->message);

        $create = $router->dispatch('/fastbill erstelle Rechnung für XYZ GmbH', self::KEY);
        self::assertSame(ResultType::NeedsConfirmation, $create->type);
        self::assertStringContainsString('Gefunden', $create->message);
        $services = array_map(static fn (array $request): string => (string) ($request['SERVICE'] ?? ''), $http->requests);
        self::assertNotContains('invoice.create', $services);

        $confirm = $router->dispatch('ja', self::KEY);
        self::assertSame(ResultType::Success, $confirm->type);
        self::assertStringContainsString('Rechnungsentwurf', $confirm->message);
        $services = array_map(static fn (array $request): string => (string) ($request['SERVICE'] ?? ''), $http->requests);
        self::assertContains('invoice.create', $services);
    }

    public function testHelpWorksWithoutCredentials(): void
    {
        $router = RouterFactory::create(
            new FastBillClient(Scenario::httpClient(), new FastBillCredentials('', ''), 'https://fake.local/api'),
            new InMemorySessionStore(),
            new PluginConfig(),
            new FixedClock('2026-08-01'),
        );
        $result = $router->dispatch('/fastbill help', self::KEY);

        self::assertSame(ResultType::Info, $result->type);
        self::assertStringContainsString('Synafastbill', $result->message);
    }

    public function testUnconfiguredReadReturnsFriendlyError(): void
    {
        $router = RouterFactory::create(
            new FastBillClient(Scenario::httpClient(), new FastBillCredentials('', ''), 'https://fake.local/api'),
            new InMemorySessionStore(),
            new PluginConfig(),
            new FixedClock('2026-08-01'),
        );
        $result = $router->dispatch('/fastbill show overdue', self::KEY);

        self::assertSame(ResultType::Error, $result->type);
        self::assertStringContainsString('e-mail', strtolower($result->message));
    }

    public function testUnknownFallsBackToHelp(): void
    {
        $router = Scenario::router(Scenario::httpClient());
        $result = $router->dispatch('/fastbill make me a sandwich', self::KEY);

        self::assertSame(ResultType::Info, $result->type);
        self::assertStringContainsString("didn't get that", $result->message);
    }

    public function testShowOverdueFindsInvoiceBeyondTheFirstPage(): void
    {
        // 130 outgoing invoices, all paid except one overdue "ImmoMa" invoice
        // sitting on page 2 (index 120). Without pagination it would be missed.
        $customers = [
            ['CUSTOMER_ID' => 7001, 'ORGANIZATION' => 'ImmoMa Verwaltung GmbH', 'CITY' => 'Köln'],
        ];
        $invoices = [];
        for ($i = 0; $i < 130; ++$i) {
            $invoices[] = [
                'INVOICE_ID' => 1000 + $i,
                'INVOICE_NUMBER' => '2026-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'CUSTOMER_ID' => 7001,
                'TYPE' => 'outgoing',
                'INVOICE_DATE' => '2026-05-01',
                'DUE_DATE' => '2026-05-15',
                'PAID_DATE' => '2026-05-10',
                'IS_CANCELED' => '0',
                'TOTAL' => 100,
                'CURRENCY_CODE' => 'EUR',
                'ITEMS' => [],
            ];
        }
        $invoices[120] = [
            'INVOICE_ID' => 7777,
            'INVOICE_NUMBER' => '2026-777',
            'CUSTOMER_ID' => 7001,
            'TYPE' => 'outgoing',
            'INVOICE_DATE' => '2026-04-01',
            'DUE_DATE' => '2026-04-15',
            'PAID_DATE' => '',
            'IS_CANCELED' => '0',
            'TOTAL' => 1500,
            'CURRENCY_CODE' => 'EUR',
            'ITEMS' => [],
        ];

        $router = Scenario::router(new FakeFastBillHttpClient($customers, $invoices));
        $result = $router->dispatch('/fastbill show overdue', self::KEY);

        self::assertSame(1, $result->data['count']);
        self::assertStringContainsString('2026-777', $result->message);
    }

    public function testShowOverdueListsExactlyTheOverdueInvoice(): void
    {
        $router = Scenario::router(Scenario::httpClient());
        $result = $router->dispatch('/fastbill show me overdue payments', self::KEY);

        self::assertSame(ResultType::Info, $result->type);
        self::assertSame(1, $result->data['count']);
        self::assertStringContainsString('2026-042', $result->message);
        self::assertStringNotContainsString('2026-041', $result->message);
    }

    public function testCreateDraftInvoiceSingleMatchFlow(): void
    {
        $http = Scenario::httpClient();
        $router = Scenario::router($http);

        $step1 = $router->dispatch('/fastbill create a new draft invoice for customer XYZ GmbH', self::KEY);
        self::assertSame(ResultType::NeedsConfirmation, $step1->type);
        self::assertStringContainsString('XYZ GmbH', $step1->message);
        self::assertStringContainsString('Consulting', $step1->message);

        $step2 = $router->dispatch('yes', self::KEY);
        self::assertSame(ResultType::Success, $step2->type);
        self::assertArrayHasKey('invoice_id', $step2->data);

        $created = $http->invoice((int) $step2->data['invoice_id']);
        self::assertNotNull($created);
        self::assertSame('draft', $created['TYPE']);
        self::assertSame(5376, $created['CUSTOMER_ID']);
    }

    public function testCreateDraftInvoiceDisambiguationFlow(): void
    {
        $http = Scenario::httpClient();
        $router = Scenario::router($http);

        $step1 = $router->dispatch('/fastbill create invoice for XYZ', self::KEY);
        self::assertSame(ResultType::NeedsConfirmation, $step1->type);
        self::assertCount(2, $step1->data['candidates']);

        $step2 = $router->dispatch('1', self::KEY);
        self::assertSame(ResultType::NeedsConfirmation, $step2->type);
        self::assertStringContainsString('XYZ GmbH', $step2->message);

        $step3 = $router->dispatch('yes', self::KEY);
        self::assertSame(ResultType::Success, $step3->type);
    }

    public function testRegisterPaymentByInvoiceNumber(): void
    {
        $http = Scenario::httpClient();
        $router = Scenario::router($http);

        $step1 = $router->dispatch('/fastbill register payment 1.190,00 EUR am 28.07.2026 Rechnung 2026-042', self::KEY);
        self::assertSame(ResultType::NeedsConfirmation, $step1->type);
        self::assertStringContainsString('2026-042', $step1->message);

        $step2 = $router->dispatch('yes', self::KEY);
        self::assertSame(ResultType::Success, $step2->type);
        $invoice = $http->invoice(4102);
        self::assertNotNull($invoice);
        self::assertSame('2026-07-28', $invoice['PAID_DATE']);
    }

    public function testRegisterPaymentByAmount(): void
    {
        $http = Scenario::httpClient();
        $router = Scenario::router($http);

        $step1 = $router->dispatch('/fastbill register payment Received 500.00 EUR on 2026-07-30', self::KEY);
        self::assertSame(ResultType::NeedsConfirmation, $step1->type);

        $step2 = $router->dispatch('yes', self::KEY);
        self::assertSame(ResultType::Success, $step2->type);
        $invoice = $http->invoice(5001);
        self::assertNotNull($invoice);
        self::assertSame('2026-07-30', $invoice['PAID_DATE']);
    }

    public function testCancelAbortsAndClearsSession(): void
    {
        $router = Scenario::router(Scenario::httpClient());

        $router->dispatch('/fastbill create invoice for XYZ GmbH', self::KEY);
        $cancel = $router->dispatch('no', self::KEY);
        self::assertSame(ResultType::Info, $cancel->type);
        self::assertStringContainsString('cancelled', strtolower($cancel->message));

        $afterCancel = $router->dispatch('yes', self::KEY);
        self::assertStringContainsString('nothing waiting', strtolower($afterCancel->message));
    }

    public function testConfirmWithoutSessionIsSafe(): void
    {
        $router = Scenario::router(Scenario::httpClient());
        $result = $router->dispatch('yes', self::KEY);

        self::assertSame(ResultType::Info, $result->type);
        self::assertStringContainsString('nothing waiting', strtolower($result->message));
    }

    public function testAuthErrorSurfacesAsFriendlyError(): void
    {
        $router = Scenario::router(new FakeFastBillHttpClient(Scenario::customers(), Scenario::invoices(), failAuth: true));
        $result = $router->dispatch('/fastbill show overdue', self::KEY);

        self::assertSame(ResultType::Error, $result->type);
        self::assertStringContainsString('credentials', strtolower($result->message));
    }

    public function testRateLimitSurfacesAsFriendlyError(): void
    {
        $router = Scenario::router(new FakeFastBillHttpClient(Scenario::customers(), Scenario::invoices(), rateLimited: true));
        $result = $router->dispatch('/fastbill show overdue', self::KEY);

        self::assertSame(ResultType::Error, $result->type);
        self::assertStringContainsString('rate-limit', strtolower($result->message));
    }
}
