<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Repository\ConfigRepository;
use App\Service\Research\Run\ResearchBudgetStore;
use App\Service\Research\Run\ResearchConfig;
use App\Service\Research\Run\ResearchDepth;
use PHPUnit\Framework\TestCase;

final class ResearchBudgetStoreTest extends TestCase
{
    public function testAStoredRowChangesOnlyTheNumbersAnAdministratorSets(): void
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturn('{"searches":3,"sources":5,"minutes":2,"cost_cap":0.2}');
        $store = new ResearchBudgetStore($repo);

        $limits = $store->forDepth(ResearchDepth::Quick);

        self::assertSame(3, $limits->searches);
        self::assertSame(5, $limits->sources);
        self::assertSame(120, $limits->wallSeconds);
        self::assertSame(0.2, $limits->costCap);
        self::assertSame(0, $limits->rounds);
    }

    public function testABrokenRowKeepsTheBuiltInLimits(): void
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturn('{');
        $store = new ResearchBudgetStore($repo);

        $limits = $store->forDepth(ResearchDepth::Standard);

        self::assertSame(10, $limits->searches);
        self::assertSame(ResearchBudgetStore::setting(ResearchDepth::Standard), 'BUDGET_STANDARD');
        self::assertSame(ResearchConfig::GROUP, 'RESEARCH');
    }

    public function testAnAutomaticRunReadsTheStandardRow(): void
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => 'BUDGET_STANDARD' === $setting ? '{"searches": 7}' : null,
        );
        $store = new ResearchBudgetStore($repo);

        self::assertSame('BUDGET_STANDARD', ResearchBudgetStore::setting(ResearchDepth::Auto));
        self::assertSame(7, $store->forDepth(ResearchDepth::Auto)->searches);
    }
}
