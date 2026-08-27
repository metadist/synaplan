<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Digest;

use App\Entity\MessageDigest;
use App\Repository\MessageDigestRepository;
use App\Service\Digest\MessageDigestConfig;
use App\Service\Digest\MessageDigestMaintenance;
use App\Service\VectorSearch\QdrantClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MessageDigestMaintenanceTest extends TestCase
{
    private const USER_ID = 7;

    private MessageDigestRepository&MockObject $digestRepository;
    private MessageDigestConfig&MockObject $config;
    private QdrantClientInterface&MockObject $qdrantClient;
    private MessageDigestMaintenance $maintenance;

    protected function setUp(): void
    {
        $this->digestRepository = $this->createMock(MessageDigestRepository::class);
        $this->config = $this->createMock(MessageDigestConfig::class);
        $this->qdrantClient = $this->createMock(QdrantClientInterface::class);

        $this->maintenance = new MessageDigestMaintenance(
            $this->digestRepository,
            $this->config,
            $this->qdrantClient,
            new NullLogger(),
        );
    }

    public function testUnderCapUserIsNotPruned(): void
    {
        $this->config->method('getMaxPerUser')->willReturn(5000);
        $this->digestRepository->method('countActiveForUser')->willReturn(4999);

        $this->digestRepository->expects(self::never())->method('findOldestActive');
        $this->digestRepository->expects(self::never())->method('deactivateByIds');
        $this->qdrantClient->expects(self::never())->method('deleteDigest');

        self::assertSame(0, $this->maintenance->pruneOverflow(self::USER_ID));
    }

    public function testOverflowDeactivatesOldestAndDeletesTheirPoints(): void
    {
        $this->config->method('getMaxPerUser')->willReturn(5000);
        $this->digestRepository->method('countActiveForUser')->willReturn(5002);

        $oldest = [$this->digest(101), $this->digest(102)];
        $this->digestRepository->method('findOldestActive')
            ->willReturnOnConsecutiveCalls($oldest, []);

        $deactivated = [];
        $this->digestRepository->method('deactivateByIds')
            ->willReturnCallback(static function (array $ids) use (&$deactivated): int {
                $deactivated[] = $ids;

                return count($ids);
            });

        $deletedPoints = [];
        $this->qdrantClient->method('deleteDigest')
            ->willReturnCallback(static function (string $pointId) use (&$deletedPoints): void {
                $deletedPoints[] = $pointId;
            });

        self::assertSame(2, $this->maintenance->pruneOverflow(self::USER_ID));
        self::assertSame([[101, 102]], $deactivated);
        self::assertSame(['dig_7_101', 'dig_7_102'], $deletedPoints);
    }

    public function testPruneToleratesQdrantOutage(): void
    {
        $this->config->method('getMaxPerUser')->willReturn(100);
        $this->digestRepository->method('countActiveForUser')->willReturn(101);
        $this->digestRepository->method('findOldestActive')
            ->willReturnOnConsecutiveCalls([$this->digest(55)], []);
        $this->digestRepository->expects(self::once())->method('deactivateByIds')->willReturn(1);

        $this->qdrantClient->method('deleteDigest')
            ->willThrowException(new \RuntimeException('qdrant down'));

        // DB soft-delete already happened; the orphaned vector is filtered by
        // the active payload flag and cleared by the next reindex.
        self::assertSame(1, $this->maintenance->pruneOverflow(self::USER_ID));
    }

    public function testChatDeletionDeactivatesItsDigestsAndPoints(): void
    {
        $this->digestRepository->method('findActiveByChat')
            ->willReturnCallback(fn (int $userId, int $chatId): array => (self::USER_ID === $userId && 42 === $chatId)
                ? [$this->digest(201), $this->digest(202)]
                : []);

        $deactivated = null;
        $this->digestRepository->method('deactivateByIds')
            ->willReturnCallback(static function (array $ids) use (&$deactivated): int {
                $deactivated = $ids;

                return count($ids);
            });

        $deletedPoints = [];
        $this->qdrantClient->method('deleteDigest')
            ->willReturnCallback(static function (string $pointId) use (&$deletedPoints): void {
                $deletedPoints[] = $pointId;
            });

        self::assertSame(2, $this->maintenance->deactivateForChat(self::USER_ID, 42));
        self::assertSame([201, 202], $deactivated);
        self::assertSame(['dig_7_201', 'dig_7_202'], $deletedPoints);
    }

    public function testChatWithoutDigestsIsANoOp(): void
    {
        $this->digestRepository->method('findActiveByChat')->willReturn([]);

        $this->digestRepository->expects(self::never())->method('deactivateByIds');
        $this->qdrantClient->expects(self::never())->method('deleteDigest');

        self::assertSame(0, $this->maintenance->deactivateForChat(self::USER_ID, 42));
    }

    private function digest(int $id): MessageDigest
    {
        $digest = new MessageDigest();
        $digest->setId($id)
            ->setUserId(self::USER_ID)
            ->setChatId(42)
            ->setMessageId($id * 10)
            ->setTitle('digest '.$id)
            ->setChannel('web')
            ->setSourceDate(1_700_000_000)
            ->setActive(true)
            ->setCreated(1_700_000_000);

        return $digest;
    }
}
