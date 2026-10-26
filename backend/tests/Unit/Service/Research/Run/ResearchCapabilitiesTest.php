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

    private function capabilities(ReplaySearch $search): ResearchCapabilities
    {
        $repo = $this->createStub(ConfigRepository::class);

        return new ResearchCapabilities(new PlugConfigService($repo), new ResearchConfig($repo, '1'), $search);
    }
}
