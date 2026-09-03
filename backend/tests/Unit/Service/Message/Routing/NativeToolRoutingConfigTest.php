<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Routing;

use App\Repository\ConfigRepository;
use App\Service\Message\Routing\NativeToolRoutingConfig;
use PHPUnit\Framework\TestCase;

final class NativeToolRoutingConfigTest extends TestCase
{
    /**
     * @param array<int, string> $values BCONFIG rows keyed by ownerId (0 = global)
     */
    private function config(array $values): NativeToolRoutingConfig
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->method('getValue')->willReturnCallback(
            static function (int $ownerId, string $group, string $setting) use ($values): ?string {
                if (NativeToolRoutingConfig::CONFIG_GROUP !== $group || NativeToolRoutingConfig::KEY_ENABLED !== $setting) {
                    return null;
                }

                return $values[$ownerId] ?? null;
            }
        );

        return new NativeToolRoutingConfig($repository);
    }

    /**
     * The plan requires the hottest path in the product to stay on its
     * current behaviour until an operator opts in.
     */
    public function testDefaultsToOffWhenNothingIsConfigured(): void
    {
        self::assertFalse($this->config([])->isEnabled(42));
        self::assertFalse($this->config([])->isEnabled(null));
    }

    public function testGlobalRowSwitchesItOnForEveryone(): void
    {
        self::assertTrue($this->config([0 => '1'])->isEnabled(42));
        self::assertTrue($this->config([0 => '1'])->isEnabled(null));
    }

    public function testPerUserRowWinsOverTheGlobalOne(): void
    {
        self::assertTrue($this->config([0 => '0', 42 => '1'])->isEnabled(42));
        self::assertFalse($this->config([0 => '1', 42 => '0'])->isEnabled(42));
    }

    public function testOtherUsersAreUnaffectedByAPerUserRollout(): void
    {
        $config = $this->config([42 => '1']);

        self::assertTrue($config->isEnabled(42));
        self::assertFalse($config->isEnabled(43));
    }

    public function testAnUnparseableValueFallsBackToOff(): void
    {
        self::assertFalse($this->config([0 => 'perhaps'])->isEnabled(1));
    }
}
