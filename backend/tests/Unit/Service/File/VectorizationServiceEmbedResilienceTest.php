<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\AI\Service\AiFacade;
use App\Service\File\TextChunker;
use App\Service\File\VectorizationService;
use App\Service\ModelConfigService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit coverage for the resilient embedding pipeline added in PR #1036.
 *
 * {@see VectorizationService::embedChunksResilient()} tries a single batch
 * embedding call first. When that fails or returns invalid/incomplete
 * vectors it falls back to per-chunk embedding and skips only the bad
 * chunks. These tests pin that contract:
 *
 *   - Batch success (fast path) returns immediately.
 *   - Batch exception → per-chunk fallback.
 *   - Batch returning NaN vectors → per-chunk fallback.
 *   - Batch returning fewer vectors than chunks → per-chunk fallback.
 *   - Individual chunk failure is skipped (empty vector, failed++).
 *   - Empty/whitespace-only chunks get an empty vector without calling embed().
 *   - Usage tokens are aggregated across the fallback path.
 *
 * Also covers the two vector-validation helpers:
 *   - {@see VectorizationService::hasInvalidVector()}
 *   - {@see VectorizationService::vectorHasInvalidValue()}
 */
final class VectorizationServiceEmbedResilienceTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private VectorizationService $service;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);

        $this->service = new VectorizationService(
            $this->aiFacade,
            $this->createStub(TextChunker::class),
            $this->createStub(ModelConfigService::class),
            $this->createStub(VectorStorageFacade::class),
            $this->createStub(RateLimitService::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
    }

    // ------------------------------------------------------------------
    // embedChunksResilient — fast path (batch succeeds)
    // ------------------------------------------------------------------

    public function testBatchSuccessReturnImmediately(): void
    {
        $chunks = ['Hello world', 'Second chunk'];
        $vectors = [[0.1, 0.2], [0.3, 0.4]];

        $this->aiFacade->expects($this->once())
            ->method('embedBatch')
            ->willReturn([
                'embeddings' => $vectors,
                'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
            ]);

        $this->aiFacade->expects($this->never())->method('embed');

        $result = $this->invokeEmbedChunksResilient($chunks);

        $this->assertSame(0, $result['failed']);
        $this->assertSame($vectors, $result['embeddings']);
        $this->assertSame(10, $result['usage']['prompt_tokens']);
    }

    // ------------------------------------------------------------------
    // embedChunksResilient — fallback: batch throws
    // ------------------------------------------------------------------

    public function testBatchExceptionFallsBackToPerChunk(): void
    {
        $chunks = ['chunk-a', 'chunk-b'];

        $this->aiFacade->method('embedBatch')
            ->willThrowException(new \RuntimeException('HTTP 500'));

        $this->aiFacade->method('embed')
            ->willReturnCallback(fn (string $text) => [
                'embedding' => [1.0, 2.0],
                'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
            ]);

        $result = $this->invokeEmbedChunksResilient($chunks);

        $this->assertSame(0, $result['failed']);
        $this->assertCount(2, $result['embeddings']);
        $this->assertSame(10, $result['usage']['prompt_tokens']);
    }

    // ------------------------------------------------------------------
    // embedChunksResilient — fallback: batch returns NaN vector
    // ------------------------------------------------------------------

    public function testBatchWithNanVectorFallsBackToPerChunk(): void
    {
        $chunks = ['good chunk', 'bad chunk'];

        $this->aiFacade->method('embedBatch')
            ->willReturn([
                'embeddings' => [[0.1, 0.2], [NAN, 0.3]],
                'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
            ]);

        $this->aiFacade->method('embed')
            ->willReturnCallback(fn (string $text) => [
                'embedding' => [1.0, 2.0],
                'usage' => ['prompt_tokens' => 4, 'total_tokens' => 4],
            ]);

        $result = $this->invokeEmbedChunksResilient($chunks);

        $this->assertSame(0, $result['failed']);
        $this->assertSame([1.0, 2.0], $result['embeddings'][0]);
    }

    // ------------------------------------------------------------------
    // embedChunksResilient — fallback: batch returns fewer vectors
    // ------------------------------------------------------------------

    public function testBatchWithFewerVectorsFallsBack(): void
    {
        $chunks = ['a', 'b', 'c'];

        $this->aiFacade->method('embedBatch')
            ->willReturn([
                'embeddings' => [[0.1]],
                'usage' => ['prompt_tokens' => 3, 'total_tokens' => 3],
            ]);

        $this->aiFacade->method('embed')
            ->willReturn([
                'embedding' => [9.0],
                'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
            ]);

        $result = $this->invokeEmbedChunksResilient($chunks);

        $this->assertCount(3, $result['embeddings']);
        $this->assertSame(0, $result['failed']);
    }

    // ------------------------------------------------------------------
    // embedChunksResilient — individual chunk failure is skipped
    // ------------------------------------------------------------------

    public function testPerChunkFailureSkipsOnlyBadChunk(): void
    {
        $chunks = ['ok', 'will-fail', 'also-ok'];

        $this->aiFacade->method('embedBatch')
            ->willThrowException(new \RuntimeException('batch down'));

        $callIndex = 0;
        $this->aiFacade->method('embed')
            ->willReturnCallback(function (string $text) use (&$callIndex) {
                ++$callIndex;
                if ('will-fail' === $text) {
                    throw new \RuntimeException('chunk embed error');
                }

                return [
                    'embedding' => [1.0],
                    'usage' => ['prompt_tokens' => 2, 'total_tokens' => 2],
                ];
            });

        $result = $this->invokeEmbedChunksResilient($chunks);

        $this->assertSame(1, $result['failed']);
        $this->assertSame([1.0], $result['embeddings'][0]);
        $this->assertSame([], $result['embeddings'][1]);
        $this->assertSame([1.0], $result['embeddings'][2]);
        $this->assertSame(4, $result['usage']['prompt_tokens']);
    }

    // ------------------------------------------------------------------
    // embedChunksResilient — invalid individual vector is skipped
    // ------------------------------------------------------------------

    public function testPerChunkInvalidVectorIsSkipped(): void
    {
        $chunks = ['good', 'nan-chunk'];

        $this->aiFacade->method('embedBatch')
            ->willThrowException(new \RuntimeException('batch down'));

        $this->aiFacade->method('embed')
            ->willReturnCallback(fn (string $text) => 'nan-chunk' === $text
                ? ['embedding' => [NAN], 'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1]]
                : ['embedding' => [1.0], 'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1]]
            );

        $result = $this->invokeEmbedChunksResilient($chunks);

        $this->assertSame(1, $result['failed']);
        $this->assertSame([1.0], $result['embeddings'][0]);
        $this->assertSame([], $result['embeddings'][1]);
    }

    // ------------------------------------------------------------------
    // embedChunksResilient — empty/whitespace chunks skipped without call
    // ------------------------------------------------------------------

    public function testEmptyChunkSkippedWithoutEmbedCall(): void
    {
        $chunks = ['real text', '', '   '];

        $this->aiFacade->method('embedBatch')
            ->willThrowException(new \RuntimeException('batch down'));

        $this->aiFacade->expects($this->once())
            ->method('embed')
            ->with('real text', $this->anything(), $this->anything())
            ->willReturn([
                'embedding' => [5.0],
                'usage' => ['prompt_tokens' => 3, 'total_tokens' => 3],
            ]);

        $result = $this->invokeEmbedChunksResilient($chunks);

        $this->assertSame(0, $result['failed']);
        $this->assertSame([5.0], $result['embeddings'][0]);
        $this->assertSame([], $result['embeddings'][1]);
        $this->assertSame([], $result['embeddings'][2]);
    }

    // ------------------------------------------------------------------
    // hasInvalidVector
    // ------------------------------------------------------------------

    public function testHasInvalidVectorReturnsFalseForAllValid(): void
    {
        $this->assertFalse($this->invokeHasInvalidVector([[0.1, 0.2], [0.3, 0.4]]));
    }

    public function testHasInvalidVectorReturnsTrueForNan(): void
    {
        $this->assertTrue($this->invokeHasInvalidVector([[0.1, NAN]]));
    }

    public function testHasInvalidVectorReturnsTrueForInf(): void
    {
        $this->assertTrue($this->invokeHasInvalidVector([[INF, 0.2]]));
    }

    public function testHasInvalidVectorReturnsTrueForEmpty(): void
    {
        $this->assertTrue($this->invokeHasInvalidVector([[0.1], []]));
    }

    // ------------------------------------------------------------------
    // vectorHasInvalidValue
    // ------------------------------------------------------------------

    public function testVectorHasInvalidValueReturnsFalseForFinite(): void
    {
        $this->assertFalse($this->invokeVectorHasInvalidValue([0.0, 1.0, -1.0, 999.9]));
    }

    public function testVectorHasInvalidValueReturnsTrueForNan(): void
    {
        $this->assertTrue($this->invokeVectorHasInvalidValue([0.1, NAN, 0.3]));
    }

    public function testVectorHasInvalidValueReturnsTrueForInf(): void
    {
        $this->assertTrue($this->invokeVectorHasInvalidValue([0.1, INF]));
    }

    public function testVectorHasInvalidValueReturnsTrueForNegativeInf(): void
    {
        $this->assertTrue($this->invokeVectorHasInvalidValue([-INF, 0.2]));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param array<int, string> $chunkTexts
     *
     * @return array{embeddings: array<int, array<float>>, usage: array{prompt_tokens: int, total_tokens: int}, failed: int}
     */
    private function invokeEmbedChunksResilient(array $chunkTexts): array
    {
        $method = new \ReflectionMethod(VectorizationService::class, 'embedChunksResilient');

        /** @var array{embeddings: array<int, array<float>>, usage: array{prompt_tokens: int, total_tokens: int}, failed: int} $result */
        $result = $method->invoke($this->service, $chunkTexts, 1, 'ollama', 'bge-m3');

        return $result;
    }

    /**
     * @param array<int, array<float>> $vectors
     */
    private function invokeHasInvalidVector(array $vectors): bool
    {
        $method = new \ReflectionMethod(VectorizationService::class, 'hasInvalidVector');

        return $method->invoke($this->service, $vectors);
    }

    /**
     * @param array<float> $vector
     */
    private function invokeVectorHasInvalidValue(array $vector): bool
    {
        $method = new \ReflectionMethod(VectorizationService::class, 'vectorHasInvalidValue');

        return $method->invoke($this->service, $vector);
    }
}
