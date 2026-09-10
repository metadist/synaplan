<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\WebSearch\Adapter\SearxngAdapter;
use App\Plug\WebSearch\Client\SearxngClient;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchFallbackMetrics;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;
use App\Plug\WebSearch\WebSearchRegistry;
use App\Repository\ConfigRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SearxngAdapterHealthTest extends TestCase
{
    public function testConfiguredButUnreachableSidecarIsUnavailableOnProbe(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new class('Connection refused') extends \RuntimeException implements \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface {};
        });
        $adapter = new SearxngAdapter(new SearxngClient(
            $http,
            new PlugConfigService($this->repo([])),
            'http://searxng.test',
        ));

        self::assertTrue($adapter->health()->available, 'A set URL is still configured');
        $probed = $adapter->probe();
        self::assertFalse($probed->available);
        self::assertStringContainsString('refused', strtolower((string) $probed->reason));
    }

    public function testUnresolvedHostIsNotReportedAsConnectionRefused(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new class('Could not resolve host searxng.internal') extends \RuntimeException implements \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface {};
        });
        $adapter = new SearxngAdapter(new SearxngClient(
            $http,
            new PlugConfigService($this->repo([])),
            'http://searxng.internal',
        ));

        $probed = $adapter->probe();
        self::assertFalse($probed->available);
        self::assertStringContainsString('could not resolve host', strtolower((string) $probed->reason));
        self::assertStringNotContainsString('connection refused', strtolower((string) $probed->reason));
    }

    public function testHtmlProbeBodyIsNotReportedAvailable(): void
    {
        $http = new MockHttpClient([new MockResponse(
            '<html>login</html>',
            ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']],
        )]);
        $adapter = new SearxngAdapter(new SearxngClient(
            $http,
            new PlugConfigService($this->repo([])),
            'http://searxng.test',
        ));

        $probed = $adapter->probe();
        self::assertFalse($probed->available);
        self::assertStringContainsString('no results array', strtolower((string) $probed->reason));
    }

    public function testJsonWithoutResultsArrayIsNotReportedAvailable(): void
    {
        $http = new MockHttpClient([new MockResponse(
            '{"error":"ok"}',
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        )]);
        $adapter = new SearxngAdapter(new SearxngClient(
            $http,
            new PlugConfigService($this->repo([])),
            'http://searxng.test',
        ));

        $probed = $adapter->probe();
        self::assertFalse($probed->available);
        self::assertStringContainsString('no results array', strtolower((string) $probed->reason));
    }

    public function testEmptyBaseUrlIsUnavailable(): void
    {
        $adapter = new SearxngAdapter(new SearxngClient(
            new MockHttpClient(),
            new PlugConfigService($this->repo([])),
            '',
        ));

        $this->assertFalse($adapter->health()->available);
        $this->assertStringContainsString('SEARXNG_BASE_URL', (string) $adapter->health()->reason);
    }

    public function testEmptyUrlFallsBackOrReturnsEmptySet(): void
    {
        $searx = new SearxngAdapter(new SearxngClient(
            new MockHttpClient(),
            new PlugConfigService($this->repo([])),
            '',
        ));
        $brave = $this->provider('brave', available: true, results: [
            ['title' => 'Fallback hit', 'url' => 'https://example.test'],
        ]);

        $withFallback = new WebSearchRegistry(
            [$searx, $brave],
            new PlugConfigService($this->repo([
                [0, PlugConfigService::KEY_WEB_SEARCH_PROVIDER, 'searxng'],
                [0, PlugConfigService::KEY_WEB_SEARCH_FALLBACK, 'brave'],
            ])),
            new WebSearchFallbackMetrics(new NullLogger()),
        );
        $set = $withFallback->search(new WebSearchQuery('synaplan'));
        $this->assertSame('Fallback hit', $set->results[0]['title'] ?? null);
        $this->assertSame('searxng', $set->meta['fellBackFrom'] ?? null);

        $noFallback = new WebSearchRegistry(
            [$searx],
            new PlugConfigService($this->repo([
                [0, PlugConfigService::KEY_WEB_SEARCH_PROVIDER, 'searxng'],
            ])),
        );
        $empty = $noFallback->search(new WebSearchQuery('synaplan'));
        $this->assertSame([], $empty->results);
    }

    /**
     * @param list<array{0: int, 1: string, 2: string}> $rows
     */
    private function repo(array $rows): ConfigRepository
    {
        $map = [];
        foreach ($rows as [$ownerId, $setting, $value]) {
            $map[$ownerId][PlugConfigService::CONFIG_GROUP][$setting] = $value;
        }
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static function (int $ownerId, string $group, string $setting) use ($map): ?string {
                return $map[$ownerId][$group][$setting] ?? null;
            },
        );

        return $repo;
    }

    /**
     * @param list<array<string, mixed>> $results
     */
    private function provider(string $key, bool $available, array $results): WebSearchProviderInterface
    {
        return new class($key, $available, $results) implements WebSearchProviderInterface {
            /**
             * @param list<array<string, mixed>> $results
             */
            public function __construct(
                private string $providerKey,
                private bool $available,
                private array $results,
            ) {
            }

            public function key(): string
            {
                return $this->providerKey;
            }

            public function descriptor(): PlugDescriptor
            {
                return new PlugDescriptor($this->providerKey, $this->providerKey, '', [], 'test');
            }

            public function capabilities(): WebSearchCapabilities
            {
                return WebSearchCapabilities::brave();
            }

            public function search(WebSearchQuery $query): SearchResultSet
            {
                return SearchResultSet::fromLegacyArray([
                    'query' => $query->query,
                    'results' => $this->results,
                ]);
            }

            public function health(): PlugHealth
            {
                return $this->available
                    ? PlugHealth::available()
                    : PlugHealth::unavailable($this->providerKey.' unavailable');
            }

            public function probe(): PlugHealth
            {
                return $this->health();
            }
        };
    }
}
