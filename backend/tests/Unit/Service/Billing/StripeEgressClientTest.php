<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing;

use App\Service\Billing\StripeEgressClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StripeEgressClientTest extends TestCase
{
    public function testAStripeCallGoesThroughTheSharedClient(): void
    {
        $seen = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = [$method, $url, $options['body'] ?? null];

            return new MockResponse('{"id":"cus_1"}', ['response_headers' => ['content-type' => 'application/json']]);
        });

        [$body, $status] = (new StripeEgressClient($http))->executeRequestWithRetries([
            \CURLOPT_POST => 1,
            \CURLOPT_POSTFIELDS => 'name=Ada',
            \CURLOPT_HTTPHEADER => ['Authorization: Bearer sk_test', 'Expect: '],
            \CURLOPT_TIMEOUT => 5,
        ], 'https://api.stripe.com/v1/customers');

        self::assertSame(['POST', 'https://api.stripe.com/v1/customers', 'name=Ada'], $seen);
        self::assertSame(200, $status);
        self::assertSame('{"id":"cus_1"}', $body);
    }
}
