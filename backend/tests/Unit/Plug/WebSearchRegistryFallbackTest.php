<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchFallbackMetrics;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;
use App\Plug\WebSearch\WebSearchRegistry;
use App\Repository\ConfigRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class WebSearchRegistryFallbackTest extends TestCase
{
    public function testFallbackRunsOnceAndRecordsMetric(): void
    {
        $metrics = new WebSearchFallbackMetrics(new NullLogger());
        $registry = new WebSearchRegistry(
            [
                $this->provider('searxng', available: false),
                $this->provider('brave', available: true, results: [
                    ['title' => 'Brave hit', 'url' => 'https://example.test'],
                ]),
            ],
            new PlugConfigService($this->repo([
                [0, PlugConfigService::KEY_WEB_SEARCH_PROVIDER, 'searxng'],
                [0, PlugConfigService::KEY_WEB_SEARCH_FALLBACK, 'brave'],
            ])),
            $metrics,
        );

        $set = $registry->search(new WebSearchQuery('synaplan'));

        $this->assertSame('Brave hit', $set->results[0]['title'] ?? null);
        $this->assertSame('searxng', $set->meta['fellBackFrom'] ?? null);
        $this->assertSame(1, $metrics->count('searxng', 'brave'));
    }

    public function testNoLoopWhenFallbackEqualsActive(): void
    {
        $metrics = new WebSearchFallbackMetrics(new NullLogger());
        $calls = 0;
        $boom = $this->throwingProvider('brave', $calls);
        $registry = new WebSearchRegistry(
            [$boom],
            new PlugConfigService($this->repo([
                [0, PlugConfigService::KEY_WEB_SEARCH_PROVIDER, 'brave'],
                [0, PlugConfigService::KEY_WEB_SEARCH_FALLBACK, 'brave'],
            ])),
            $metrics,
        );

        $set = $registry->search(new WebSearchQuery('synaplan'));

        $this->assertSame([], $set->results);
        $this->assertSame(0, $metrics->total());
        $this->assertSame(1, $calls);
    }

    public function testExceptionOnPrimaryUsesFallback(): void
    {
        $calls = 0;
        $metrics = new WebSearchFallbackMetrics(new NullLogger());
        $registry = new WebSearchRegistry(
            [
                $this->throwingProvider('tavily', $calls),
                $this->provider('brave', available: true, results: [
                    ['title' => 'Brave hit', 'url' => 'https://example.test'],
                ]),
            ],
            new PlugConfigService($this->repo([
                [0, PlugConfigService::KEY_WEB_SEARCH_PROVIDER, 'tavily'],
                [0, PlugConfigService::KEY_WEB_SEARCH_FALLBACK, 'brave'],
            ])),
            $metrics,
        );

        $set = $registry->search(new WebSearchQuery('synaplan'));

        $this->assertSame('Brave hit', $set->results[0]['title'] ?? null);
        $this->assertSame(1, $metrics->count('tavily', 'brave'));
        $this->assertSame(1, $calls);
    }

    public function testPrimarySuccessIsLoggedEvenWithoutFallback(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'synaplan_plugs_web_search_resolved',
            self::equalTo([
                'provider' => 'brave',
                'results' => 1,
                'fellBackFrom' => null,
                'empty' => false,
            ]),
        );
        $logger->expects(self::never())->method('warning');

        $registry = new WebSearchRegistry(
            [
                $this->provider('brave', available: true, results: [
                    ['title' => 'Brave hit', 'url' => 'https://example.test'],
                ]),
            ],
            new PlugConfigService($this->repo([
                [0, PlugConfigService::KEY_WEB_SEARCH_PROVIDER, 'brave'],
            ])),
            new WebSearchFallbackMetrics($logger),
        );

        $set = $registry->search(new WebSearchQuery('synaplan'));
        $this->assertSame('brave', $set->meta['provider'] ?? null);
    }

    public function testFailedPrimaryWithoutFallbackIsLoggedAsEmpty(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'synaplan_plugs_web_search_failed',
            self::equalTo([
                'provider' => 'exa',
                'reason' => 'provider down',
            ]),
        );
        $logger->expects(self::once())->method('info')->with(
            'synaplan_plugs_web_search_resolved',
            self::equalTo([
                'provider' => 'exa',
                'results' => 0,
                'fellBackFrom' => null,
                'empty' => true,
            ]),
        );

        $calls = 0;
        $registry = new WebSearchRegistry(
            [$this->throwingProvider('exa', $calls)],
            new PlugConfigService($this->repo([
                [0, PlugConfigService::KEY_WEB_SEARCH_PROVIDER, 'exa'],
            ])),
            new WebSearchFallbackMetrics($logger),
        );

        $set = $registry->search(new WebSearchQuery('synaplan'));
        $this->assertSame([], $set->results);
        $this->assertSame('exa', $set->meta['provider'] ?? null);
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
    private function provider(string $key, bool $available, array $results = []): WebSearchProviderInterface
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

    private function throwingProvider(string $key, int &$calls): WebSearchProviderInterface
    {
        return new class($key, $calls) implements WebSearchProviderInterface {
            public function __construct(
                private string $providerKey,
                private int &$calls,
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
                ++$this->calls;
                throw new \RuntimeException('provider down');
            }

            public function health(): PlugHealth
            {
                return PlugHealth::available();
            }

            public function probe(): PlugHealth
            {
                return $this->health();
            }
        };
    }
}
