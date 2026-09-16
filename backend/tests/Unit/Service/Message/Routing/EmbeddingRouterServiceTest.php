<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Routing;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Routing\EmbeddingRouterService;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\VectorSearch\QdrantClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
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

    private RateLimitService&MockObject $rateLimitService;

    protected function setUp(): void
    {
        $this->rateLimitService = $this->createMock(RateLimitService::class);
    }

    /**
     * @param User|null $user the user the EntityManager resolves, or null for
     *                        "not found"
     */
    private function service(
        AiFacade $aiFacade,
        QdrantClientInterface $qdrant,
        ?User $user = null,
        ?int $vectorizeModelId = null,
    ): EmbeddingRouterService {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($user);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->willReturn($vectorizeModelId);
        $modelConfig->method('getProviderForModel')->willReturn('ollama');
        $modelConfig->method('getModelName')->willReturn('bge-m3');

        return new EmbeddingRouterService(
            $aiFacade,
            $qdrant,
            new NullLogger(),
            new SystemCapabilityRegistry(),
            $this->rateLimitService,
            $em,
            $modelConfig,
        );
    }

    public function testReturnsNullForEmptyText(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->expects($this->never())->method('embed');
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->expects($this->never())->method('searchRoutingAnchors');

        $this->assertNull($this->service($aiFacade, $qdrant)->findClosestAnchor('   '));
    }

    public function testReturnsNullWhenEmbeddingThrows(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('embed')->willThrowException(new \RuntimeException('provider down'));
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->expects($this->never())->method('searchRoutingAnchors');

        $this->assertNull($this->service($aiFacade, $qdrant)->findClosestAnchor('Hello, how are you?'));
    }

    public function testReturnsNullWhenEmbeddingIsEmpty(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('embed')->willReturn(['embedding' => [], 'usage' => []]);
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->expects($this->never())->method('searchRoutingAnchors');

        $this->assertNull($this->service($aiFacade, $qdrant)->findClosestAnchor('Hello, how are you?'));
    }

    public function testReturnsNullWhenNoAnchorsExist(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('embed')->willReturn(['embedding' => self::VECTOR, 'usage' => []]);
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->method('searchRoutingAnchors')->willReturn([]);

        $this->assertNull($this->service($aiFacade, $qdrant)->findClosestAnchor('Hello, how are you?'));
    }

    public function testReturnsNullWhenTopAnchorPayloadHasNoTopic(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('embed')->willReturn(['embedding' => self::VECTOR, 'usage' => []]);
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->method('searchRoutingAnchors')->willReturn([
            ['id' => 'x', 'score' => 0.99, 'payload' => []],
        ]);

        $this->assertNull($this->service($aiFacade, $qdrant)->findClosestAnchor('Hello, how are you?'));
    }

    /**
     * A stale anchor (capability renamed since the last sync) or a tampered
     * payload must not be able to inject an arbitrary topic into routing, no
     * matter how confident the vector match looks.
     */
    public function testReturnsNullWhenTopAnchorTopicIsNotAKnownCapability(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('embed')->willReturn(['embedding' => self::VECTOR, 'usage' => []]);
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->method('searchRoutingAnchors')->willReturn([
            ['id' => 'x', 'score' => 0.99, 'payload' => ['topic' => 'exfiltrate_everything']],
        ]);

        $this->assertNull($this->service($aiFacade, $qdrant)->findClosestAnchor('Hello, how are you?'));
    }

    public function testReturnsBestMatchWithScoreAndTopic(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('embed')->willReturn(['embedding' => self::VECTOR, 'usage' => []]);
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->method('searchRoutingAnchors')->willReturn([
            ['id' => 'a', 'score' => 0.94, 'payload' => ['topic' => 'mediamaker']],
        ]);

        $match = $this->service($aiFacade, $qdrant)->findClosestAnchor('Make an image of a cat');

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
            // Unknown topic: excluded for the same reason the winner would be.
            ['id' => 'e', 'score' => 0.30, 'payload' => ['topic' => 'not_a_capability']],
        ]);

        $match = $this->service($aiFacade, $qdrant)->findClosestAnchor('Make an image of a cat');

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

        $this->service($aiFacade, $qdrant)->findClosestAnchor('hi', 42);
    }

    /**
     * One embedding per message is real spend. Left unbooked it is invisible
     * to both the user's quota and the cost meter.
     */
    public function testBooksTheEmbeddingAgainstTheUsersQuota(): void
    {
        $user = new User();
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('embed')->willReturn([
            'embedding' => self::VECTOR,
            'usage' => ['prompt_tokens' => 12, 'total_tokens' => 12],
        ]);
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->method('searchRoutingAnchors')->willReturn([
            ['id' => 'a', 'score' => 0.94, 'payload' => ['topic' => 'mediamaker']],
        ]);

        $this->rateLimitService
            ->expects($this->once())
            ->method('recordUsage')
            ->with($user, 'EMBEDDINGS', $this->callback(function (array $metadata): bool {
                self::assertSame(['prompt_tokens' => 12, 'total_tokens' => 12], $metadata['usage']);
                self::assertSame('EMBEDDING_ROUTER', $metadata['source']);
                self::assertSame(7, $metadata['model_id']);
                self::assertSame('ollama', $metadata['provider']);
                self::assertSame('bge-m3', $metadata['model']);
                self::assertSame('Make an image of a cat', $metadata['input_text']);

                return true;
            }));

        $this->service($aiFacade, $qdrant, $user, 7)->findClosestAnchor('Make an image of a cat', 42);
    }

    /**
     * Booking must not depend on the anchor lookup succeeding — the embedding
     * was paid for either way.
     */
    public function testBooksTheEmbeddingEvenWhenNoAnchorMatches(): void
    {
        $user = new User();
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('embed')->willReturn(['embedding' => self::VECTOR, 'usage' => []]);
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->method('searchRoutingAnchors')->willReturn([]);

        $this->rateLimitService->expects($this->once())->method('recordUsage');

        $this->service($aiFacade, $qdrant, $user, 7)->findClosestAnchor('hi', 42);
    }

    public function testAnonymousCallsBookNothing(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('embed')->willReturn(['embedding' => self::VECTOR, 'usage' => []]);
        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->method('searchRoutingAnchors')->willReturn([]);

        $this->rateLimitService->expects($this->never())->method('recordUsage');

        $this->service($aiFacade, $qdrant)->findClosestAnchor('hi');
    }
}
