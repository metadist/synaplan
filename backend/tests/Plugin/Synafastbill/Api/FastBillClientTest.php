<?php

declare(strict_types=1);

namespace App\Tests\Plugin\Synafastbill\Api;

use App\Tests\Plugin\Synafastbill\Fake\FakeFastBillHttpClient;
use App\Tests\Plugin\Synafastbill\Fake\Scenario;
use PHPUnit\Framework\TestCase;
use Plugin\Synafastbill\Api\Exception\FastBillApiException;
use Plugin\Synafastbill\Dto\InvoiceItem;

final class FastBillClientTest extends TestCase
{
    public function testGetCustomersBuildsRequestAndParsesResponse(): void
    {
        $http = Scenario::httpClient();
        $client = Scenario::client($http);

        $customers = $client->getCustomers(['TERM' => 'XYZ'], 20);

        self::assertCount(2, $customers);
        self::assertSame('customer.get', $http->requests[0]['SERVICE']);
        self::assertSame('XYZ', $http->requests[0]['FILTER']['TERM']);
        self::assertSame('XYZ GmbH', $customers[0]->displayName());
    }

    public function testListLimitIsCappedAtHundred(): void
    {
        $http = Scenario::httpClient();
        Scenario::client($http)->getInvoices([], 500);

        self::assertSame(100, $http->requests[0]['LIMIT']);
    }

    public function testCreateDraftInvoiceSendsItems(): void
    {
        $http = Scenario::httpClient();
        $client = Scenario::client($http);

        $id = $client->createDraftInvoice(5376, [new InvoiceItem('Consulting', 5, 'hours', 120.0, 19.0)], 'EUR');

        self::assertGreaterThan(0, $id);
        $request = $http->requests[0];
        self::assertSame('invoice.create', $request['SERVICE']);
        self::assertSame(5376, $request['DATA']['CUSTOMER_ID']);
        self::assertSame('Consulting', $request['DATA']['ITEMS'][0]['DESCRIPTION']);
        $created = $http->invoice($id);
        self::assertNotNull($created);
        self::assertSame('draft', $created['TYPE']);
    }

    public function testAuthenticationErrorIsTyped(): void
    {
        $client = Scenario::client(new FakeFastBillHttpClient([], [], failAuth: true));

        try {
            $client->getCustomers();
            self::fail('Expected FastBillApiException');
        } catch (FastBillApiException $e) {
            self::assertTrue($e->isAuthenticationError());
            self::assertStringNotContainsString('test-key', $e->getMessage());
        }
    }

    public function testRateLimitIsTyped(): void
    {
        $client = Scenario::client(new FakeFastBillHttpClient([], [], rateLimited: true));

        try {
            $client->getInvoices();
            self::fail('Expected FastBillApiException');
        } catch (FastBillApiException $e) {
            self::assertTrue($e->isRateLimited());
        }
    }
}
