<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Health\Probe;

use App\AI\Health\Probe\CloudflareModelListProbe;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CloudflareModelListProbeTest extends TestCase
{
    public function testRateLimitedCatalogReturnsReachableInsteadOfFailing(): void
    {
        $attempts = 0;
        $client = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;

            return new MockResponse('{}', ['http_code' => 429]);
        });

        $probe = new CloudflareModelListProbe($client, new NullLogger(), 'acct-1', 'tok-1');
        $result = $probe->probe('cloudflare');

        self::assertTrue($result->isOk());
        self::assertFalse($result->isFailed());
        self::assertFalse($result->listingComplete);
        self::assertSame(2, $attempts);
    }

    public function testTransientEdgeFailureIsRetriedOnceAndSucceeds(): void
    {
        $attempts = 0;
        $client = new MockHttpClient(function () use (&$attempts): MockResponse {
            ++$attempts;
            if (1 === $attempts) {
                return new MockResponse('{}', ['http_code' => 503]);
            }

            return new MockResponse(json_encode([
                'result' => [['name' => '@cf/meta/llama-3.1-8b-instruct']],
            ], \JSON_THROW_ON_ERROR));
        });

        $probe = new CloudflareModelListProbe($client, new NullLogger(), 'acct-1', 'tok-1');
        $result = $probe->probe('cloudflare');

        self::assertTrue($result->isOk());
        self::assertTrue($result->listingComplete);
        self::assertTrue($result->offers('@cf/meta/llama-3.1-8b-instruct'));
        self::assertSame(2, $attempts);
    }
}
