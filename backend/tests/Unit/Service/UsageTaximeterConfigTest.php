<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\ConfigRepository;
use App\Service\UsageTaximeterConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class UsageTaximeterConfigTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private UsageTaximeterConfig $config;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->config = new UsageTaximeterConfig($this->configRepository);
    }

    public function testEnabledByDefaultWhenNoRowExists(): void
    {
        // Deliberately the opposite of MarketingNews: the taximeter is a
        // transparency feature and defaults ON on a fresh install.
        $this->configRepository->method('getValue')->willReturn(null);

        self::assertTrue($this->config->isEnabled());
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
        // Garbage falls back to the built-in default (ON), unlike MarketingNews.
        yield 'garbage' => ['nonsense', true];
    }

    #[DataProvider('enabledValueProvider')]
    public function testIsEnabledAcceptsBothConventions(string $stored, bool $expected): void
    {
        $this->configRepository->method('getValue')->willReturn($stored);

        self::assertSame($expected, $this->config->isEnabled());
    }

    public function testReadsTheGlobalOwnerAndCorrectKey(): void
    {
        $this->configRepository->expects(self::once())
            ->method('getValue')
            ->with(0, UsageTaximeterConfig::CONFIG_GROUP, UsageTaximeterConfig::KEY_ENABLED)
            ->willReturn('0');

        self::assertFalse($this->config->isEnabled());
    }
}
