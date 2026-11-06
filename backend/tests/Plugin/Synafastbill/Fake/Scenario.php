<?php

declare(strict_types=1);

namespace App\Tests\Plugin\Synafastbill\Fake;

use Plugin\Synafastbill\Api\FastBillClient;
use Plugin\Synafastbill\Api\FastBillCredentials;
use Plugin\Synafastbill\Command\ClockInterface;
use Plugin\Synafastbill\Command\CommandRouter;
use Plugin\Synafastbill\Command\PluginConfig;
use Plugin\Synafastbill\Command\RouterFactory;
use Plugin\Synafastbill\Command\Session\InMemorySessionStore;
use Plugin\Synafastbill\Intent\AiCommandExtractorInterface;

/**
 * Deterministic sample data + wiring, shared by the unit tests and the offline
 * example so both exercise the exact same pipeline the controller uses.
 */
final class Scenario
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function customers(): array
    {
        return [
            ['CUSTOMER_ID' => 5376, 'CUSTOMER_NUMBER' => 'K-1001', 'CUSTOMER_TYPE' => 'business', 'ORGANIZATION' => 'XYZ GmbH', 'FIRST_NAME' => '', 'LAST_NAME' => '', 'CITY' => 'Berlin', 'EMAIL' => 'billing@xyz.example'],
            ['CUSTOMER_ID' => 5377, 'CUSTOMER_NUMBER' => 'K-1002', 'CUSTOMER_TYPE' => 'business', 'ORGANIZATION' => 'XYZ Retail AG', 'FIRST_NAME' => '', 'LAST_NAME' => '', 'CITY' => 'Hamburg', 'EMAIL' => 'ap@xyz-retail.example'],
            ['CUSTOMER_ID' => 6001, 'CUSTOMER_NUMBER' => 'K-2001', 'CUSTOMER_TYPE' => 'consumer', 'ORGANIZATION' => '', 'FIRST_NAME' => 'Jane', 'LAST_NAME' => 'Doe', 'CITY' => 'Munich', 'EMAIL' => 'jane.doe@example.com'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function invoices(): array
    {
        $consultingItems = [
            ['DESCRIPTION' => 'Consulting (May)', 'QUANTITY' => 10, 'UNIT' => 'hours', 'UNIT_PRICE' => 100, 'VAT_PERCENT' => 19],
        ];

        return [
            // Paid invoice for XYZ GmbH — not overdue.
            ['INVOICE_ID' => 4101, 'INVOICE_NUMBER' => '2026-041', 'CUSTOMER_ID' => 5376, 'TYPE' => 'outgoing', 'INVOICE_DATE' => '2026-05-01', 'DUE_DATE' => '2026-05-15', 'PAID_DATE' => '2026-05-10', 'IS_CANCELED' => '0', 'SUB_TOTAL' => 1000, 'VAT_TOTAL' => 190, 'TOTAL' => 1190, 'CURRENCY_CODE' => 'EUR', 'ITEMS' => $consultingItems],
            // Latest invoice for XYZ GmbH — unpaid + past due => overdue, and the copy source.
            ['INVOICE_ID' => 4102, 'INVOICE_NUMBER' => '2026-042', 'CUSTOMER_ID' => 5376, 'TYPE' => 'outgoing', 'INVOICE_DATE' => '2026-06-01', 'DUE_DATE' => '2026-06-15', 'PAID_DATE' => '', 'IS_CANCELED' => '0', 'SUB_TOTAL' => 1000, 'VAT_TOTAL' => 190, 'TOTAL' => 1190, 'CURRENCY_CODE' => 'EUR', 'ITEMS' => $consultingItems],
            // Unpaid but not yet due — used for amount-only payment matching.
            ['INVOICE_ID' => 5001, 'INVOICE_NUMBER' => '2026-050', 'CUSTOMER_ID' => 6001, 'TYPE' => 'outgoing', 'INVOICE_DATE' => '2026-07-20', 'DUE_DATE' => '2026-08-20', 'PAID_DATE' => '', 'IS_CANCELED' => '0', 'SUB_TOTAL' => 420.17, 'VAT_TOTAL' => 79.83, 'TOTAL' => 500, 'CURRENCY_CODE' => 'EUR', 'ITEMS' => []],
            // A draft — must be ignored by overdue + payment matching.
            ['INVOICE_ID' => 9001, 'INVOICE_NUMBER' => '', 'CUSTOMER_ID' => 5377, 'TYPE' => 'draft', 'INVOICE_DATE' => '2026-07-01', 'DUE_DATE' => '2026-07-10', 'PAID_DATE' => '', 'IS_CANCELED' => '0', 'SUB_TOTAL' => 200, 'VAT_TOTAL' => 38, 'TOTAL' => 238, 'CURRENCY_CODE' => 'EUR', 'ITEMS' => []],
        ];
    }

    public static function httpClient(bool $failAuth = false, bool $rateLimited = false): FakeFastBillHttpClient
    {
        return new FakeFastBillHttpClient(self::customers(), self::invoices(), $failAuth, $rateLimited);
    }

    public static function client(FakeFastBillHttpClient $http): FastBillClient
    {
        return new FastBillClient($http, new FastBillCredentials('demo@synaplan.com', 'test-key-0000'), 'https://fake.local/api');
    }

    public static function router(
        FakeFastBillHttpClient $http,
        ?ClockInterface $clock = null,
        ?PluginConfig $config = null,
        ?AiCommandExtractorInterface $aiExtractor = null,
    ): CommandRouter {
        return RouterFactory::create(
            self::client($http),
            new InMemorySessionStore(),
            $config ?? new PluginConfig(),
            $clock ?? new FixedClock('2026-08-01'),
            $aiExtractor,
        );
    }
}
