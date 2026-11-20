<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\Plug\PlugConfigService;
use App\Plug\PlugKeyStore;
use App\Plug\WebSearch\Adapter\SpecStreamAdapter;
use App\Plug\WebSearch\Client\SpecStreamClient;
use App\Plug\WebSearch\WebSearchOptionMapper;
use App\Plug\WebSearch\WebSearchQuery;
use App\Repository\ConfigRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SpecStreamAdapterTest extends TestCase
{
    public function testSearchMapsSourcesPassagesAndAnswerAndSendsExpectedHeadersAndParams(): void
    {
        $capturedMethod = null;
        $capturedUrl = null;
        $capturedOptions = null;

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedMethod, &$capturedUrl, &$capturedOptions): MockResponse {
            $capturedMethod = $method;
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/web_search/specstream/search.json'), [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        $adapter = new SpecStreamAdapter(
            new SpecStreamClient($http, $this->plugKeys('specstream', 'ss-test-key'), $this->plugConfig()),
            $this->plugConfig(),
        );

        $set = $adapter->search(new WebSearchQuery(
            'synaplan',
            [
                'count' => 5,
                'freshness' => 'pw',
                'language' => 'de',
                'country' => 'DE',
                'site' => 'synaplan.com',
            ],
            wantAnswer: true,
        ));

        self::assertSame('GET', $capturedMethod);
        self::assertIsString($capturedUrl);
        self::assertStringStartsWith('https://api.specstream.ai/v1/web-search?', $capturedUrl);
        self::assertIsArray($capturedOptions);

        $headers = array_map('strtolower', $capturedOptions['headers'] ?? []);
        self::assertContains('authorization: bearer ss-test-key', $headers);

        $queryString = (string) parse_url($capturedUrl, PHP_URL_QUERY);
        parse_str($queryString, $params);
        $expectedStart = WebSearchOptionMapper::startPublishedDate('pw');
        self::assertSame('synaplan site:synaplan.com after:'.$expectedStart, $params['q'] ?? null);
        self::assertSame('5', $params['num'] ?? null);
        self::assertSame('true', $params['passages'] ?? null);
        self::assertSame('de', $params['language'] ?? null);
        self::assertSame('DE', $params['country'] ?? null);
        self::assertArrayNotHasKey('speed', $params);

        self::assertCount(1, $set->results);
        self::assertSame('Synaplan', $set->results[0]['title']);
        self::assertSame('https://www.synaplan.com/', $set->results[0]['url']);
        self::assertSame('Open-source AI knowledge platform.', $set->results[0]['description']);
        self::assertSame('2026-09-01', $set->results[0]['age']);
        self::assertSame('Synaplan is an open-source AI knowledge platform for teams and agents.', $set->results[0]['content']);
        self::assertSame('Synaplan is an open-source AI knowledge platform.', $set->answer?->text);
    }

    public function testSearchWithoutWantAnswerUsesFastTierAndOmitsAnswer(): void
    {
        $capturedUrl = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return new MockResponse((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/web_search/specstream/search.json'), [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        $adapter = new SpecStreamAdapter(
            new SpecStreamClient($http, $this->plugKeys('specstream', 'ss-test-key'), $this->plugConfig()),
            $this->plugConfig(),
        );

        $set = $adapter->search(new WebSearchQuery('synaplan', ['country' => 'en', 'search_lang' => 'en'], wantAnswer: false));

        $queryString = (string) parse_url((string) $capturedUrl, PHP_URL_QUERY);
        parse_str($queryString, $params);
        self::assertSame('fast', $params['speed'] ?? null);
        self::assertSame('en', $params['language'] ?? null);
        self::assertSame('US', $params['country'] ?? null);
        self::assertNull($set->answer);
    }

    public function testPassageFallbackPopulatesContentWhenExcerptIsAbsent(): void
    {
        $payload = json_encode([
            'data' => [
                'request_id' => 'ws_2',
                'query' => 'synaplan',
                'sources' => [
                    [
                        'title' => 'Docs',
                        'url' => 'https://docs.synaplan.com/',
                        'snippet' => 'Short snippet.',
                    ],
                ],
                'passages' => [
                    [
                        'url' => 'https://docs.synaplan.com/',
                        'title' => 'Docs',
                        'text' => 'Full passage text from page.',
                    ],
                ],
                'took_ms' => 420,
                'price_usd' => 0.001,
            ],
        ], JSON_THROW_ON_ERROR);

        $http = new MockHttpClient([
            new MockResponse($payload, ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]),
        ]);

        $adapter = new SpecStreamAdapter(
            new SpecStreamClient($http, $this->plugKeys('specstream', 'ss-test-key'), $this->plugConfig()),
            $this->plugConfig(),
        );

        $set = $adapter->search(new WebSearchQuery('synaplan'));
        self::assertSame('Full passage text from page.', $set->results[0]['content'] ?? null);
    }

    #[DataProvider('httpFailureStatusProvider')]
    public function testHttpErrorStatusIsUnavailableOnProbeAndThrowsOnSearch(int $statusCode): void
    {
        $http = new MockHttpClient([
            new MockResponse('error', ['http_code' => $statusCode]),
            new MockResponse('error', ['http_code' => $statusCode]),
        ]);

        $adapter = new SpecStreamAdapter(
            new SpecStreamClient($http, $this->plugKeys('specstream', 'bad-key'), $this->plugConfig()),
            $this->plugConfig(),
        );

        self::assertTrue($adapter->health()->available);

        $probed = $adapter->probe();
        self::assertFalse($probed->available);
        self::assertStringContainsString((string) $statusCode, (string) $probed->reason);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SpecStream search returned HTTP '.$statusCode);
        $adapter->search(new WebSearchQuery('synaplan'));
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function httpFailureStatusProvider(): iterable
    {
        yield 'unauthorized 401' => [401];
        yield 'payment required 402' => [402];
    }

    public function testMissingKeyReportsUnavailableHealth(): void
    {
        $adapter = new SpecStreamAdapter(
            new SpecStreamClient(new MockHttpClient(), $this->plugKeys('specstream', null), $this->plugConfig()),
            $this->plugConfig(),
        );

        self::assertFalse($adapter->health()->available);
        self::assertSame('SpecStream API key is not configured', $adapter->health()->reason);
        self::assertFalse($adapter->probe()->available);
    }

    public function testPlugKeyStoreSupportsSpecstreamAndCountryNormalizerHandlesCodes(): void
    {
        self::assertTrue(PlugKeyStore::isSupported('specstream'));
        self::assertSame('US', WebSearchOptionMapper::country(['country' => 'en']));
        self::assertSame('FR', WebSearchOptionMapper::country(['country' => 'fr']));
        self::assertSame('GB', WebSearchOptionMapper::country(['country' => 'gb']));
        self::assertNull(WebSearchOptionMapper::country(['country' => '']));
    }

    private function plugKeys(string $provider, ?string $value): PlugKeyStore
    {
        $keys = $this->createStub(PlugKeyStore::class);
        $keys->method('getKey')->willReturnCallback(
            static fn (string $requested): ?string => $requested === $provider ? $value : null,
        );

        return $keys;
    }

    private function plugConfig(): PlugConfigService
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);

        return new PlugConfigService($repo);
    }
}
