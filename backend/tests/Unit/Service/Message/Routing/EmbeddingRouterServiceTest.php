<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Routing;

use App\AI\Service\AiFacade;
use App\Service\Message\Routing\EmbeddingRouterService;
use App\Service\VectorSearch\QdrantClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for the Phase 8 embedding-router cascade layer.
 *
 * Per the class docblock, {@see EmbeddingRouterService::findClosestAnchor()}
 * only ever answers "what's the closest anchor and how close is it" — the
 * confidence-threshold decision belongs to the caller. These tests therefore
 * assert on the raw match (or null), never on a threshold.
 */
final class EmbeddingRouterServiceTest extends TestCase
{
    private const VECTOR = [0.1, 0.2, 0.3];

    public function testReturnsNullForEmptyText(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->never())->method('embed');
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->expects($this->never())->method('searchRoutingAnchors');

        $service = new EmbeddingRouterService($aiFacade, $qdrant, new NullLogger());

        $this->assertNull($service->findClosestAnchor('   '));
    }

    public function testReturnsNullWhenEmbeddingThrows(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('embed')->willThrowException(new \RuntimeException('provider down'));
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->expects($this->never())->method('searchRoutingAnchors');

        $service = new EmbeddingRouterService($aiFacade, $qdrant, new NullLogger());

        $this->assertNull($service->findClosestAnchor('Hello, how are you?'));
    }

    public function testReturnsNullWhenEmbeddingIsEmpty(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('embed')->willReturn(['embedding' => [], 'usage' => []]);
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->expects($this->never())->method('searchRoutingAnchors');

        $service = new EmbeddingRouterService($aiFacade, $qdrant, new NullLogger());

        $this->assertNull($service->findClosestAnchor('Hello, how are you?'));
    }

    public function testReturnsNullWhenNoAnchorsExist(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('embed')->willReturn(['embedding' => self::VECTOR, 'usage' => []]);
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->method('searchRoutingAnchors')->willReturn([]);

        $service = new EmbeddingRouterService($aiFacade, $qdrant, new NullLogger());

        $this->assertNull($service->findClosestAnchor('Hello, how are you?'));
    }

    public function testReturnsNullWhenTopAnchorPayloadHasNoTopic(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('embed')->willReturn(['embedding' => self::VECTOR, 'usage' => []]);
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->method('searchRoutingAnchors')->willReturn([
            ['id' => 'x', 'score' => 0.99, 'payload' => []],
        ]);

        $service = new EmbeddingRouterService($aiFacade, $qdrant, new NullLogger());

        $this->assertNull($service->findClosestAnchor('Hello, how are you?'));
    }

    public function testReturnsBestMatchWithScoreAndTopic(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('embed')->willReturn(['embedding' => self::VECTOR, 'usage' => []]);
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->method('searchRoutingAnchors')->willReturn([
            ['id' => 'a', 'score' => 0.94, 'payload' => ['topic' => 'mediamaker']],
        ]);

        $service = new EmbeddingRouterService($aiFacade, $qdrant, new NullLogger());
        $match = $service->findClosestAnchor('Make an image of a cat');

        $this->assertNotNull($match);
        $this->assertSame('mediamaker', $match->topic);
        $this->assertSame(0.94, $match->score);
        $this->assertSame([], $match->discardedAlternatives);
    }

    public function testDiscardedAlternativesExcludeSameTopicRunnerUps(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('embed')->willReturn(['embedding' => self::VECTOR, 'usage' => []]);
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->method('searchRoutingAnchors')->willReturn([
            ['id' => 'a', 'score' => 0.94, 'payload' => ['topic' => 'mediamaker']],
            // Same topic as the winner: adds no routing information, must be excluded.
            ['id' => 'b', 'score' => 0.90, 'payload' => ['topic' => 'mediamaker']],
            ['id' => 'c', 'score' => 0.55, 'payload' => ['topic' => 'general']],
            // Malformed payload without a topic must also be excluded.
            ['id' => 'd', 'score' => 0.40, 'payload' => []],
        ]);

        $service = new EmbeddingRouterService($aiFacade, $qdrant, new NullLogger());
        $match = $service->findClosestAnchor('Make an image of a cat');

        $this->assertNotNull($match);
        $this->assertSame('mediamaker', $match->topic);
        $this->assertSame([['topic' => 'general', 'score' => 0.55]], $match->discardedAlternatives);
    }

    public function testPassesUserIdThroughToEmbedForModelResolution(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->once())
            ->method('embed')
            ->with('hi', 42)
            ->willReturn(['embedding' => self::VECTOR, 'usage' => []]);
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->method('searchRoutingAnchors')->willReturn([]);

        $service = new EmbeddingRouterService($aiFacade, $qdrant, new NullLogger());
        $service->findClosestAnchor('hi', 42);
    }
}
