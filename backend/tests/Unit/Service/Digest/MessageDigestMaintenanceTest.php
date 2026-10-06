<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Digest;

use App\Entity\MessageDigest;
use App\Repository\MessageDigestRepository;
use App\Service\Digest\MessageDigestConfig;
use App\Service\Digest\MessageDigestMaintenance;
use App\Service\VectorSearch\QdrantClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MessageDigestMaintenanceTest extends TestCase
{
    private const USER_ID = 7;

    private MessageDigestRepository&MockObject $digestRepository;
    private MessageDigestConfig&Stub $config;
    private QdrantClientInterface&MockObject $qdrantClient;
    private MessageDigestMaintenance $maintenance;

    protected function setUp(): void
    {
        $this->digestRepository = $this->createMock(MessageDigestRepository::class);
        $this->config = $this->createStub(MessageDigestConfig::class);
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
        $this->qdrantClient->expects(self::never())->method('deleteDigests');

        self::assertSame(0, $this->maintenance->pruneOverflow(self::USER_ID));
    }

    public function testOverflowDeactivatesOldestAndDeletesTheirPoints(): void
    {
        $this->config->method('getMaxPerUser')->willReturn(5000);
        $this->digestRepository->expects(self::atLeastOnce())->method('countActiveForUser')->willReturn(5002);

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
        $this->qdrantClient->expects(self::atLeastOnce())->method('deleteDigests')
            ->willReturnCallback(static function (array $pointIds) use (&$deletedPoints): void {
                $deletedPoints[] = $pointIds;
            });

        self::assertSame(2, $this->maintenance->pruneOverflow(self::USER_ID));
        self::assertSame([[101, 102]], $deactivated);
        self::assertSame([['dig_7_101', 'dig_7_102']], $deletedPoints);
    }

    public function testPruneToleratesQdrantOutage(): void
    {
        $this->config->method('getMaxPerUser')->willReturn(100);
        $this->digestRepository->method('countActiveForUser')->willReturn(101);
        $this->digestRepository->method('findOldestActive')
            ->willReturnOnConsecutiveCalls([$this->digest(55)], []);
        $this->digestRepository->expects(self::once())->method('deactivateByIds')->willReturn(1);

        $this->qdrantClient->expects(self::once())->method('deleteDigests')
            ->willThrowException(new \RuntimeException('qdrant down'));

        // DB soft-delete already happened; the orphaned vector is filtered by
        // the active payload flag and cleared by the next reindex.
        self::assertSame(1, $this->maintenance->pruneOverflow(self::USER_ID));
    }

    public function testChatDeletionDeactivatesItsDigestsAndPoints(): void
    {
        $this->digestRepository->expects(self::atLeastOnce())->method('findActiveByChat')
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
        $this->qdrantClient->expects(self::atLeastOnce())->method('deleteDigests')
            ->willReturnCallback(static function (array $pointIds) use (&$deletedPoints): void {
                $deletedPoints[] = $pointIds;
            });

        self::assertSame(2, $this->maintenance->deactivateForChat(self::USER_ID, 42));
        self::assertSame([201, 202], $deactivated);
        self::assertSame([['dig_7_201', 'dig_7_202']], $deletedPoints);
    }

    public function testChatWithoutDigestsIsANoOp(): void
    {
        $this->digestRepository->method('findActiveByChat')->willReturn([]);

        $this->digestRepository->expects(self::never())->method('deactivateByIds');
        $this->qdrantClient->expects(self::never())->method('deleteDigests');

        self::assertSame(0, $this->maintenance->deactivateForChat(self::USER_ID, 42));
    }

    public function testDeactivateOwnedRemovesOnlyThatPoint(): void
    {
        $this->digestRepository->method('findActiveOwnedId')->willReturn(55);
        $this->digestRepository->expects(self::once())->method('deactivateByIds')->with([55])->willReturn(1);

        $batches = [];
        $this->qdrantClient->expects(self::never())->method('deleteDigest');
        $this->qdrantClient->method('deleteDigests')
            ->willReturnCallback(static function (array $pointIds) use (&$batches): void {
                $batches[] = $pointIds;
            });

        self::assertTrue($this->maintenance->deactivateOwned(self::USER_ID, 55));
        self::assertSame([['dig_7_55']], $batches);
    }

    public function testDeactivateOwnedIsANoOpWhenTheRowIsNotActiveForThisUser(): void
    {
        $this->digestRepository->method('findActiveOwnedId')->willReturn(null);

        $this->digestRepository->expects(self::never())->method('deactivateByIds');
        $this->qdrantClient->expects(self::never())->method('deleteDigests');

        self::assertFalse($this->maintenance->deactivateOwned(self::USER_ID, 55));
    }

    public function testDeactivateAllDeletesPointsInBatches(): void
    {
        $pages = [
            0 => [$this->digest(10), $this->digest(11)],
            11 => [$this->digest(20)],
        ];
        $this->digestRepository->expects(self::exactly(3))->method('findActiveForUserAfterId')
            ->willReturnCallback(function (int $userId, int $afterId, int $limit) use ($pages): array {
                self::assertSame(self::USER_ID, $userId);
                self::assertSame(500, $limit);

                return $pages[$afterId] ?? [];
            });

        $deactivated = [];
        $this->digestRepository->expects(self::exactly(2))->method('deactivateByIds')
            ->willReturnCallback(static function (array $ids) use (&$deactivated): int {
                $deactivated[] = $ids;

                return count($ids);
            });

        $batches = [];
        $this->qdrantClient->expects(self::never())->method('deleteDigest');
        $this->qdrantClient->expects(self::exactly(2))->method('deleteDigests')
            ->willReturnCallback(static function (array $pointIds) use (&$batches): void {
                $batches[] = $pointIds;
            });

        self::assertSame(3, $this->maintenance->deactivateAllActive(self::USER_ID));
        self::assertSame([[10, 11], [20]], $deactivated);
        self::assertSame([['dig_7_10', 'dig_7_11'], ['dig_7_20']], $batches);
    }

    public function testDeactivateAllWithNothingIsANoOp(): void
    {
        $this->digestRepository->method('findActiveForUserAfterId')->willReturn([]);

        $this->digestRepository->expects(self::never())->method('deactivateByIds');
        $this->qdrantClient->expects(self::never())->method('deleteDigests');

        self::assertSame(0, $this->maintenance->deactivateAllActive(self::USER_ID));
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
