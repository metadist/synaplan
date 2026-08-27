<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Digest;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\Digest\DigestSearchService;
use App\Service\Digest\MessageDigestConfig;
use App\Service\VectorSearch\QdrantClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DigestSearchServiceTest extends TestCase
{
    private const USER_ID = 7;
    private const NOW = 1_760_000_000;

    private QdrantClientInterface&MockObject $qdrantClient;
    private MessageRepository&MockObject $messageRepository;
    private MessageDigestConfig&MockObject $config;
    private DigestSearchService $service;

    protected function setUp(): void
    {
        $this->qdrantClient = $this->createMock(QdrantClientInterface::class);
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->config = $this->createMock(MessageDigestConfig::class);

        $this->config->method('getTopK')->willReturn(5);
        $this->config->method('getMinScore')->willReturn(0.5);
        $this->config->method('getRecencyHalfLifeDays')->willReturn(180);
        $this->config->method('getPullTopN')->willReturn(2);
        $this->config->method('getPullMinScore')->willReturn(0.6);

        $this->service = new DigestSearchService(
            $this->qdrantClient,
            $this->messageRepository,
            $this->config,
            new NullLogger(),
        );
    }

    public function testEmptyQueryVectorReturnsNoHitsWithoutSearching(): void
    {
        $this->qdrantClient->expects($this->never())->method('searchDigests');

        self::assertSame([], $this->service->search(self::USER_ID, []));
    }

    public function testReturnsHitsWithTitlesAndScores(): void
    {
        $this->qdrantClient->method('searchDigests')->willReturn([
            $this->qdrantHit(1234, 42, 'office rent letter about the increase', 0.9, self::NOW - 86400),
        ]);
        $this->messageRepository->method('find')->willReturn(
            $this->message(1234, self::USER_ID, 'Letter text about the rent increase.')
        );

        $hits = $this->service->search(self::USER_ID, [0.1, 0.2], now: self::NOW);

        self::assertCount(1, $hits);
        self::assertSame(1234, $hits[0]['message_id']);
        self::assertSame('office rent letter about the increase', $hits[0]['title']);
        self::assertSame('Letter text about the rent increase.', $hits[0]['excerpt']);
    }

    public function testRecencyDecayReordersAnOldSlightlyBetterHit(): void
    {
        // Old hit scores 0.80 raw but is one full half-life old (-> 0.40
        // effective); the fresh 0.70 hit must win.
        $halfLife = 180 * 86400;
        $this->qdrantClient->method('searchDigests')->willReturn([
            $this->qdrantHit(1, 10, 'old but slightly better', 0.80, self::NOW - $halfLife),
            $this->qdrantHit(2, 11, 'fresh and nearly as good', 0.70, self::NOW),
        ]);
        $this->messageRepository->method('find')->willReturn(null);

        $hits = $this->service->search(self::USER_ID, [0.1], now: self::NOW);

        self::assertSame([2, 1], array_column($hits, 'message_id'));
        self::assertEqualsWithDelta(0.40, $hits[1]['effective_score'], 0.001);
    }

    public function testExcludesDigestsFromTheCurrentChat(): void
    {
        $this->qdrantClient->method('searchDigests')->willReturn([
            $this->qdrantHit(1, 99, 'from the current chat', 0.9, self::NOW),
            $this->qdrantHit(2, 11, 'from an older chat', 0.8, self::NOW),
        ]);
        $this->messageRepository->method('find')->willReturn(null);

        $hits = $this->service->search(self::USER_ID, [0.1], excludeChatId: 99, now: self::NOW);

        self::assertSame([2], array_column($hits, 'message_id'));
    }

    public function testCapsResultsAtTopK(): void
    {
        $rawHits = [];
        for ($i = 1; $i <= 10; ++$i) {
            $rawHits[] = $this->qdrantHit($i, 10 + $i, "digest $i", 1.0 - $i * 0.01, self::NOW);
        }
        $this->qdrantClient->method('searchDigests')->willReturn($rawHits);
        $this->messageRepository->method('find')->willReturn(null);

        $hits = $this->service->search(self::USER_ID, [0.1], now: self::NOW);

        self::assertCount(5, $hits);
        self::assertSame([1, 2, 3, 4, 5], array_column($hits, 'message_id'));
    }

    public function testPullsExcerptsOnlyForTopNAboveMinScore(): void
    {
        $this->qdrantClient->method('searchDigests')->willReturn([
            $this->qdrantHit(1, 10, 'first', 0.9, self::NOW),
            $this->qdrantHit(2, 11, 'second', 0.8, self::NOW),
            $this->qdrantHit(3, 12, 'third above pull threshold but budget spent', 0.7, self::NOW),
            $this->qdrantHit(4, 13, 'below pull threshold', 0.55, self::NOW),
        ]);
        $this->messageRepository->method('find')->willReturnCallback(
            fn (int $id): Message => $this->message($id, self::USER_ID, "text of message $id")
        );

        $hits = $this->service->search(self::USER_ID, [0.1], now: self::NOW);

        self::assertSame('text of message 1', $hits[0]['excerpt']);
        self::assertSame('text of message 2', $hits[1]['excerpt']);
        self::assertNull($hits[2]['excerpt']);
        self::assertNull($hits[3]['excerpt']);
    }

    public function testNeverPullsAForeignUsersMessage(): void
    {
        $this->qdrantClient->method('searchDigests')->willReturn([
            $this->qdrantHit(1, 10, 'points at someone elses message', 0.9, self::NOW),
        ]);
        $this->messageRepository->method('find')->willReturn(
            $this->message(1, self::USER_ID + 1, 'secret text of another user')
        );

        $hits = $this->service->search(self::USER_ID, [0.1], now: self::NOW);

        self::assertCount(1, $hits);
        self::assertNull($hits[0]['excerpt']);
    }

    public function testLongExcerptIsClipped(): void
    {
        $this->qdrantClient->method('searchDigests')->willReturn([
            $this->qdrantHit(1, 10, 'long document', 0.9, self::NOW),
        ]);
        $this->messageRepository->method('find')->willReturn(
            $this->message(1, self::USER_ID, str_repeat('a', 5000))
        );

        $hits = $this->service->search(self::USER_ID, [0.1], now: self::NOW);

        self::assertNotNull($hits[0]['excerpt']);
        self::assertSame(1501, mb_strlen($hits[0]['excerpt']));
        self::assertStringEndsWith('…', $hits[0]['excerpt']);
    }

    public function testEffectiveScoreHalvesPerHalfLifeAndToleratesZeroHalfLife(): void
    {
        self::assertEqualsWithDelta(0.8, DigestSearchService::effectiveScore(0.8, 0, 100), 1e-9);
        self::assertEqualsWithDelta(0.4, DigestSearchService::effectiveScore(0.8, 100, 100), 1e-9);
        self::assertEqualsWithDelta(0.2, DigestSearchService::effectiveScore(0.8, 200, 100), 1e-9);
        self::assertEqualsWithDelta(0.8, DigestSearchService::effectiveScore(0.8, 500, 0), 1e-9);
    }

    public function testQdrantOutageReturnsEmptyInsteadOfThrowing(): void
    {
        $this->qdrantClient->method('searchDigests')
            ->willThrowException(new \RuntimeException('qdrant down'));

        self::assertSame([], $this->service->search(self::USER_ID, [0.1], now: self::NOW));
    }

    public function testSkipsMalformedPayloads(): void
    {
        $this->qdrantClient->method('searchDigests')->willReturn([
            ['score' => 0.9, 'payload' => ['message_id' => 0, 'title' => 'no message id']],
            ['score' => 0.9, 'payload' => ['message_id' => 5, 'title' => '   ']],
            $this->qdrantHit(6, 10, 'valid', 0.8, self::NOW),
        ]);
        $this->messageRepository->method('find')->willReturn(null);

        $hits = $this->service->search(self::USER_ID, [0.1], now: self::NOW);

        self::assertSame([6], array_column($hits, 'message_id'));
    }

    /**
     * @return array{score: float, payload: array<string, mixed>}
     */
    private function qdrantHit(int $messageId, int $chatId, string $title, float $score, int $sourceDate): array
    {
        return [
            'score' => $score,
            'payload' => [
                'message_id' => $messageId,
                'chat_id' => $chatId,
                'title' => $title,
                'channel' => 'web',
                'source_date' => $sourceDate,
            ],
        ];
    }

    private function message(int $id, int $userId, string $text): Message
    {
        $message = new Message();
        $idProperty = new \ReflectionProperty(Message::class, 'id');
        $idProperty->setValue($message, $id);
        $message->setUserId($userId);
        $message->setText($text);

        return $message;
    }
}
