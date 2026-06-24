<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MarketingNews;

use App\Repository\ConfigRepository;
use App\Service\MarketingNews\MarketingNewsConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MarketingNewsConfigTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private MarketingNewsConfig $config;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->config = new MarketingNewsConfig($this->configRepository);
    }

    public function testDisabledByDefaultWhenNoRowExists(): void
    {
        $this->configRepository->method('getValue')->willReturn(null);

        self::assertFalse($this->config->isEnabled());
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function enabledValueProvider(): iterable
    {
        yield 'seeder 1' => ['1', true];
        yield 'seeder 0' => ['0', false];
        yield 'admin true' => ['true', true];
        yield 'admin false' => ['false', false];
        yield 'garbage' => ['nonsense', false];
    }

    #[DataProvider('enabledValueProvider')]
    public function testIsEnabledAcceptsBothConventions(string $stored, bool $expected): void
    {
        $this->configRepository->method('getValue')->willReturn($stored);

        self::assertSame($expected, $this->config->isEnabled());
    }

    public function testResolveFeedUrlReturnsNullWhenDisabled(): void
    {
        // ENABLED row is the only one consulted; it is off → no URL resolved.
        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId, string $group, string $setting): ?string {
                return MarketingNewsConfig::KEY_ENABLED === $setting ? '0' : 'https://example.com/feed.xml';
            });

        self::assertNull($this->config->resolveFeedUrl('en'));
    }

    public function testResolveFeedUrlPicksLocaleSpecificUrl(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId, string $group, string $setting): ?string {
                return match ($setting) {
                    MarketingNewsConfig::KEY_ENABLED => '1',
                    MarketingNewsConfig::KEY_FEED_URL_DE => 'https://example.com/de.xml',
                    MarketingNewsConfig::KEY_FEED_URL_EN => 'https://example.com/en.xml',
                    MarketingNewsConfig::KEY_FEED_URL_DEFAULT => 'https://example.com/default.xml',
                    default => null,
                };
            });

        self::assertSame('https://example.com/de.xml', $this->config->resolveFeedUrl('de'));
        self::assertSame('https://example.com/en.xml', $this->config->resolveFeedUrl('en'));
        self::assertSame('https://example.com/default.xml', $this->config->resolveFeedUrl('es'));
        self::assertSame('https://example.com/default.xml', $this->config->resolveFeedUrl('tr'));
    }

    public function testResolveFeedUrlFallsBackToBuiltInDefaultWhenUnset(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId, string $group, string $setting): ?string {
                return MarketingNewsConfig::KEY_ENABLED === $setting ? '1' : null;
            });

        self::assertSame(MarketingNewsConfig::DEFAULT_FEED_URL_DE, $this->config->resolveFeedUrl('de'));
        self::assertSame(MarketingNewsConfig::DEFAULT_FEED_URL_EN, $this->config->resolveFeedUrl('en'));
        self::assertSame(MarketingNewsConfig::DEFAULT_FEED_URL_EN, $this->config->resolveFeedUrl('it'));
    }

    public function testResolveFeedUrlRejectsNonHttpUrl(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId, string $group, string $setting): ?string {
                return match ($setting) {
                    MarketingNewsConfig::KEY_ENABLED => '1',
                    default => 'file:///etc/passwd',
                };
            });

        self::assertNull($this->config->resolveFeedUrl('en'));
    }
}
