<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat;

use App\Repository\ConfigRepository;
use App\Service\Chat\ProgressNarrationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ProgressNarrationConfigTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private ProgressNarrationConfig $config;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->config = new ProgressNarrationConfig($this->configRepository);
    }

    public function testEverythingOnWhenNoRowsExist(): void
    {
        $this->configRepository->method('getValue')->willReturn(null);

        self::assertSame(
            ['steps' => true, 'models' => true, 'timings' => true],
            $this->config->toRuntimeConfig(),
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function valueProvider(): iterable
    {
        yield 'seeder 1' => ['1', true];
        yield 'seeder 0' => ['0', false];
        yield 'admin true' => ['true', true];
        yield 'admin false' => ['false', false];
        yield 'garbage falls back to on' => ['nonsense', true];
    }

    #[DataProvider('valueProvider')]
    public function testFlagsAcceptBothConventions(string $stored, bool $expected): void
    {
        $this->configRepository->method('getValue')->willReturn($stored);

        self::assertSame($expected, $this->config->showSteps());
        self::assertSame($expected, $this->config->showModels());
        self::assertSame($expected, $this->config->showTimings());
    }

    public function testEachSwitchReadsItsOwnKey(): void
    {
        $this->configRepository->method('getValue')->willReturnCallback(
            static fn (int $ownerId, string $group, string $key): ?string => match (true) {
                0 !== $ownerId || ProgressNarrationConfig::CONFIG_GROUP !== $group => null,
                ProgressNarrationConfig::KEY_MODELS === $key => '0',
                default => '1',
            }
        );

        self::assertSame(
            ['steps' => true, 'models' => false, 'timings' => true],
            $this->config->toRuntimeConfig(),
        );
    }
}
