<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing;

use App\Service\Billing\StripeEgressClient;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\UnexpectedValueException;
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

    public function testAFileUploadIsRefusedInsteadOfOpeningCurl(): void
    {
        $called = false;
        $http = new MockHttpClient(function () use (&$called): MockResponse {
            $called = true;

            return new MockResponse('no');
        });

        try {
            (new StripeEgressClient($http))->executeRequestWithRetries([
                \CURLOPT_POST => 1,
                \CURLOPT_POSTFIELDS => ['file' => 'bytes'],
            ], 'https://api.stripe.com/v1/files');
            self::fail('A multipart Stripe body must not leave through another client.');
        } catch (UnexpectedValueException) {
            self::assertFalse($called);
        }
    }

    public function testARetryableStatusIsFollowedByTheNextAttempt(): void
    {
        $count = 0;
        $http = new MockHttpClient(function () use (&$count): MockResponse {
            ++$count;

            return 1 === $count
                ? new MockResponse('busy', ['http_code' => 503])
                : new MockResponse('{"id":"cus_2"}', ['http_code' => 200]);
        });

        [$body, $status] = (new StripeEgressClient($http))->executeRequestWithRetries([
            \CURLOPT_HTTPGET => 1,
            \CURLOPT_TIMEOUT => 5,
        ], 'https://api.stripe.com/v1/customers/cus_2', 1);

        self::assertSame(200, $status);
        self::assertSame('{"id":"cus_2"}', $body);
        self::assertSame(2, $count);
    }
}
