<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\AI\ToolCalling\ToolCall;
use App\Service\RAG\DocumentKeywordSearch;
use App\Service\RAG\HybridKnowledgeSearch;
use App\Service\RAG\RagScopeResolver;
use App\Service\RAG\VectorSearchService;
use App\Service\RAG\VectorStorage\DTO\RagScope;
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

    public function testTheLibraryWinsOverAFolderListAndRepeatedFoldersCountOnce(): void
    {
        $library = ResearchSources::fromBody([
            'sources' => ['knowledge' => ['DESKTOP:42'], 'library' => true],
        ]);
        $folders = ResearchSources::fromBody([
            'sources' => ['knowledge' => ['DESKTOP:42', ' DESKTOP:42 ']],
        ]);

        self::assertSame([], $library->knowledge);
        self::assertTrue($library->library);
        self::assertSame(['DESKTOP:42'], $folders->knowledge);
    }

    public function testASharedFolderTheCallerCannotUseIsNotSearched(): void
    {
        $vectors = $this->createMock(VectorSearchService::class);
        $vectors->expects(self::never())->method('semanticSearch');
        $keywords = $this->createMock(DocumentKeywordSearch::class);
        $keywords->expects(self::never())->method('find');
        $scopes = $this->createStub(RagScopeResolver::class);
        $scopes->method('canUsePickerKey')->willReturn(false);
        $scopes->method('resolve')->willReturn([new RagScope(4, null)]);

        $search = new ResearchFileSearch($vectors, $keywords, $this->createStub(HybridKnowledgeSearch::class), $scopes);

        self::assertSame([], $search->find(4, 'prices', 5, ['shared:9:Team']));
    }

    public function testSeveralFoldersTakeTurns(): void
    {
        $vectors = $this->createStub(VectorSearchService::class);
        $vectors->method('semanticSearch')->willReturnCallback(static fn (string $query, int $userId, ?string $key): array => 'A' === $key
            ? [['file_id' => 1, 'file_name' => 'a1'], ['file_id' => 2, 'file_name' => 'a2'], ['file_id' => 3, 'file_name' => 'a3']]
            : [['file_id' => 7, 'file_name' => 'b1']]);
        $keywords = $this->createStub(DocumentKeywordSearch::class);
        $keywords->method('find')->willReturn([]);
        $hybrid = $this->createStub(HybridKnowledgeSearch::class);
        $hybrid->method('merge')->willReturnArgument(0);
        $scopes = $this->createStub(RagScopeResolver::class);
        $scopes->method('canUsePickerKey')->willReturn(true);
        $scopes->method('resolve')->willReturn([new RagScope(4, null)]);

        $found = (new ResearchFileSearch($vectors, $keywords, $hybrid, $scopes))->find(4, 'prices', 2, ['A', 'B']);

        self::assertSame([1, 7], array_column($found, 'file_id'));
    }

    public function testTheLeadSeesAFileItFoundBeforeUnderTheSameId(): void
    {
        $files = $this->createStub(ResearchFileSearch::class);
        $files->method('find')->willReturn([['file_id' => 9, 'title' => 'Prices']]);
        $search = new LeadSearch(new ScriptedSearch(), $files);
        $ctx = $this->context('files', []);
        $call = static fn (string $query): ToolCall => new ToolCall('1', 'search', [
            'point' => 'p1',
            'query' => $query,
            'language' => 'en',
            'scope' => 'files',
        ]);

        $first = json_decode($search->run($call('heat pump subsidy'), $ctx), true);
        $second = json_decode($search->run($call('heat pump grant'), $ctx), true);

        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame($first['results'][0]['id'], $second['results'][0]['id']);
        self::assertCount(1, $ctx->state->ledger->sources());
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
