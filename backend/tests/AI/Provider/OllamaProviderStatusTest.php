<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\OllamaProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

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
}
