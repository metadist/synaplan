<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime;

use App\Realtime\Channel\WidgetSessionChannel;
use App\Realtime\Publisher\CentrifugoPublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CentrifugoPublisherTest extends TestCase
{
    public function testSkipsWhenDisabled(): void
    {
        $client = new MockHttpClient();
        $publisher = new CentrifugoPublisher(
            httpClient: $client,
            logger: $this->createStub(LoggerInterface::class),
            apiUrl: 'http://centrifugo:8000/api',
            apiKey: 'k',
            enabled: false,
        );

        $publisher->publish(new WidgetSessionChannel('w', 's'), 'message.received', ['hello' => 'world']);

        $this->assertSame(0, $client->getRequestsCount());
    }

    public function testWarnsWhenConfigMissing(): void
    {
        $client = new MockHttpClient();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('REALTIME_API_URL'));

        $publisher = new CentrifugoPublisher(
            httpClient: $client,
            logger: $logger,
            apiUrl: '',
            apiKey: '',
            enabled: true,
        );

        $publisher->publish(new WidgetSessionChannel('w', 's'), 'message.received', []);

        $this->assertSame(0, $client->getRequestsCount());
    }

    public function testSkipsPlaceholderApiKeyInProd(): void
    {
        $client = new MockHttpClient();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('changeme'));

        $publisher = new CentrifugoPublisher(
            httpClient: $client,
            logger: $logger,
            apiUrl: 'http://centrifugo:8000/api',
            apiKey: 'changeme_centrifugo_api_key',
            enabled: true,
            environment: 'prod',
        );

        $publisher->publish(new WidgetSessionChannel('w', 's'), 'message.received', []);

        $this->assertSame(0, $client->getRequestsCount());
    }

    public function testAllowsPlaceholderApiKeyInDev(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode(['result' => []]), ['http_code' => 200]));

        $publisher = new CentrifugoPublisher(
            httpClient: $client,
            logger: $this->createStub(LoggerInterface::class),
            apiUrl: 'http://centrifugo:8000/api',
            apiKey: 'changeme_centrifugo_api_key',
            enabled: true,
            environment: 'dev',
        );

        $publisher->publish(new WidgetSessionChannel('w', 's'), 'message.received', []);

        $this->assertSame(1, $client->getRequestsCount());
    }

    public function testEmitsCanonicalEnvelope(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse(json_encode(['result' => []]), ['http_code' => 200]);
        });

        $publisher = new CentrifugoPublisher(
            httpClient: $client,
            logger: $this->createStub(LoggerInterface::class),
            apiUrl: 'http://centrifugo:8000/api',
            apiKey: 'secret-key',
            enabled: true,
        );

        $publisher->publish(new WidgetSessionChannel('w', 's'), 'message.received', ['hello' => 'world']);

        $this->assertNotNull($captured);
        $this->assertSame('POST', $captured['method']);
        $this->assertSame('http://centrifugo:8000/api', $captured['url']);

        $headers = $captured['options']['headers'];
        $this->assertContains('Content-Type: application/json', $headers);
        $this->assertContains('X-API-Key: secret-key', $headers);

        $body = json_decode($captured['options']['body'], true);
        $this->assertSame('publish', $body['method']);
        $this->assertSame('widget:session.w.s', $body['params']['channel']);
        $this->assertSame('message.received', $body['params']['data']['type']);
        $this->assertIsInt($body['params']['data']['ts']);
        $this->assertSame(['hello' => 'world'], $body['params']['data']['data']);
    }

    public function testNeverThrowsOnHttpFailure(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('boom');
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Centrifugo publish failed'), $this->anything());

        $publisher = new CentrifugoPublisher(
            httpClient: $client,
            logger: $logger,
            apiUrl: 'http://centrifugo:8000/api',
            apiKey: 'k',
            enabled: true,
        );

        $publisher->publish(new WidgetSessionChannel('w', 's'), 'event', []);
    }

    public function testLogsNon2xxResponses(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('error body', ['http_code' => 500]);
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('non-2xx'), $this->callback(static function (array $context): bool {
                return 500 === $context['status'] && 'widget:session.w.s' === $context['channel'];
            }));

        $publisher = new CentrifugoPublisher(
            httpClient: $client,
            logger: $logger,
            apiUrl: 'http://centrifugo:8000/api',
            apiKey: 'k',
            enabled: true,
        );

        $publisher->publish(new WidgetSessionChannel('w', 's'), 'event', []);
    }
}
