<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Gate;

use App\Entity\Config;
use App\Module\Gate\ModuleGateConfig;
use App\Repository\ConfigRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleGateConfigTest extends TestCase
{
    public function testSettingNameIsUpperCasedModuleIdWithPrefix(): void
    {
        $this->assertSame('GATE_TIKA', ModuleGateConfig::settingFor('tika'));
        $this->assertSame('GATE_STRIPE_BILLING', ModuleGateConfig::settingFor('stripe_billing'));
        $this->assertSame('MODULES', ModuleGateConfig::GROUP);
    }

    public function testEveryGateIsOffWhenNoRowExists(): void
    {
        $config = $this->config([]);

        $this->assertFalse($config->isGated('tika'));
        $this->assertFalse($config->isGated('stripe_billing'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function flagValues(): iterable
    {
        yield 'one' => ['1', true];
        yield 'true' => ['true', true];
        yield 'yes' => ['yes', true];
        yield 'on' => ['on', true];
        yield 'zero' => ['0', false];
        yield 'false' => ['false', false];
        yield 'empty' => ['', false];
        yield 'garbage reads as off' => ['maybe', false];
    }

    #[DataProvider('flagValues')]
    public function testFlagValueIsParsedAsBoolean(string $value, bool $expected): void
    {
        $config = $this->config([$this->row(0, 'GATE_TIKA', $value)]);

        $this->assertSame($expected, $config->isGated('tika'));
        $this->assertFalse($config->isGated('docling'), 'an unrelated module stays off');
    }

    public function testOnlyGlobalRowsCount(): void
    {
        $config = $this->config([$this->row(42, 'GATE_TIKA', '1')]);

        $this->assertFalse($config->isGated('tika'), 'a per-user row must not gate an installation-level module');
    }

    public function testGroupIsLoadedOncePerRequestAndAgainAfterReset(): void
    {
        $repository = $this->createMock(ConfigRepository::class);
        $repository->expects($this->exactly(2))
            ->method('getByGroup')
            ->with(0, 'MODULES')
            ->willReturn([$this->row(0, 'GATE_TIKA', '1')]);

        $config = new ModuleGateConfig($repository);

        $this->assertTrue($config->isGated('tika'));
        $this->assertFalse($config->isGated('docling'));
        $this->assertTrue($config->isGated('tika'));

        $config->reset();

        $this->assertTrue($config->isGated('tika'));
    }

    /**
     * @param list<Config> $rows
     */
    private function config(array $rows): ModuleGateConfig
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getByGroup')->willReturn($rows);

        return new ModuleGateConfig($repository);
    }

    private function row(int $ownerId, string $setting, string $value): Config
    {
        return (new Config())
            ->setOwnerId($ownerId)
            ->setGroup(ModuleGateConfig::GROUP)
            ->setSetting($setting)
            ->setValue($value);
    }
}
