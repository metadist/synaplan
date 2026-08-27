<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Digest;

use App\Entity\Message;
use App\Entity\User;
use App\Repository\MessageDigestRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\Digest\MessageDigestConfig;
use App\Service\Digest\MessageDigestMaintenance;
use App\Service\Digest\MessageDigestRunner;
use App\Service\Digest\MessageDigestService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MessageDigestRunnerTest extends TestCase
{
    private MessageDigestService&MockObject $digestService;
    private MessageDigestConfig&MockObject $config;
    private MessageRepository&MockObject $messageRepository;
    private MessageDigestRepository&MockObject $digestRepository;
    private MessageDigestMaintenance&MockObject $maintenance;
    private UserRepository&MockObject $userRepository;
    private MessageDigestRunner $runner;

    protected function setUp(): void
    {
        $this->digestService = $this->createMock(MessageDigestService::class);
        $this->config = $this->createMock(MessageDigestConfig::class);
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->digestRepository = $this->createMock(MessageDigestRepository::class);
        $this->maintenance = $this->createMock(MessageDigestMaintenance::class);
        $this->userRepository = $this->createMock(UserRepository::class);

        $this->config->method('getBatchSize')->willReturn(25);
        $this->config->method('getQuietSeconds')->willReturn(3600);
        $this->config->method('getMaxBatchesPerUser')->willReturn(4);

        $this->runner = new MessageDigestRunner(
            $this->digestService,
            $this->config,
            $this->messageRepository,
            $this->digestRepository,
            $this->maintenance,
            $this->userRepository,
            new NullLogger(),
        );
    }

    public function testDisabledConfigSkipsTheWholeRun(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $this->messageRepository->expects(self::never())->method('findDistinctUserIds');
        $this->digestService->expects(self::never())->method('digestBatch');

        $summary = $this->runner->run();

        self::assertSame(0, $summary['users']);
        self::assertSame(0, $summary['batches']);
    }

    public function testUsersWithMemoriesDisabledAreSkipped(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->messageRepository->method('findDistinctUserIds')->willReturn([7]);

        $user = $this->makeUser(7);
        $user->setMemoriesEnabled(false);
        $this->userRepository->method('find')->willReturn($user);

        $this->digestService->expects(self::never())->method('digestBatch');

        $summary = $this->runner->run();

        self::assertSame(1, $summary['skipped_users']);
        self::assertSame(0, $summary['batches']);
    }

    public function testCursorAdvancesPerBatchAndIsPersisted(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('getCursor')->willReturn(100);
        $this->digestRepository->method('maxMessageIdForUser')->willReturn(90);

        $batch1 = [$this->makeMessage(101), $this->makeMessage(120)];
        $batch2 = [$this->makeMessage(140)];

        $capturedAfterIds = [];
        $this->messageRepository->method('findDigestCandidates')
            ->willReturnCallback(function (int $userId, int $afterId) use (&$capturedAfterIds, $batch1, $batch2): array {
                $capturedAfterIds[] = $afterId;

                return match (count($capturedAfterIds)) {
                    1 => $batch1,
                    2 => $batch2,
                    default => [],
                };
            });

        $this->digestService->method('digestBatch')
            ->willReturn(['scanned' => 2, 'created' => 1, 'proposals' => []]);

        $persistedCursors = [];
        $this->config->method('setCursor')
            ->willReturnCallback(static function (int $userId, int $messageId) use (&$persistedCursors): void {
                $persistedCursors[] = $messageId;
            });

        $result = $this->runner->runForUser($user, maxBatches: 4);

        // Stored cursor (100) beats the digest-table max (90) as starting point.
        self::assertSame([100, 120, 140], $capturedAfterIds);
        self::assertSame([120, 140], $persistedCursors);
        self::assertSame(2, $result['batches']);
        self::assertSame(140, $result['cursor']);
    }

    public function testMaxBatchesCapsTheModelCalls(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('getCursor')->willReturn(0);
        $this->digestRepository->method('maxMessageIdForUser')->willReturn(0);

        $callCount = 0;
        $this->messageRepository->method('findDigestCandidates')
            ->willReturnCallback(function () use (&$callCount): array {
                ++$callCount;

                return [$this->makeMessage($callCount * 10)];
            });

        $this->digestService->expects(self::exactly(2))
            ->method('digestBatch')
            ->willReturn(['scanned' => 1, 'created' => 0, 'proposals' => []]);

        $result = $this->runner->runForUser($user, maxBatches: 2);

        self::assertSame(2, $result['batches']);
    }

    public function testBackfillNeverTouchesTheStoredCursor(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $user = $this->makeUser(7);
        $this->userRepository->method('find')->willReturn($user);

        $this->messageRepository->method('findDigestCandidates')
            ->willReturnOnConsecutiveCalls([$this->makeMessage(50)], []);
        $this->digestService->method('digestBatch')
            ->willReturn(['scanned' => 1, 'created' => 1, 'proposals' => []]);

        $this->config->expects(self::never())->method('setCursor');
        // Backfill starts from id 0, ignoring both cursor sources.
        $this->config->expects(self::never())->method('getCursor');
        $this->digestRepository->expects(self::never())->method('maxMessageIdForUser');

        $summary = $this->runner->backfill(onlyUserId: 7, sinceUnix: 1_000_000);

        self::assertSame(1, $summary['batches']);
        self::assertSame(1, $summary['created']);
    }

    public function testDryRunDoesNotPersistTheCursor(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('getCursor')->willReturn(0);
        $this->digestRepository->method('maxMessageIdForUser')->willReturn(0);

        $this->messageRepository->method('findDigestCandidates')
            ->willReturnOnConsecutiveCalls([$this->makeMessage(50)], []);
        $this->digestService->method('digestBatch')
            ->with(self::anything(), self::anything(), true)
            ->willReturn(['scanned' => 1, 'created' => 0, 'proposals' => []]);

        $this->config->expects(self::never())->method('setCursor');

        $this->runner->runForUser($user, maxBatches: 4, dryRun: true);
    }

    public function testPruneRunsAfterAUserPassThatCreatedDigests(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('getCursor')->willReturn(0);
        $this->digestRepository->method('maxMessageIdForUser')->willReturn(0);

        $this->messageRepository->method('findDigestCandidates')
            ->willReturnOnConsecutiveCalls([$this->makeMessage(50)], []);
        $this->digestService->method('digestBatch')
            ->willReturn(['scanned' => 1, 'created' => 1, 'proposals' => []]);

        $this->maintenance->expects(self::once())->method('pruneOverflow')->with(7);

        $this->runner->runForUser($user, maxBatches: 4);
    }

    public function testPruneIsSkippedWhenNothingWasCreatedOrOnDryRun(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('getCursor')->willReturn(0);
        $this->digestRepository->method('maxMessageIdForUser')->willReturn(0);

        $this->messageRepository->method('findDigestCandidates')
            ->willReturnOnConsecutiveCalls([$this->makeMessage(50)], [], [$this->makeMessage(60)], []);
        $this->digestService->method('digestBatch')
            ->willReturnOnConsecutiveCalls(
                ['scanned' => 1, 'created' => 0, 'proposals' => []],
                ['scanned' => 1, 'created' => 1, 'proposals' => [['title' => 'x', 'message_id' => 60]]],
            );

        $this->maintenance->expects(self::never())->method('pruneOverflow');

        // Pass 1: nothing created. Pass 2: created, but dry run.
        $this->runner->runForUser($user, maxBatches: 4);
        $this->runner->runForUser($user, maxBatches: 4, dryRun: true);
    }

    public function testQuietPeriodBoundsTheCandidateQuery(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('getCursor')->willReturn(0);
        $this->digestRepository->method('maxMessageIdForUser')->willReturn(0);

        $capturedBeforeUnix = null;
        $this->messageRepository->method('findDigestCandidates')
            ->willReturnCallback(function (int $userId, int $afterId, int $beforeUnix) use (&$capturedBeforeUnix): array {
                $capturedBeforeUnix = $beforeUnix;

                return [];
            });

        $before = time();
        $this->runner->runForUser($user, maxBatches: 1);
        $after = time();

        self::assertNotNull($capturedBeforeUnix);
        self::assertGreaterThanOrEqual($before - 3600, $capturedBeforeUnix);
        self::assertLessThanOrEqual($after - 3600, $capturedBeforeUnix);
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $idProperty = new \ReflectionProperty(User::class, 'id');
        $idProperty->setValue($user, $id);

        return $user;
    }

    private function makeMessage(int $id): Message
    {
        $message = new Message();
        $idProperty = new \ReflectionProperty(Message::class, 'id');
        $idProperty->setValue($message, $id);

        $message->setUserId(7);
        $message->setTrackingId(0);
        $message->setUnixTimestamp(1_700_000_000);
        $message->setDateTime('20231114000000');
        $message->setMessageType('WEB');
        $message->setDirection('IN');
        $message->setText('message '.$id);
        $message->setFile(0);
        $message->setFilePath('');
        $message->setFileType('');
        $message->setFileText('');
        $message->setChatId(3);

        return $message;
    }
}
