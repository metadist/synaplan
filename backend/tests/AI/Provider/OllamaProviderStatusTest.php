<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\OllamaProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OllamaProviderStatusTest extends TestCase
{
    public function testEmptyBaseUrlReportsNotConfiguredWithoutARequest(): void
    {
        $provider = new OllamaProvider(new NullLogger(), '', new MockHttpClient());

        self::assertSame(
            ['healthy' => false, 'error' => 'Server URL not configured'],
            $provider->getStatus(),
        );
        self::assertFalse($provider->isAvailable());
    }

    public function testWhitespaceBaseUrlCountsAsNotConfigured(): void
    {
        $provider = new OllamaProvider(new NullLogger(), '   ', new MockHttpClient());

        self::assertSame('Server URL not configured', $provider->getStatus()['error'] ?? null);
        self::assertFalse($provider->isAvailable());
    }

    public function testStatusChecksShareOneBoundedProbePerRequest(): void
    {
        $requests = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = $options;

            return new MockResponse((string) json_encode(['models' => [['name' => 'llama3:8b', 'size' => 5]]]));
        });
        $provider = new OllamaProvider(new NullLogger(), 'http://ollama:11434', $client);

        self::assertTrue($provider->getStatus()['healthy']);
        self::assertTrue($provider->isAvailable());
        self::assertSame(['llama3:8b'], $provider->getAvailableModels());
        self::assertTrue($provider->hasModel('llama3:8b'));

        self::assertCount(1, $requests);
        self::assertSame(2.0, $requests[0]['timeout']);
        self::assertSame(2.0, $requests[0]['max_duration']);

        $provider->reset();
        $provider->isAvailable();
        self::assertCount(2, $requests);
    }

    public function testUnreachableServerIsProbedOnceAndReportedUnavailable(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            throw new TransportException('Idle timeout reached for "http://ollama:11434/api/tags".');
        });
        $provider = new OllamaProvider(new NullLogger(), 'http://ollama:11434', $client);

        self::assertFalse($provider->getStatus()['healthy']);
        self::assertFalse($provider->isAvailable());
        self::assertSame([], $provider->getAvailableModels());
        self::assertSame(1, $calls);
    }
}
