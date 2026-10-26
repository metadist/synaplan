<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\RAG;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\RAG\DocumentKeywordSearch;
use App\Service\RAG\HybridKnowledgeSearch;
use App\Service\RAG\VectorStorage\DocumentScope;
use App\Service\RAG\VectorStorage\DTO\RagScope;
use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\RagScopeFilter;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\SmartSearch\RankFusion;
use App\Service\SmartSearch\SearchHit;
use App\Service\VectorSearch\QdrantClientInterface;
use App\Service\VectorSearch\QdrantTextSearch;
use PHPUnit\Framework\TestCase;

final class HybridKnowledgeSearchTest extends TestCase
{
    public function testPaletteFusionSnapshotIsUnchanged(): void
    {
        $lexical = [
            new SearchHit('file', '1', 'One', '/files/1', SearchHit::MATCHED_LEXICAL),
            new SearchHit('file', '2', 'Two', '/files/2', SearchHit::MATCHED_LEXICAL),
        ];
        $semantic = [
            new SearchHit('file', '2', 'Two', '/files/2', SearchHit::MATCHED_SEMANTIC),
            new SearchHit('file', '3', 'Three', '/files/3', SearchHit::MATCHED_SEMANTIC),
        ];
        $fused = array_map(
            static fn (SearchHit $hit): array => [
                'id' => $hit->id(),
                'matchedBy' => $hit->matchedBy,
                'score' => $hit->toArray()['score'],
            ],
            RankFusion::fuse([$lexical, $semantic]),
        );

        self::assertSame([
            ['id' => 'file:2', 'matchedBy' => 'both', 'score' => 0.032522],
            ['id' => 'file:1', 'matchedBy' => 'lexical', 'score' => 0.016393],
            ['id' => 'file:3', 'matchedBy' => 'semantic', 'score' => 0.016129],
        ], $fused);
    }

    public function testAKeywordArticleNumberStaysWhenMeaningMissesIt(): void
    {
        $meaning = [[
            'chunk_id' => 'c1',
            'file_id' => 1,
            'chunk_text' => 'A general note about privacy.',
            'file_name' => 'note.md',
        ]];
        $keyword = [[
            'chunk_id' => 'art',
            'file_id' => 17,
            'chunk_text' => 'Art. 17 gives a right to erasure.',
            'file_name' => 'gdpr.md',
        ]];

        $merged = (new HybridKnowledgeSearch())->merge($meaning, $keyword, 1, 'What does Art. 17 say?');

        self::assertSame('art', $merged[0]['chunk_id']);
    }

    public function testAnEmptyKeywordListLeavesTheMeaningListUntouched(): void
    {
        $meaning = [
            ['chunk_id' => 'c1', 'chunk_text' => 'One', 'file_id' => 1],
            ['chunk_id' => 'c2', 'chunk_text' => 'Two', 'file_id' => 2],
        ];

        self::assertSame($meaning, (new HybridKnowledgeSearch())->merge($meaning, [], 5, 'Art. 17'));
    }

    public function testKeywordFilterMatchesTheVectorFilter(): void
    {
        $own = SearchQuery::own(4, [], 'folder');
        self::assertSame(RagScopeFilter::qdrant($own), DocumentScope::filter(4, 'folder', null));
        self::assertSame(RagScopeFilter::qdrant($own), DocumentScope::filter(4, 'folder', $own));

        $shared = new SearchQuery(4, [], null, 5, 0.3, [
            new RagScope(4, 'folder'),
            new RagScope(9, 'shared', [3]),
        ]);
        self::assertFalse($shared->isLegacyOwnFilter());
        self::assertSame(RagScopeFilter::qdrant($shared), DocumentScope::filter(4, 'folder', $shared));
    }

    public function testTextMatchStaysInsideEachOwnerScope(): void
    {
        $filter = ['should' => [
            ['must' => [['key' => 'user_id', 'match' => ['value' => 4]]]],
            ['must' => [['key' => 'user_id', 'match' => ['value' => 9]]]],
        ]];

        $with = QdrantTextSearch::withText($filter, QdrantTextSearch::tokens('Art. 17'));

        $words = static fn (array $branch): array => array_map(
            static fn (array $condition): string => $condition['match']['text'],
            $branch['must'][1]['should'],
        );
        self::assertSame(['art', '17'], $words($with['should'][0]));
        self::assertSame(['art', '17'], $words($with['should'][1]));
        self::assertArrayNotHasKey('must', array_diff_key($with, ['should' => true]));
    }

    public function testMariaDbSkipsTheKeywordLeg(): void
    {
        $client = $this->createMock(QdrantClientInterface::class);
        $client->expects($this->never())->method('searchDocumentKeywords');
        $storage = $this->createMock(VectorStorageFacade::class);
        $storage->method('getProviderName')->willReturn('mariadb');
        $search = new DocumentKeywordSearch($client, $storage, $this->createMock(FileRepository::class));

        self::assertSame([], $search->find(4, 'Art. 17', 'folder', [new RagScope(4, 'folder')], 5));
    }

    public function testQdrantKeywordHitKeepsTheFileName(): void
    {
        $client = $this->createMock(QdrantClientInterface::class);
        $client->method('searchDocumentKeywords')->willReturn([[
            'id' => 'p1',
            'score' => 2.0,
            'payload' => ['file_id' => 17, 'user_id' => 9, 'group_key' => 'law', 'text' => 'Art. 17 gives a right to erasure.'],
        ]]);
        $storage = $this->createMock(VectorStorageFacade::class);
        $storage->method('getProviderName')->willReturn('qdrant');
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(17);
        $file->method('getFileName')->willReturn('gdpr.md');
        $files = $this->createMock(FileRepository::class);
        $files->expects($this->once())->method('findBy')->with(['id' => [17]])->willReturn([$file]);
        $search = new DocumentKeywordSearch($client, $storage, $files);

        $hits = $search->find(4, 'Art. 17', null, [new RagScope(4, null), new RagScope(9, 'law')], 5);

        self::assertSame('p1', $hits[0]['chunk_id']);
        self::assertSame(17, $hits[0]['file_id']);
        self::assertSame('gdpr.md', $hits[0]['file_name']);
        self::assertStringContainsString('Art. 17', $hits[0]['chunk_text']);
        self::assertSame(9, $hits[0]['owner_id']);
        self::assertTrue($hits[0]['shared']);
        self::assertSame('law', $hits[0]['group_key']);
    }

    public function testNoScopesSearchesNothing(): void
    {
        $client = $this->createMock(QdrantClientInterface::class);
        $client->expects($this->never())->method('searchDocumentKeywords');
        $storage = $this->createMock(VectorStorageFacade::class);
        $storage->method('getProviderName')->willReturn('qdrant');
        $search = new DocumentKeywordSearch($client, $storage, $this->createMock(FileRepository::class));

        self::assertSame([], $search->find(4, 'Art. 17', null, [], 5));
    }
}
