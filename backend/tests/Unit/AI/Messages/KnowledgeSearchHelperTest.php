<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\RAG\KnowledgeSearchHelper;
use App\Service\RAG\RagScopeResolver;
use App\Service\RAG\VectorSearchService;
use App\Service\RAG\VectorStorage\DTO\RagScope;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\Runtime\RuntimeProfile;
use PHPUnit\Framework\TestCase;

final class KnowledgeSearchHelperTest extends TestCase
{
    public function testOwnFolderIsSearched(): void
    {
        $vector = $this->createMock(VectorSearchService::class);
        $vector->expects($this->once())->method('semanticSearch')->with(
            'invoice total',
            3,
            'DESKTOP:personal',
            5,
            0.3,
            null,
        )->willReturn([[
            'file_name' => 'invoice.pdf',
            'file_id' => 12,
            'chunk_id' => 34,
            'score' => 0.81,
            'chunk_text' => 'Total 40',
        ]]);

        $found = $this->helper($vector)->searchForTool(3, 'invoice total', 'DESKTOP:personal', null, 5);

        $this->assertNull($found['error']);
        $this->assertSame('Total 40', $found['hits'][0]['chunk_text']);
    }

    public function testSharedFolderTheCallerCanUseIsSearched(): void
    {
        $scopes = $this->createMock(RagScopeResolver::class);
        $scopes->expects($this->once())->method('canUse')->with(3, 9, 'contracts')->willReturn(true);

        $vector = $this->createMock(VectorSearchService::class);
        $vector->expects($this->once())->method('semanticSearch')->with(
            'renewal',
            3,
            'shared:9:contracts',
            5,
            0.3,
            null,
        )->willReturn([]);

        $found = $this->helper($vector, $scopes)->searchForTool(3, 'renewal', 'shared:9:contracts', null, 5);

        $this->assertNull($found['error']);
    }

    public function testSharedFolderTheCallerCanOnlyViewIsRefused(): void
    {
        $scopes = $this->createMock(RagScopeResolver::class);
        $scopes->method('canUse')->with(3, 9, 'contracts')->willReturn(false);
        $vector = $this->createMock(VectorSearchService::class);
        $vector->expects($this->never())->method('semanticSearch');

        $found = $this->helper($vector, $scopes)->searchForTool(3, 'renewal', 'shared:9:contracts', null, 5);

        $this->assertSame(KnowledgeSearchHelper::REFUSED, $found['error']);
        $this->assertSame([], $found['hits']);
    }

    public function testForeignFolderIsRefused(): void
    {
        $scopes = $this->createMock(RagScopeResolver::class);
        $scopes->method('canUse')->with(3, 8, 'secret')->willReturn(false);
        $vector = $this->createMock(VectorSearchService::class);
        $vector->expects($this->never())->method('semanticSearch');

        $found = $this->helper($vector, $scopes)->searchForTool(3, 'salary', 'shared:8:secret', null, 5);

        $this->assertSame(KnowledgeSearchHelper::REFUSED, $found['error']);
    }

    public function testLimitIsClampedToTen(): void
    {
        $vector = $this->createMock(VectorSearchService::class);
        $vector->expects($this->once())->method('semanticSearch')->with(
            'notes',
            3,
            'DESKTOP:personal',
            10,
            0.3,
            null,
        )->willReturn([]);

        $this->helper($vector)->searchForTool(3, 'notes', 'DESKTOP:personal', null, 50);
    }

    public function testOwnFileInThePinnedFolderCanBeRead(): void
    {
        $files = $this->createMock(FileRepository::class);
        $files->expects($this->once())->method('find')->with(12)->willReturn($this->file(3, 'DESKTOP:personal', 'invoice.pdf', 'Total 40'));

        $read = $this->helper($this->createStub(VectorSearchService::class), files: $files)
            ->readForTool(3, 12, null, 'DESKTOP:personal', null);

        $this->assertFalse($read['isError']);
        $this->assertStringContainsString('Total 40', $read['text']);
        $this->assertStringContainsString('file_id: 12', $read['text']);
    }

    public function testSharedFileTheCallerCanUseCanBeRead(): void
    {
        $scopes = $this->createMock(RagScopeResolver::class);
        $scopes->method('canUse')->willReturn(true);
        $files = $this->createMock(FileRepository::class);
        $files->method('find')->willReturn($this->file(9, 'contracts', 'renewal.pdf', 'Renews in May'));

        $read = $this->helper($this->createStub(VectorSearchService::class), $scopes, files: $files)
            ->readForTool(3, 20, null, 'shared:9:contracts', null);

        $this->assertFalse($read['isError']);
        $this->assertStringContainsString('Renews in May', $read['text']);
    }

    public function testSharedFileTheCallerCanOnlyViewIsRefused(): void
    {
        $scopes = $this->createMock(RagScopeResolver::class);
        $scopes->method('canUse')->willReturn(false);
        $files = $this->createMock(FileRepository::class);
        $files->expects($this->never())->method('find');

        $read = $this->helper($this->createStub(VectorSearchService::class), $scopes, files: $files)
            ->readForTool(3, 20, null, 'shared:9:contracts', null);

        $this->assertTrue($read['isError']);
        $this->assertSame(KnowledgeSearchHelper::REFUSED, $read['text']);
        $this->assertStringNotContainsString('Renews in May', $read['text']);
    }

    public function testForeignFileIsRefused(): void
    {
        $files = $this->createMock(FileRepository::class);
        $files->expects($this->once())->method('find')->with(99)->willReturn($this->file(8, 'secret', 'payroll.pdf', 'Salary 9000'));

        $read = $this->helper($this->createStub(VectorSearchService::class), files: $files)
            ->readForTool(3, 99, null, 'DESKTOP:personal', null);

        $this->assertTrue($read['isError']);
        $this->assertSame(KnowledgeSearchHelper::FILE_REFUSED, $read['text']);
        $this->assertStringNotContainsString('Salary 9000', $read['text']);
    }

    public function testAroundReturnsTheChunkAndItsNeighbours(): void
    {
        $lines = [];
        for ($i = 1; $i <= 400; ++$i) {
            $lines[] = sprintf('L%04d %s', $i, str_repeat('x', 40));
        }
        $files = $this->createMock(FileRepository::class);
        $files->method('find')->willReturn($this->file(3, 'DESKTOP:personal', 'long.txt', implode("\n", $lines)));
        $storage = $this->createMock(VectorStorageFacade::class);
        $storage->method('findChunk')->with(3, '34')->willReturn(new SearchResult(
            chunkId: 34,
            fileId: 12,
            groupKey: 'DESKTOP:personal',
            text: 'L0200',
            score: 0.0,
            startLine: 200,
            endLine: 200,
        ));

        $read = $this->helper($this->createStub(VectorSearchService::class), storage: $storage, files: $files)
            ->readForTool(3, 12, '34', 'DESKTOP:personal', null);

        $this->assertFalse($read['isError']);
        $this->assertStringContainsString('L0200', $read['text']);
        $this->assertStringContainsString('L0160', $read['text']);
        $this->assertStringContainsString('L0240', $read['text']);
        $this->assertStringNotContainsString('L0001', $read['text']);
        $this->assertStringNotContainsString('L0400', $read['text']);
    }

    public function testAShortFileComesBackWhole(): void
    {
        $files = $this->createMock(FileRepository::class);
        $files->method('find')->willReturn($this->file(3, 'DESKTOP:personal', 'note.txt', "alpha\nomega"));
        $storage = $this->createMock(VectorStorageFacade::class);
        $storage->method('findChunk')->willReturn(new SearchResult(
            chunkId: 1,
            fileId: 4,
            groupKey: 'DESKTOP:personal',
            text: 'alpha',
            score: 0.0,
            startLine: 1,
            endLine: 1,
        ));

        $read = $this->helper($this->createStub(VectorSearchService::class), storage: $storage, files: $files)
            ->readForTool(3, 4, '1', 'DESKTOP:personal', null);

        $this->assertStringContainsString("alpha\nomega", $read['text']);
    }

    public function testAssistantScopesAreSearchedWithoutOpeningAForeignFolder(): void
    {
        $scopes = $this->createMock(RagScopeResolver::class);
        $scopes->expects($this->never())->method('canUse');
        $vector = $this->createMock(VectorSearchService::class);
        $vector->expects($this->once())->method('semanticSearch')->with(
            'policy',
            3,
            null,
            5,
            0.3,
            $this->callback(static function (?array $explicit): bool {
                $scope = \is_array($explicit) ? ($explicit[0] ?? null) : null;

                return $scope instanceof RagScope
                    && 1 === \count($explicit)
                    && 9 === $scope->ownerId
                    && 'handbook' === $scope->groupKey;
            }),
        )->willReturn([]);

        $profile = new RuntimeProfile(null, '', null, [], [
            ['ownerId' => 9, 'groupKey' => 'handbook'],
        ], [], null, null, []);

        $found = $this->helper($vector, $scopes)->searchForTool(3, 'policy', null, $profile, 5);

        $this->assertNull($found['error']);
    }

    private function helper(
        VectorSearchService $vector,
        ?RagScopeResolver $scopes = null,
        ?VectorStorageFacade $storage = null,
        ?FileRepository $files = null,
    ): KnowledgeSearchHelper {
        return new KnowledgeSearchHelper(
            $vector,
            $scopes ?? $this->createStub(RagScopeResolver::class),
            $storage ?? $this->createStub(VectorStorageFacade::class),
            $files ?? $this->createStub(FileRepository::class),
        );
    }

    private function file(int $ownerId, string $groupKey, string $name, string $text): File
    {
        return (new File())
            ->setUserId($ownerId)
            ->setGroupKey($groupKey)
            ->setFileName($name)
            ->setFileText($text);
    }
}
