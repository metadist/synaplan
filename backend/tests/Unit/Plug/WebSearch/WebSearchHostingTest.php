<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug\WebSearch;

use App\Plug\WebSearch\WebSearchHosting;
use App\Plug\WebSearch\WebSearchProviderInterface;
use PHPUnit\Framework\TestCase;

final class WebSearchHostingTest extends TestCase
{
    public function testOnlySearxngIsSelfHosted(): void
    {
        $this->assertTrue(WebSearchHosting::selfHosted('searxng'));
        $this->assertTrue(WebSearchHosting::selfHosted('SearXNG'));
        foreach (['brave', 'tavily', 'exa', 'firecrawl', 'perplexity'] as $provider) {
            $this->assertFalse(WebSearchHosting::selfHosted($provider), $provider);
            $this->assertSame('US', WebSearchHosting::country($provider), $provider);
        }
        $this->assertFalse(WebSearchHosting::selfHosted('unknown'));
    }

    public function testSelfHostedAndUnknownProvidersHaveNoCountry(): void
    {
        $this->assertNull(WebSearchHosting::country('searxng'));
        $this->assertNull(WebSearchHosting::country('unknown'));
    }

    public function testEveryShippedAdapterHasAHostingEntry(): void
    {
        $files = glob(dirname(__DIR__, 4).'/src/Plug/WebSearch/Adapter/*Adapter.php') ?: [];
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            /** @var class-string $class */
            $class = 'App\\Plug\\WebSearch\\Adapter\\'.basename($file, '.php');
            $reflection = new \ReflectionClass($class);
            if (!$reflection->implementsInterface(WebSearchProviderInterface::class)) {
                continue;
            }
            /** @var WebSearchProviderInterface $adapter */
            $adapter = $reflection->newInstanceWithoutConstructor();
            $key = $adapter->key();
            if ('test' === $key) {
                continue;
            }

            $this->assertArrayHasKey($key, WebSearchHosting::PROVIDERS, $class.' has no hosting entry.');
        }
    }
}
