<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Entity\User;
use App\Plug\PlugConfigService;
use App\Repository\ConfigRepository;
use App\Service\Research\Run\ResearchCapabilities;
use App\Service\Research\Run\ResearchConfig;
use PHPUnit\Framework\TestCase;

final class ResearchCapabilitiesTest extends TestCase
{
    public function testTheWebIsOfferedOnlyWhenItsProviderIsSetUp(): void
    {
        $search = new ReplaySearch();
        $search->available = false;

        $off = $this->capabilities($search)->describe(new User());

        self::assertFalse($off['web_search_available']);
        self::assertNull($off['web_search_provider']);

        $search->available = true;
        $on = $this->capabilities($search)->describe(new User());

        self::assertTrue($on['web_search_available']);
        self::assertSame('brave', $on['web_search_provider']['id'] ?? null);
    }

    public function testAutomaticResearchFollowsItsRowAndNamesTheStandardCeiling(): void
    {
        $search = new ReplaySearch();
        $search->available = true;

        $off = $this->capabilities($search)->describe(new User());

        self::assertContains('auto', $off['depths']);
        self::assertFalse($off['automatic']);
        self::assertSame(['minutes' => 6, 'cost_cap' => 1.0], $off['auto_limits']);

        $on = $this->capabilities($search, '1')->describe(new User());

        self::assertTrue($on['automatic']);
    }

    private function capabilities(ReplaySearch $search, ?string $autoRow = null): ResearchCapabilities
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => ResearchConfig::AUTO_ENABLED === $setting ? $autoRow : null,
        );

        return new ResearchCapabilities(new PlugConfigService($repo), new ResearchConfig($repo, '1'), $search);
    }
}
