<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File\Office;

use App\Service\File\Office\OfficeConverterClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OfficeConverterClientTest extends TestCase
{
    public function testIsEnabledIsFalseWhenUrlIsEmpty(): void
    {
        $client = $this->client(new MockHttpClient(), '');

        self::assertFalse($client->isEnabled());
        self::assertSame([], $client->capabilities());
        self::assertNull($client->convert($this->touchInput(), 'pdf'));
    }

    public function testIsEnabledIsFalseWhenUrlIsDisabled(): void
    {
        $http = new MockHttpClient();
        $client = $this->client($http, 'disabled');

        self::assertFalse($client->isEnabled());
        self::assertSame([], $client->capabilities());
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testConvertWritesOutputNextToInputOnSuccess(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured[] = [$method, $url];
            if (str_contains($url, '/hosting/capabilities')) {
                return new MockResponse('{"convert-to":{"available":true}}', [
                    'http_code' => 200,
                    'response_headers' => ['content-type' => 'application/json'],
                ]);
            }

            return new MockResponse('%PDF-1.4 fake', ['http_code' => 200]);
        });

        $input = $this->touchInput();
        $client = $this->client($http);
        $output = $client->convert($input, 'pdf');

        self::assertNotNull($output);
        self::assertFileExists($output);
        self::assertSame(dirname($input), dirname($output));
        self::assertSame('%PDF-1.4 fake', file_get_contents($output));
        self::assertSame('GET', $captured[0][0]);
        self::assertStringEndsWith('/hosting/capabilities', $captured[0][1]);
        self::assertSame('POST', $captured[1][0]);
        self::assertStringEndsWith('/cool/convert-to/pdf', $captured[1][1]);

        @unlink($output);
        @unlink($input);
    }

    public function testConvertReturnsNullOnHttp500WithoutRetry(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function (string $method, string $url) use (&$calls): MockResponse {
            if (str_contains($url, '/hosting/capabilities')) {
                return new MockResponse('{"convert-to":{"available":true}}', [
                    'http_code' => 200,
                    'response_headers' => ['content-type' => 'application/json'],
                ]);
            }
            ++$calls;

            return new MockResponse('boom', ['http_code' => 500]);
        });

        $input = $this->touchInput();
        $client = $this->client($http);

        self::assertNull($client->convert($input, 'pdf'));
        self::assertSame(1, $calls);

        @unlink($input);
    }

    public function testConvertRetriesOnceOnTransportError(): void
    {
        $convertAttempts = 0;
        $http = new MockHttpClient(function (string $method, string $url) use (&$convertAttempts): MockResponse {
            if (str_contains($url, '/hosting/capabilities')) {
                return new MockResponse('{"convert-to":{"available":true}}', [
                    'http_code' => 200,
                    'response_headers' => ['content-type' => 'application/json'],
                ]);
            }
            ++$convertAttempts;
            if (1 === $convertAttempts) {
                throw new TransportException('Timeout');
            }

            return new MockResponse('%PDF-ok', ['http_code' => 200]);
        });

        $input = $this->touchInput();
        $client = $this->client($http);
        $output = $client->convert($input, 'pdf');

        self::assertSame(2, $convertAttempts);
        self::assertNotNull($output);
        self::assertSame('%PDF-ok', file_get_contents((string) $output));

        @unlink((string) $output);
        @unlink($input);
    }

    public function testConvertReturnsNullOnTimeoutAfterRetry(): void
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/hosting/capabilities')) {
                return new MockResponse('{"convert-to":{"available":true}}', [
                    'http_code' => 200,
                    'response_headers' => ['content-type' => 'application/json'],
                ]);
            }

            throw new TransportException('Timeout');
        });

        $input = $this->touchInput();
        $client = $this->client($http);

        self::assertNull($client->convert($input, 'pdf'));

        @unlink($input);
    }

    public function testConvertRejectsUnsupportedFormatWithoutHttp(): void
    {
        $http = new MockHttpClient();
        $client = $this->client($http);

        self::assertNull($client->convert($this->touchInput(), 'gif'));
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testCapabilitiesAreCachedPerProcess(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"convert-to":{"available":true}}', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        $client = $this->client($http);
        $first = $client->capabilities();
        $second = $client->capabilities();

        self::assertSame(1, $calls);
        self::assertSame($first, $second);
        self::assertTrue($first['convert-to']['available']);
    }

    private function client(MockHttpClient $http, string $url = 'http://collabora:9980'): OfficeConverterClient
    {
        return new OfficeConverterClient($http, new NullLogger(), $url, 60000);
    }

    private function touchInput(): string
    {
        $path = sys_get_temp_dir().'/office-convert-'.bin2hex(random_bytes(4)).'.docx';
        file_put_contents($path, 'PK fake-docx');

        return $path;
    }
}
