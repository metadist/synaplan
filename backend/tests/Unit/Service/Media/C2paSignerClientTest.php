<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Service\Media\C2paSignerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class C2paSignerClientTest extends TestCase
{
    public function testEmptyUrlDoesNotCallTheSidecar(): void
    {
        $client = new C2paSignerClient(new MockHttpClient(), '', 'token');

        self::assertNull($client->sign('png', 'a.png', 'model', false));
        self::assertFalse($client->isConfigured());
    }

    public function testEmptyTokenDoesNotCallTheSidecar(): void
    {
        $client = new C2paSignerClient(new MockHttpClient(), 'http://c2pa-signer:8787', '');

        self::assertNull($client->sign('png', 'a.png', 'model', false));
        self::assertFalse($client->isConfigured());
    }

    public function testSuccessfulSignReturnsTheDecodedBytes(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('http://c2pa-signer:8787/sign', $url);
            self::assertContains('Authorization: Bearer secret-token', $options['normalized_headers']['authorization'] ?? []);

            return new MockResponse(json_encode(['bytes' => base64_encode('signed')], \JSON_THROW_ON_ERROR));
        });
        $client = new C2paSignerClient($http, 'http://c2pa-signer:8787', 'secret-token');

        self::assertSame('signed', $client->sign('png', 'a.png', 'model', true));
    }

    public function testFailureReturnsNull(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('no', ['http_code' => 503]);
        });
        $client = new C2paSignerClient($http, 'http://c2pa-signer:8787', 'secret-token');

        self::assertNull($client->sign('png', 'a.png', 'model', false));
    }
}
