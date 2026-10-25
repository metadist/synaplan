<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Command\ResearchLeadConformanceCommand;
use App\Repository\ConfigRepository;
use App\Service\Research\Run\Lead\LeadConformance;
use App\Service\Research\Run\ResearchConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ResearchLeadTest extends TestCase
{
    public function testTheConformanceSuitePassesOnTheDoubles(): void
    {
        $results = (new LeadConformance())->results();

        self::assertCount(LeadConformance::CASES, $results);
        $failed = array_filter($results, static fn (?string $failure): bool => null !== $failure);
        self::assertSame([], $failed);
    }

    public function testTheCommandReportsTheScriptedTurns(): void
    {
        $tester = new CommandTester(new ResearchLeadConformanceCommand(new LeadConformance()));
        $tester->execute(['key' => 'anthropic:claude:chat']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No provider was called', $tester->getDisplay());
    }

    public function testAnUnreadableModelListStaysEmpty(): void
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => match ($setting) {
                ResearchConfig::LEAD_MODE => 'TOOLS',
                ResearchConfig::TOOL_MODE_MODELS => '{',
                default => null,
            },
        );
        $config = new ResearchConfig($repo, '');

        self::assertSame('tools', $config->leadMode());
        self::assertSame([], $config->toolModeModels());
    }
}
