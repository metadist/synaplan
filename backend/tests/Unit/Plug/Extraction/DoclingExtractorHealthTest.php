<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug\Extraction;

use App\Plug\Extraction\Docling\DoclingClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class DoclingExtractorHealthTest extends TestCase
{
    public function testEmptyUrlIsUnavailable(): void
    {
        $client = new DoclingClient(new MockHttpClient(), new NullLogger(), '', 120000);

        self::assertFalse($client->isEnabled());
        $health = $client->health();
        self::assertFalse($health->available);
        self::assertNotNull($health->reason);
        self::assertStringContainsString('DOCLING_BASE_URL', $health->reason);
    }

    public function testConnectionRefusedIsUnavailable(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new class('Connection refused') extends \RuntimeException implements TransportExceptionInterface {};
        });
        $client = new DoclingClient($http, new NullLogger(), 'http://docling.test', 120000);

        $health = $client->health();
        self::assertFalse($health->available);
        self::assertNotNull($health->reason);
        self::assertStringContainsString('refused', strtolower($health->reason));
    }

    public function testTimeoutIsUnavailable(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new class('Request timed out') extends \RuntimeException implements TransportExceptionInterface {};
        });
        $client = new DoclingClient($http, new NullLogger(), 'http://docling.test', 120000);

        $health = $client->health();
        self::assertFalse($health->available);
        self::assertNotNull($health->reason);
        self::assertStringContainsString('timed out', strtolower($health->reason));
    }

    public function testServerErrorIsUnavailable(): void
    {
        $http = new MockHttpClient([
            new MockResponse('nope', ['http_code' => 503]),
        ]);
        $client = new DoclingClient($http, new NullLogger(), 'http://docling.test', 120000);

        $health = $client->health();
        self::assertFalse($health->available);
        self::assertNotNull($health->reason);
        self::assertStringContainsString('503', $health->reason);
    }

    public function testOkHealthIsAvailableAndCached(): void
    {
        $calls = 0;
        $http = new class($calls) implements HttpClientInterface {
            public function __construct(private int &$calls)
            {
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                ++$this->calls;

                return new MockResponse('{"status":"ok"}', ['http_code' => 200]);
            }

            public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
            {
                throw new \BadMethodCallException('not used');
            }

            public function withOptions(array $options): static
            {
                return $this;
            }
        };
        $client = new DoclingClient($http, new NullLogger(), 'http://docling.test', 120000);

        self::assertTrue($client->health()->available);
        self::assertTrue($client->health()->available);
        self::assertSame(1, $calls);
    }
}
