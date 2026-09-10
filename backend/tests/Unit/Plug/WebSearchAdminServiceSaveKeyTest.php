<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\AI\Credential\ProviderKeyStore;
use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\PlugKeyStore;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchAdminHealth;
use App\Plug\WebSearch\WebSearchAdminService;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchHealthCache;
use App\Plug\WebSearch\WebSearchLiveProbeInterface;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;
use App\Plug\WebSearch\WebSearchRegistry;
use App\Repository\ConfigRepository;
use App\Service\Search\BraveSearchService;
use PHPUnit\Framework\TestCase;

final class WebSearchAdminServiceSaveKeyTest extends TestCase
{
    public function testRejectedWebSearchKeyIsRolledBack(): void
    {
        $plugKeys = $this->createMock(PlugKeyStore::class);
        $plugKeys->method('supports')->with('exa')->willReturn(true);
        $plugKeys->method('getStatus')->willReturn([
            'configured' => false,
            'source' => 'none',
            'origin' => null,
            'maskedKey' => '',
        ]);
        $plugKeys->method('getKey')->willReturn(null);
        $plugKeys->expects(self::once())->method('saveKey')->with('exa', 'garbage');
        $plugKeys->expects(self::once())->method('deleteKey')->with('exa');

        $service = $this->service(
            $this->adapter('exa', PlugHealth::unavailable('Exa search returned HTTP 401')),
            $plugKeys,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API key was not stored');
        $service->saveKey('exa', 'garbage');
    }

    public function testPreviousDbKeyIsRestoredWhenProbeFails(): void
    {
        $saved = [];
        $plugKeys = $this->createMock(PlugKeyStore::class);
        $plugKeys->method('supports')->willReturn(true);
        $plugKeys->method('getStatus')->willReturn([
            'configured' => true,
            'source' => 'db',
            'origin' => PlugKeyStore::ORIGIN_ENV,
            'maskedKey' => '••••',
        ]);
        $plugKeys->method('getKey')->willReturn('old-good-key');
        $plugKeys->expects(self::exactly(2))->method('saveKey')->willReturnCallback(
            static function (string $provider, string $key, string $origin = PlugKeyStore::ORIGIN_UI) use (&$saved): void {
                $saved[] = [$provider, $key, $origin];
            },
        );
        $plugKeys->expects(self::never())->method('deleteKey');

        $service = $this->service(
            $this->adapter('exa', PlugHealth::unavailable('Exa search returned HTTP 401')),
            $plugKeys,
        );

        try {
            $service->saveKey('exa', 'new-bad-key');
            self::fail('expected the rejected key to throw');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('not stored', $e->getMessage());
        }

        self::assertSame([
            ['exa', 'new-bad-key', PlugKeyStore::ORIGIN_UI],
            ['exa', 'old-good-key', PlugKeyStore::ORIGIN_ENV],
        ], $saved);
    }

    public function testProbeExceptionRollsBackPreviousKey(): void
    {
        $saved = [];
        $plugKeys = $this->createMock(PlugKeyStore::class);
        $plugKeys->method('supports')->willReturn(true);
        $plugKeys->method('getStatus')->willReturn([
            'configured' => true,
            'source' => 'db',
            'origin' => PlugKeyStore::ORIGIN_UI,
            'maskedKey' => '••••',
        ]);
        $plugKeys->method('getKey')->willReturn('old-good-key');
        $plugKeys->expects(self::exactly(2))->method('saveKey')->willReturnCallback(
            static function (string $provider, string $key) use (&$saved): void {
                $saved[] = [$provider, $key];
            },
        );

        $service = $this->service($this->throwingAdapter('exa'), $plugKeys);

        try {
            $service->saveKey('exa', 'new-bad-key');
            self::fail('expected the rejected key to throw');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('not stored', $e->getMessage());
            self::assertStringContainsString('upstream timeout', $e->getMessage());
        }

        self::assertSame([['exa', 'new-bad-key'], ['exa', 'old-good-key']], $saved);
    }

    public function testAcceptedWebSearchKeyIsKeptAndCached(): void
    {
        $plugKeys = $this->createMock(PlugKeyStore::class);
        $plugKeys->method('supports')->with('exa')->willReturn(true);
        $plugKeys->method('getStatus')->willReturn([
            'configured' => true,
            'source' => 'db',
            'origin' => 'ui',
            'maskedKey' => '••••abcd',
        ]);
        $plugKeys->method('getKey')->willReturn(null);
        $plugKeys->expects(self::once())->method('saveKey')->with('exa', 'real-key');
        $plugKeys->expects(self::never())->method('deleteKey');

        $cache = new WebSearchHealthCache();
        $service = $this->service(
            $this->adapter('exa', PlugHealth::available()),
            $plugKeys,
            $cache,
        );

        $status = $service->saveKey('exa', 'real-key');

        self::assertTrue($status['configured']);
        $remembered = $cache->remember('exa', static fn () => PlugHealth::unavailable('cache miss'));
        self::assertTrue($remembered->available);
    }

    public function testRerankKeyIsNotProbedAsWebSearch(): void
    {
        $plugKeys = $this->createMock(PlugKeyStore::class);
        $plugKeys->method('supports')->with('jina')->willReturn(true);
        $plugKeys->method('getStatus')->willReturn([
            'configured' => true,
            'source' => 'db',
            'origin' => 'ui',
            'maskedKey' => '••••',
        ]);
        $plugKeys->expects(self::once())->method('saveKey')->with('jina', 'jina-key');
        $plugKeys->expects(self::never())->method('deleteKey');

        $service = $this->service(
            $this->adapter('exa', PlugHealth::unavailable('must not run')),
            $plugKeys,
        );

        $status = $service->saveKey('jina', 'jina-key');
        self::assertTrue($status['configured']);
    }

    private function service(
        WebSearchProviderInterface $adapter,
        PlugKeyStore $plugKeys,
        ?WebSearchHealthCache $cache = null,
    ): WebSearchAdminService {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);
        $config = new PlugConfigService($repo);
        $cache ??= new WebSearchHealthCache();

        return new WebSearchAdminService(
            new WebSearchRegistry([$adapter], $config),
            $config,
            $plugKeys,
            $this->createMock(ProviderKeyStore::class),
            $this->createMock(BraveSearchService::class),
            new WebSearchAdminHealth($cache),
        );
    }

    private function adapter(string $key, PlugHealth $probe): WebSearchProviderInterface
    {
        return new class($key, $probe) implements WebSearchProviderInterface, WebSearchLiveProbeInterface {
            public function __construct(
                private string $providerKey,
                private PlugHealth $probeHealth,
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
                return WebSearchCapabilities::exa();
            }

            public function search(WebSearchQuery $query): SearchResultSet
            {
                return SearchResultSet::empty($query->query);
            }

            public function health(): PlugHealth
            {
                return PlugHealth::available();
            }

            public function probe(): PlugHealth
            {
                return $this->probeHealth;
            }
        };
    }

    private function throwingAdapter(string $key): WebSearchProviderInterface
    {
        return new class($key) implements WebSearchProviderInterface, WebSearchLiveProbeInterface {
            public function __construct(
                private string $providerKey,
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
                return WebSearchCapabilities::exa();
            }

            public function search(WebSearchQuery $query): SearchResultSet
            {
                return SearchResultSet::empty($query->query);
            }

            public function health(): PlugHealth
            {
                return PlugHealth::available();
            }

            public function probe(): PlugHealth
            {
                throw new \RuntimeException('upstream timeout');
            }
        };
    }
}
