<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Repository\ConfigRepository;
use App\Seed\BundledPluginSeeder;
use App\Service\Plugin\PluginManager;
use App\Service\Plugin\PluginManifest;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class BundledPluginSeederTest extends TestCase
{
    public function testInstallsWhenTheEnabledRowIsMissing(): void
    {
        $installed = $this->seed(
            enabled: [1 => ['synaform' => null, 'synamail' => null]],
            symlinked: [],
        );

        self::assertSame([[1, 'synaform'], [1, 'synamail']], $installed);
    }

    public function testLeavesAnExplicitlyDisabledPluginOff(): void
    {
        $installed = $this->seed(
            enabled: [1 => ['synaform' => '0', 'synamail' => '1']],
            symlinked: ['1/synamail'],
        );

        self::assertSame([], $installed);
    }

    public function testRestoresAMissingSymlinkAndAWipedDatabaseRow(): void
    {
        $installed = $this->seed(
            enabled: [
                1 => ['synaform' => '1', 'synamail' => null],
            ],
            symlinked: ['1/synamail'],
        );

        self::assertSame([[1, 'synaform'], [1, 'synamail']], $installed);
    }

    public function testSkipsAPluginThatIsNotInTheImage(): void
    {
        $plugins = $this->createMock(PluginManager::class);
        $plugins->method('listAvailablePlugins')->willReturn([
            new PluginManifest('synaform', '4.4.3', 'Forms'),
        ]);
        $plugins->expects($this->once())->method('installPlugin')->with(1, 'synaform');

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([1]);

        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('not in this image'),
            ['plugin' => 'synamail'],
        );

        (new BundledPluginSeeder($connection, $config, $plugins, $logger))->seed();
    }

    /**
     * @param array<int, array<string, ?string>> $enabled
     * @param list<string>                       $symlinked userId/pluginName
     *
     * @return list<array{0: int, 1: string}>
     */
    private function seed(array $enabled, array $symlinked): array
    {
        $plugins = $this->createStub(PluginManager::class);
        $plugins->method('listAvailablePlugins')->willReturn([
            new PluginManifest('synaform', '4.4.3', 'Forms'),
            new PluginManifest('synamail', '1.0.0', 'Mail profiles'),
        ]);
        $plugins->method('configGroup')->willReturnCallback(
            static fn (string $name): string => 'P_'.$name,
        );
        $plugins->method('isInstalled')->willReturnCallback(
            static fn (int $userId, string $name): bool => in_array($userId.'/'.$name, $symlinked, true),
        );

        $installed = [];
        $plugins->method('installPlugin')->willReturnCallback(
            static function (int $userId, string $name) use (&$installed): void {
                $installed[] = [$userId, $name];
            },
        );

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn(array_keys($enabled));

        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturnCallback(
            static function (int $userId, string $group, string $setting) use ($enabled): ?string {
                self::assertSame('enabled', $setting);

                return $enabled[$userId][substr($group, 2)] ?? null;
            },
        );

        $result = (new BundledPluginSeeder(
            $connection,
            $config,
            $plugins,
            $this->createStub(LoggerInterface::class),
        ))->seed();

        self::assertSame(count($installed), $result->inserted);

        return $installed;
    }
}
