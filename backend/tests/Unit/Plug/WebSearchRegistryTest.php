<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchGateway;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;
use App\Plug\WebSearch\WebSearchRegistry;
use App\Repository\ConfigRepository;
use PHPUnit\Framework\TestCase;

final class WebSearchRegistryTest extends TestCase
{
    public function testGatewayIsEnabledUsesPerUserProvider(): void
    {
        $brave = $this->provider('brave', available: true);
        $searx = $this->provider('searxng', available: false);
        $gateway = new WebSearchGateway($this->registry([
            [0, PlugConfigService::KEY_WEB_SEARCH_PROVIDER, 'brave'],
            [42, PlugConfigService::KEY_WEB_SEARCH_PROVIDER, 'searxng'],
        ], [$brave, $searx]));

        $this->assertTrue($gateway->isEnabled());
        $this->assertTrue($gateway->isEnabled(7));
        $this->assertFalse($gateway->isEnabled(42));
    }

    /**
     * @param list<array{0: int, 1: string, 2: string}> $rows
     * @param list<WebSearchProviderInterface>          $providers
     */
    private function registry(array $rows, array $providers): WebSearchRegistry
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

        return new WebSearchRegistry($providers, new PlugConfigService($repo));
    }

    private function provider(string $key, bool $available): WebSearchProviderInterface
    {
        return new class($key, $available) implements WebSearchProviderInterface {
            public function __construct(
                private string $providerKey,
                private bool $available,
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
                return SearchResultSet::fromLegacyArray(['query' => $query->query, 'results' => []]);
            }

            public function health(): PlugHealth
            {
                return $this->available
                    ? PlugHealth::available()
                    : PlugHealth::unavailable($this->providerKey.' unavailable');
            }
        };
    }
}
