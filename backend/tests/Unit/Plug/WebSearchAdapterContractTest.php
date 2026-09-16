<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\AI\Credential\ProviderKeyStore;
use App\Plug\PlugConfigService;
use App\Plug\PlugKeyStore;
use App\Plug\WebSearch\Adapter\ExaAdapter;
use App\Plug\WebSearch\Adapter\FirecrawlAdapter;
use App\Plug\WebSearch\Adapter\PerplexityAdapter;
use App\Plug\WebSearch\Adapter\SearxngAdapter;
use App\Plug\WebSearch\Adapter\TavilyAdapter;
use App\Plug\WebSearch\Client\ExaClient;
use App\Plug\WebSearch\Client\FirecrawlClient;
use App\Plug\WebSearch\Client\PerplexitySearchClient;
use App\Plug\WebSearch\Client\SearxngClient;
use App\Plug\WebSearch\Client\TavilyClient;
use App\Plug\WebSearch\WebSearchQuery;
use App\Repository\ConfigRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebSearchAdapterContractTest extends TestCase
{
    public function testSearxngMapsFixtureTitles(): void
    {
        $adapter = new SearxngAdapter($this->searxngClient());
        $set = $adapter->search(new WebSearchQuery('synaplan'));

        $this->assertSame('Synaplan', $set->results[0]['title'] ?? null);
        $this->assertTrue($adapter->capabilities()->language);
        $this->assertTrue($adapter->health()->available);
    }

    public function testTavilyMapsFixtureTitles(): void
    {
        $adapter = new TavilyAdapter($this->tavilyClient());
        $set = $adapter->search(new WebSearchQuery('synaplan'));

        $this->assertSame('Synaplan', $set->results[0]['title'] ?? null);
        $this->assertTrue($adapter->capabilities()->fullContent);
        $this->assertTrue($adapter->health()->available);
    }

    public function testExaMapsFixtureTitles(): void
    {
        $adapter = new ExaAdapter($this->exaClient(), $this->plugConfig());
        $set = $adapter->search(new WebSearchQuery('synaplan'));

        $this->assertSame('Synaplan', $set->results[0]['title'] ?? null);
        $this->assertTrue($adapter->capabilities()->siteFilter);
    }

    public function testFirecrawlPutsMarkdownInContent(): void
    {
        $adapter = new FirecrawlAdapter($this->firecrawlClient(), $this->plugConfig());
        $set = $adapter->search(new WebSearchQuery('synaplan'));

        $this->assertSame('Synaplan', $set->results[0]['title'] ?? null);
        $this->assertStringContainsString('Open-source', (string) ($set->results[0]['content'] ?? ''));
        $this->assertTrue($adapter->capabilities()->fullContent);
    }

    public function testPerplexityMapsFixtureAndSkipsAnswerWhenUnbound(): void
    {
        $adapter = new PerplexityAdapter(
            $this->perplexityClient(),
            $this->emptyConfigRepo(),
            new NullLogger(),
        );
        $set = $adapter->search(new WebSearchQuery('synaplan', [], true));

        $this->assertSame('Synaplan', $set->results[0]['title'] ?? null);
        $this->assertNull($set->answer);
        $this->assertTrue($adapter->capabilities()->answer);
    }

    public function testExaGarbageKeyIsUnavailableOnProbe(): void
    {
        $http = new MockHttpClient([new MockResponse('unauthorized', ['http_code' => 401])]);
        $adapter = new ExaAdapter(
            new ExaClient($http, $this->plugKeys('exa'), $this->plugConfig()),
            $this->plugConfig(),
        );

        self::assertTrue($adapter->health()->available, 'A stored key is still configured');
        $probed = $adapter->probe();
        self::assertFalse($probed->available);
        self::assertStringContainsString('401', (string) $probed->reason);
    }

    public function testHttpErrorBecomesExceptionForRegistryFallback(): void
    {
        $http = new MockHttpClient([new MockResponse('nope', ['http_code' => 500])]);
        $adapter = new TavilyAdapter(new TavilyClient($http, $this->plugKeys('tavily'), $this->plugConfig()));

        $this->expectException(\RuntimeException::class);
        $adapter->search(new WebSearchQuery('synaplan'));
    }

    private function searxngClient(): SearxngClient
    {
        return new SearxngClient(
            $this->mockClient('searxng/search.json'),
            $this->plugConfig(),
            'http://searxng.test',
        );
    }

    private function tavilyClient(): TavilyClient
    {
        return new TavilyClient($this->mockClient('tavily/search.json'), $this->plugKeys('tavily'), $this->plugConfig());
    }

    private function exaClient(): ExaClient
    {
        return new ExaClient($this->mockClient('exa/search.json'), $this->plugKeys('exa'), $this->plugConfig());
    }

    private function firecrawlClient(): FirecrawlClient
    {
        return new FirecrawlClient($this->mockClient('firecrawl/search.json'), $this->plugKeys('firecrawl'), $this->plugConfig());
    }

    private function perplexityClient(): PerplexitySearchClient
    {
        $keys = $this->createMock(ProviderKeyStore::class);
        $keys->method('getKey')->with('perplexity')->willReturn('test-key');

        return new PerplexitySearchClient($this->mockClient('perplexity/search.json'), $keys, $this->plugConfig());
    }

    private function mockClient(string $relative): MockHttpClient
    {
        $path = dirname(__DIR__, 2).'/Fixtures/web_search/'.$relative;

        return new MockHttpClient([
            new MockResponse((string) file_get_contents($path), [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/json'],
            ]),
        ]);
    }

    private function plugKeys(string $provider): PlugKeyStore
    {
        $keys = $this->createMock(PlugKeyStore::class);
        $keys->method('getKey')->with($provider)->willReturn('test-key');

        return $keys;
    }

    private function plugConfig(): PlugConfigService
    {
        return new PlugConfigService($this->emptyConfigRepo());
    }

    private function emptyConfigRepo(): ConfigRepository
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);

        return $repo;
    }
}
