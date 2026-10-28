<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Repository\ConfigRepository;
use App\Service\Research\Run\ResearchConfig;
use PHPUnit\Framework\TestCase;

final class ResearchConfigTest extends TestCase
{
    public function testAutomaticResearchNeedsItsRowAndResearchOn(): void
    {
        self::assertTrue($this->config(['ENABLED' => '1', 'AUTO_ENABLED' => '1'], '')->autoEnabled());
        self::assertFalse($this->config(['ENABLED' => '1'], '')->autoEnabled(), 'a missing row is off');
        self::assertFalse($this->config(['ENABLED' => '1', 'AUTO_ENABLED' => '0'], '')->autoEnabled());
        self::assertFalse($this->config(['ENABLED' => '0', 'AUTO_ENABLED' => '1'], '')->autoEnabled(), 'research off wins');
        self::assertFalse($this->config(['ENABLED' => '1', 'AUTO_ENABLED' => '1'], '0')->autoEnabled(), 'the environment pin wins');
    }

    public function testTheProvenToolModeModelsAreTheSeedValue(): void
    {
        self::assertSame(['anthropic:claude-sonnet-5-5:chat', 'anthropic:claude-sonnet-5:chat'], ResearchConfig::TOOL_MODE_MODELS_PROVEN);
        self::assertSame('AUTO_ENABLED', ResearchConfig::AUTO_ENABLED);
    }

    /**
     * @param array<string, string> $rows
     */
    private function config(array $rows, string $env): ResearchConfig
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => $rows[$setting] ?? null,
        );

        return new ResearchConfig($repo, $env);
    }
}
