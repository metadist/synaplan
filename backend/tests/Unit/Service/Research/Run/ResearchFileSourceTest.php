<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\AI\ToolCalling\ToolCall;
use App\Service\Research\Run\Lead\LeadRead;
use App\Service\Research\Run\Lead\LeadSearch;
use App\Service\Research\Run\Lead\LeadToolbox;
use App\Service\Research\Run\Lead\ScriptedSearch;
use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchEventLog;
use App\Service\Research\Run\ResearchRejected;
use App\Service\Research\Run\ResearchRoles;
use App\Service\Research\Run\ResearchRunKeys;
use App\Service\Research\Run\ResearchRunRef;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchSources;
use App\Service\Research\Run\Stage\ResearchFileSearch;
use App\Service\Research\Run\Stage\ResearchStop;
use App\Service\Research\Run\Stage\StageContext;
use PHPUnit\Framework\TestCase;

final class ResearchFileSourceTest extends TestCase
{
    public function testARequestWithAFolderTurnsFilesOn(): void
    {
        $sources = ResearchSources::fromBody([
            'sources' => ['web' => true, 'knowledge' => ['DESKTOP:42'], 'library' => false],
        ]);

        self::assertSame('web files', $sources->label());
        self::assertSame(['DESKTOP:42'], $sources->knowledge);
    }

    public function testTheWebFilesSwitchSearchesTheCallersFiles(): void
    {
        $sources = ResearchSources::fromBody([
            'sources' => ['web' => true, 'knowledge' => true],
        ]);

        self::assertSame('web files', $sources->label());
        self::assertSame([], $sources->knowledge);
    }

    public function testFilesOnlyIsRefusedWhenNothingIsChosen(): void
    {
        $this->expectException(ResearchRejected::class);
        ResearchSources::fromBody(['sources' => ['web' => false, 'library' => false]]);
    }

    public function testASavedRunRemembersTheFolder(): void
    {
        $state = $this->state('web files', ['DESKTOP:42']);
        $again = ResearchRunState::decode($state->encode());

        self::assertNotNull($again);
        self::assertSame('web files', $again->sourcesOn);
        self::assertSame(['DESKTOP:42'], $again->knowledgeKeys);
    }

    public function testTheLeadCanSearchAChosenFolder(): void
    {
        $files = $this->createStub(ResearchFileSearch::class);
        $files->method('find')->willReturn([['file_id' => 9, 'title' => 'Prices']]);
        $search = new LeadSearch(new ScriptedSearch(), $files);
        $ctx = $this->context('web files', ['DESKTOP:42']);

        $reply = json_decode($search->run(new ToolCall('1', 'search', [
            'point' => 'p1',
            'query' => 'heat pump subsidy',
            'language' => 'en',
            'scope' => 'files',
        ]), $ctx), true);

        self::assertIsArray($reply);
        self::assertSame('file', $reply['results'][0]['kind']);
        self::assertSame('Prices', $reply['results'][0]['title']);
    }

    public function testFilesStayHiddenUntilTheRunTurnsThemOn(): void
    {
        $files = $this->createMock(ResearchFileSearch::class);
        $files->expects(self::never())->method('find');
        $search = new LeadSearch(new ScriptedSearch(), $files);
        $ctx = $this->context('web', []);

        $reply = json_decode($search->run(new ToolCall('1', 'search', [
            'point' => 'p1',
            'query' => 'heat pump subsidy',
            'language' => 'en',
            'scope' => 'files',
        ]), $ctx), true);

        self::assertSame('not_allowed', $reply['error']);
        $scopes = (new LeadToolbox($search, $this->createStub(LeadRead::class)))
            ->definitions($ctx)[0]
            ->parameters['properties']['scope']['enum'] ?? null;
        self::assertSame(['web'], $scopes);
    }

    /**
     * @param list<string> $keys
     */
    private function state(string $sourcesOn, array $keys): ResearchRunState
    {
        $state = ResearchRunState::open(
            ResearchRunRef::start(4, ResearchDepth::Standard, 'What is the subsidy?'),
            ResearchBudget::for(ResearchDepth::Standard, ResearchBudgetConfig::defaults(ResearchDepth::Standard)),
        );
        $state->sourcesOn = $sourcesOn;
        $state->knowledgeKeys = $keys;
        $state->ledger->addPoint('Subsidy');

        return $state;
    }

    /**
     * @param list<string> $keys
     */
    private function context(string $sourcesOn, array $keys): StageContext
    {
        $keysStore = $this->createStub(ResearchRunKeys::class);
        $keysStore->method('appendEvent')->willReturn(1);

        return new StageContext(
            $this->state($sourcesOn, $keys),
            new ResearchRoles(1, 2, 3, 4, 5, false, []),
            new ResearchEventLog($keysStore),
            new \DateTimeImmutable('2026-10-27'),
            new ResearchStop(),
            sourcesOn: $sourcesOn,
        );
    }
}
