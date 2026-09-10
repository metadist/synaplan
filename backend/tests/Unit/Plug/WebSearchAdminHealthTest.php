<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchAdminHealth;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchHealthCache;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;
use PHPUnit\Framework\TestCase;

final class WebSearchAdminHealthTest extends TestCase
{
    public function testUnconfiguredStaysAConfigReasonAndDoesNotProbe(): void
    {
        $probes = 0;
        $adapter = $this->provider('exa', configured: false, probe: static function () use (&$probes): PlugHealth {
            ++$probes;

            return PlugHealth::available();
        });

        $health = $this->resolver()->forAdapter($adapter, 'exa', '');

        self::assertFalse($health->available);
        self::assertSame('Exa API key is not configured', $health->reason);
        self::assertSame(0, $probes);
    }

    public function testUnusedKeyIsNotReportedAvailable(): void
    {
        $probes = 0;
        $adapter = $this->provider('exa', configured: true, probe: static function () use (&$probes): PlugHealth {
            ++$probes;

            return PlugHealth::available();
        });

        $health = $this->resolver()->forAdapter($adapter, 'brave', '');

        self::assertFalse($health->available);
        self::assertSame('Key stored — not verified', $health->reason);
        self::assertSame(0, $probes);
    }

    public function testActiveProviderUsesLiveProbe(): void
    {
        $adapter = $this->provider('exa', configured: true, probe: static function (): PlugHealth {
            return PlugHealth::unavailable('Exa search returned HTTP 401');
        });

        $health = $this->resolver()->forAdapter($adapter, 'exa', '');

        self::assertFalse($health->available);
        self::assertSame('Exa search returned HTTP 401', $health->reason);
    }

    public function testFallbackProviderUsesLiveProbe(): void
    {
        $adapter = $this->provider('exa', configured: true, probe: static function (): PlugHealth {
            return PlugHealth::unavailable('Exa search returned HTTP 401');
        });

        $health = $this->resolver()->forAdapter($adapter, 'brave', 'exa');

        self::assertFalse($health->available);
        self::assertSame('Exa search returned HTTP 401', $health->reason);
    }

    public function testSearxngIsAlwaysProbedWhenConfigured(): void
    {
        $adapter = $this->provider('searxng', configured: true, probe: static function (): PlugHealth {
            return PlugHealth::unavailable('SearXNG unavailable — connection refused');
        });

        $health = $this->resolver()->forAdapter($adapter, 'brave', '');

        self::assertFalse($health->available);
        self::assertStringContainsString('connection refused', (string) $health->reason);
    }

    public function testProbeResultIsCached(): void
    {
        $probes = 0;
        $adapter = $this->provider('searxng', configured: true, probe: static function () use (&$probes): PlugHealth {
            ++$probes;

            return PlugHealth::unavailable('SearXNG unavailable — connection refused');
        });
        $resolver = $this->resolver();

        $resolver->forAdapter($adapter, 'brave', '');
        $resolver->forAdapter($adapter, 'brave', '');

        self::assertSame(1, $probes);
    }

    private function resolver(): WebSearchAdminHealth
    {
        return new WebSearchAdminHealth(new WebSearchHealthCache());
    }

    private function provider(string $key, bool $configured, callable $probe): WebSearchProviderInterface
    {
        return new class($key, $configured, $probe) implements WebSearchProviderInterface {
            /**
             * @param callable(): PlugHealth $probe
             */
            public function __construct(
                private string $providerKey,
                private bool $configured,
                private mixed $probe,
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
                return SearchResultSet::empty($query->query);
            }

            public function health(): PlugHealth
            {
                return $this->configured
                    ? PlugHealth::available()
                    : PlugHealth::unavailable('Exa API key is not configured');
            }

            public function probe(): PlugHealth
            {
                return ($this->probe)();
            }
        };
    }
}
