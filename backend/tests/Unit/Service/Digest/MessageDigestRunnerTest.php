<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Digest;

use App\AI\Exception\ChatFailureReason;
use App\Entity\Config;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\Digest\MessageDigestConfig;
use App\Service\Digest\MessageDigestMaintenance;
use App\Service\Digest\MessageDigestRunner;
use App\Service\Digest\MessageDigestService;
use App\Service\RateLimitService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class MessageDigestRunnerTest extends TestCase
{
    private MessageDigestService&MockObject $digestService;
    private MessageDigestConfig&MockObject $config;
    private MessageRepository&MockObject $messageRepository;
    private MessageDigestMaintenance&MockObject $maintenance;
    private UserRepository&MockObject $userRepository;
    private RateLimitService&MockObject $rateLimitService;
    private LoggerInterface $logger;
    private MessageDigestRunner $runner;
    private bool $budgetAllowed = true;
    private ?int $lowestSkippedId = null;

    /** @var list<int> */
    private array $lowestSkippedAfterIds = [];

    /** @var array<int, array{start: int, count: int}> */
    private array $cursorFailures = [];

    /** @var list<string> */
    private array $failureWrites = [];

    protected function setUp(): void
    {
        $this->digestService = $this->createMock(MessageDigestService::class);
        $this->config = $this->createMock(MessageDigestConfig::class);
        $this->messageRepository = $this->createMock(MessageRepository::class);
        $this->maintenance = $this->createMock(MessageDigestMaintenance::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->logger = new NullLogger();
        $this->budgetAllowed = true;
        $this->lowestSkippedId = null;
        $this->lowestSkippedAfterIds = [];
        $this->cursorFailures = [];
        $this->failureWrites = [];

        $this->config->method('getBatchSize')->willReturn(25);
        $this->config->method('getQuietSeconds')->willReturn(3600);
        $this->config->method('getMaxBatchesPerUser')->willReturn(4);
        $this->config->method('getCursorFailures')->willReturnCallback($this->cursorFailureFor(...));
        $this->config->method('setCursorFailures')->willReturnCallback(
            function (int $userId, int $start, int $count): void {
                $this->failureWrites[] = $userId.':'.$start.':'.$count;
                $this->cursorFailures[$userId] = ['start' => $start, 'count' => $count];
            },
        );
        $this->config->method('clearCursorFailures')->willReturnCallback(
            function (int $userId): void {
                $this->failureWrites[] = 'clear:'.$userId;
                unset($this->cursorFailures[$userId]);
            },
        );
        $this->rateLimitService->method('checkCostBudget')->willReturnCallback(
            fn (User $user): array => ['allowed' => $this->budgetAllowed],
        );
        $this->messageRepository->method('lowestSkippedDigestCandidateId')->willReturnCallback(
            function (int $userId, int $afterId): ?int {
                $this->lowestSkippedAfterIds[] = $afterId;

                return $this->lowestSkippedId;
            },
        );

        $this->runner = $this->makeRunner();
    }

    private function makeRunner(): MessageDigestRunner
    {
        return new MessageDigestRunner(
            $this->digestService,
            $this->config,
            $this->messageRepository,
            $this->maintenance,
            $this->userRepository,
            $this->rateLimitService,
            $this->logger,
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
            ->willReturn(['scanned' => 2, 'created' => 1, 'proposals' => [], 'failed' => false, 'failureReason' => null]);

        $persistedCursors = [];
        $this->config->method('advanceCursor')
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
        $callCount = 0;
        $this->messageRepository->method('findDigestCandidates')
            ->willReturnCallback(function () use (&$callCount): array {
                ++$callCount;

                return [$this->makeMessage($callCount * 10)];
            });

        $this->digestService->expects(self::exactly(2))
            ->method('digestBatch')
            ->willReturn(['scanned' => 1, 'created' => 0, 'proposals' => [], 'failed' => false, 'failureReason' => null]);

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
            ->willReturn(['scanned' => 1, 'created' => 1, 'proposals' => [], 'failed' => false, 'failureReason' => null]);

        $this->config->expects(self::never())->method('setCursor');
        $this->config->expects(self::never())->method('advanceCursor');
        // Backfill starts from id 0, ignoring both cursor sources.
        $this->config->expects(self::never())->method('getCursor');
        $summary = $this->runner->backfill(onlyUserId: 7, sinceUnix: 1_000_000);

        self::assertSame(1, $summary['batches']);
        self::assertSame(1, $summary['created']);
    }

    public function testDryRunDoesNotPersistTheCursor(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('getCursor')->willReturn(0);
        $this->messageRepository->method('findDigestCandidates')
            ->willReturnOnConsecutiveCalls([$this->makeMessage(50)], []);
        $this->digestService->expects(self::once())->method('digestBatch')
            ->with(self::anything(), self::anything(), true)
            ->willReturn(['scanned' => 1, 'created' => 0, 'proposals' => [], 'failed' => false, 'failureReason' => null]);

        $this->config->expects(self::never())->method('setCursor');
        $this->config->expects(self::never())->method('advanceCursor');

        $this->runner->runForUser($user, maxBatches: 4, dryRun: true);
    }

    public function testPruneRunsAfterAUserPassThatCreatedDigests(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('getCursor')->willReturn(0);
        $this->messageRepository->method('findDigestCandidates')
            ->willReturnOnConsecutiveCalls([$this->makeMessage(50)], []);
        $this->digestService->method('digestBatch')
            ->willReturn(['scanned' => 1, 'created' => 1, 'proposals' => [], 'failed' => false, 'failureReason' => null]);

        $this->maintenance->expects(self::once())->method('pruneOverflow')->with(7);

        $this->runner->runForUser($user, maxBatches: 4);
    }

    public function testPruneIsSkippedWhenNothingWasCreatedOrOnDryRun(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('getCursor')->willReturn(0);
        $this->messageRepository->method('findDigestCandidates')
            ->willReturnOnConsecutiveCalls([$this->makeMessage(50)], [], [$this->makeMessage(60)], []);
        $this->digestService->method('digestBatch')
            ->willReturnOnConsecutiveCalls(
                ['scanned' => 1, 'created' => 0, 'proposals' => [], 'failed' => false, 'failureReason' => null],
                ['scanned' => 1, 'created' => 1, 'proposals' => [['title' => 'x', 'message_id' => 60]], 'failed' => false, 'failureReason' => null],
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

    public function testRunForOtherChatsForwardsLiveChatIdSoQuietAppliesOnlyThere(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getCursor')->willReturn(0);
        $capturedLiveChatId = null;
        $this->messageRepository->method('findDigestCandidates')
            ->willReturnCallback(function (
                int $userId,
                int $afterId,
                int $beforeUnix,
                int $limit,
                ?int $sinceUnix = null,
                ?int $liveChatId = null,
            ) use (&$capturedLiveChatId): array {
                $capturedLiveChatId = $liveChatId;

                return [];
            });

        $this->runner->runForOtherChats($user, 55);

        self::assertSame(55, $capturedLiveChatId);
    }

    public function testRunForOtherChatsRespectsEnabledGuard(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $this->messageRepository->expects(self::never())->method('findDigestCandidates');
        $this->digestService->expects(self::never())->method('digestBatch');

        $result = $this->runner->runForOtherChats($this->makeUser(7), 55);

        self::assertSame(0, $result['batches']);
        self::assertSame(0, $result['created']);
    }

    public function testRunForOtherChatsPersistsTheCursorWhenNothingWasSkipped(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('isEnabled')->willReturn(true);

        $capturedAfterIds = [];
        $this->messageRepository->method('findDigestCandidates')
            ->willReturnCallback(function (int $userId, int $afterId) use (&$capturedAfterIds): array {
                $capturedAfterIds[] = $afterId;

                return 1 === count($capturedAfterIds) ? [$this->makeMessage(200)] : [];
            });
        $this->digestService->method('digestBatch')
            ->willReturn(['scanned' => 1, 'created' => 1, 'proposals' => [], 'failed' => false, 'failureReason' => null]);

        $this->config->method('getCursor')->willReturn(100);
        $persisted = [];
        $this->config->method('advanceCursor')
            ->willReturnCallback(static function (int $userId, int $messageId) use (&$persisted): void {
                $persisted[] = $messageId;
            });

        $result = $this->runner->runForOtherChats($user, 55);

        self::assertSame(100, $capturedAfterIds[0] ?? null);
        self::assertSame([200], $persisted);
        self::assertSame(1, $result['batches']);
        self::assertSame(1, $result['created']);
        self::assertSame(200, $result['cursor']);
        self::assertFalse($result['aborted']);
    }

    public function testRateLimitAbortsTheRunBeforeTheNextUser(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->messageRepository->method('findDistinctUserIds')->willReturn([7, 8]);
        $seen = [];
        $this->userRepository->method('find')->willReturnCallback(function (int $id) use (&$seen): User {
            $seen[] = $id;

            return $this->makeUser($id);
        });
        $this->messageRepository->method('findDigestCandidates')->willReturn([$this->makeMessage(10)]);
        $this->digestService->expects(self::once())->method('digestBatch')->willReturn([
            'scanned' => 0,
            'created' => 0,
            'proposals' => [],
            'failed' => true,
            'failureReason' => ChatFailureReason::RateLimited->value,
        ]);
        $this->config->expects(self::never())->method('advanceCursor');
        $this->config->expects(self::never())->method('setCursor');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::logicalAnd(
                self::stringContains('rate_limited'),
                self::stringContains('1 users left unprocessed'),
            ),
            self::anything(),
        );
        $this->logger = $logger;
        $this->runner = $this->makeRunner();

        $summary = $this->runner->run();

        self::assertSame([7], $seen);
        self::assertTrue($summary['aborted']);
        self::assertSame(ChatFailureReason::RateLimited->value, $summary['abort_reason']);
        self::assertSame(1, $summary['failed_batches']);
        self::assertSame(0, $summary['batches']);
        self::assertSame([], $this->cursorFailures);
    }

    public function testBatchCausedFailureKeepsTheCursorAndContinuesWithTheNextUser(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->messageRepository->method('findDistinctUserIds')->willReturn([7, 8]);
        $this->userRepository->method('find')->willReturnCallback(fn (int $id): User => $this->makeUser($id));
        $this->config->method('getCursor')->willReturn(100);
        $this->messageRepository->method('findDigestCandidates')->willReturnCallback(
            function (int $userId, int $afterId): array {
                if ($afterId >= 130) {
                    return [];
                }

                return [$this->makeMessage(110), $this->makeMessage(130)];
            },
        );

        $seen = [];
        $this->digestService->method('digestBatch')->willReturnCallback(
            function (User $user) use (&$seen): array {
                $seen[] = $user->getId();
                if (7 === $user->getId()) {
                    return [
                        'scanned' => 0,
                        'created' => 0,
                        'proposals' => [],
                        'failed' => true,
                        'failureReason' => ChatFailureReason::ContentFiltered->value,
                    ];
                }

                return ['scanned' => 2, 'created' => 0, 'proposals' => [], 'failed' => false, 'failureReason' => null];
            },
        );
        $advanced = [];
        $this->config->method('advanceCursor')->willReturnCallback(
            static function (int $userId, int $messageId) use (&$advanced): void {
                $advanced[] = [$userId, $messageId];
            },
        );

        $summary = $this->runner->run();

        self::assertSame([7, 8], $seen);
        self::assertFalse($summary['aborted']);
        self::assertSame(1, $summary['failed_batches']);
        self::assertSame(1, $summary['users']);
        self::assertSame(['start' => 100, 'count' => 1], $this->cursorFailures[7]);
        self::assertSame([[8, 130]], $advanced);
        self::assertContains('7:100:1', $this->failureWrites);
        self::assertNotContains('clear:7', $this->failureWrites);
    }

    public function testThirdFailureAtTheSameStartSkipsTheBatchAndClearsTheCounter(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('getCursor')->willReturn(100);
        $this->cursorFailures[7] = ['start' => 100, 'count' => MessageDigestRunner::MAX_ATTEMPTS_PER_BATCH - 1];
        $this->messageRepository->method('findDigestCandidates')->willReturn([$this->makeMessage(110), $this->makeMessage(130)]);
        $this->digestService->expects(self::once())->method('digestBatch')->willReturn([
            'scanned' => 0,
            'created' => 0,
            'proposals' => [],
            'failed' => true,
            'failureReason' => ChatFailureReason::Unknown->value,
        ]);
        $advanced = [];
        $this->config->method('advanceCursor')->willReturnCallback(
            static function (int $userId, int $messageId) use (&$advanced): void {
                $advanced[] = $messageId;
            },
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::logicalAnd(
                self::stringContains('user 7'),
                self::stringContains('110-130'),
            ),
            self::anything(),
        );
        $this->logger = $logger;
        $this->runner = $this->makeRunner();

        $result = $this->runner->runForUser($user, maxBatches: 4);

        self::assertSame([130], $advanced);
        self::assertArrayNotHasKey(7, $this->cursorFailures);
        self::assertNotContains('7:100:3', $this->failureWrites);
        self::assertContains('clear:7', $this->failureWrites);
        self::assertSame(130, $result['cursor']);
        self::assertFalse($result['aborted']);
        self::assertSame(1, $result['failed_batches']);
    }

    public function testSuccessfulBatchClearsTheFailureCounter(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('getCursor')->willReturn(0);
        $this->cursorFailures[7] = ['start' => 0, 'count' => 2];
        $this->messageRepository->method('findDigestCandidates')
            ->willReturnOnConsecutiveCalls([$this->makeMessage(50)], []);
        $this->digestService->method('digestBatch')
            ->willReturn(['scanned' => 1, 'created' => 0, 'proposals' => [], 'failed' => false, 'failureReason' => null]);
        $this->config->expects(self::once())->method('advanceCursor')->with(7, 50);

        $this->runner->runForUser($user, maxBatches: 4);

        self::assertArrayNotHasKey(7, $this->cursorFailures);
        self::assertContains('clear:7', $this->failureWrites);
    }

    public function testPassStopsBelowAQuietHoleAndNothingAboveItIsSentTwice(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('isEnabled')->willReturn(true);
        $cursor = 100;
        $this->config->method('getCursor')->willReturnCallback(static function () use (&$cursor): int {
            return $cursor;
        });
        $this->config->method('advanceCursor')->willReturnCallback(static function (int $userId, int $messageId) use (&$cursor): void {
            if ($messageId > $cursor) {
                $cursor = $messageId;
            }
        });
        // 120 is still inside the quiet window; 150 and 170 are copied rows
        // that kept their old timestamps, so the quiet window lets them through.
        $this->lowestSkippedId = 120;
        $eligible = [110, 150, 170];
        $this->messageRepository->method('findDigestCandidates')->willReturnCallback(
            function (int $userId, int $afterId) use (&$eligible): array {
                $ids = array_values(array_filter($eligible, static fn (int $id): bool => $id > $afterId));

                return array_map(fn (int $id): Message => $this->makeMessage($id), $ids);
            },
        );
        $sent = [];
        $this->digestService->method('digestBatch')->willReturnCallback(
            static function (User $user, array $messages) use (&$sent): array {
                $sent[] = array_map(static fn (Message $message): int => (int) $message->getId(), $messages);

                return ['scanned' => \count($messages), 'created' => 0, 'proposals' => [], 'failed' => false, 'failureReason' => null];
            },
        );

        $first = $this->runner->runForUser($user, maxBatches: 4);
        $this->runner->runForOtherChats($user, 9);
        self::assertSame([[110]], $sent);
        self::assertSame(110, $first['cursor']);
        self::assertSame(110, $cursor);

        $this->lowestSkippedId = null;
        $eligible = [110, 120, 150, 170];
        $this->runner->runForUser($user, maxBatches: 4);

        self::assertSame([[110], [120, 150, 170]], $sent);
        self::assertSame(170, $cursor);
    }

    public function testHoleBeforeTheFirstCandidateSendsNothing(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('getCursor')->willReturn(100);
        $this->lowestSkippedId = 101;
        $this->messageRepository->method('findDigestCandidates')
            ->willReturn([$this->makeMessage(150), $this->makeMessage(170)]);
        $this->digestService->expects(self::never())->method('digestBatch');
        $this->config->expects(self::never())->method('advanceCursor');

        $result = $this->runner->runForUser($user, maxBatches: 4);

        self::assertSame(100, $result['cursor']);
        self::assertSame([100], $this->lowestSkippedAfterIds);
    }

    public function testSecondOtherChatsPassDoesNotSendTheSameBatch(): void
    {
        $user = $this->makeUser(7);
        $this->config->method('isEnabled')->willReturn(true);
        $cursor = 0;
        $this->config->method('getCursor')->willReturnCallback(static function () use (&$cursor): int {
            return $cursor;
        });
        $this->config->method('advanceCursor')->willReturnCallback(static function (int $userId, int $messageId) use (&$cursor): void {
            if ($messageId > $cursor) {
                $cursor = $messageId;
            }
        });
        $this->messageRepository->method('findDigestCandidates')->willReturnCallback(
            function (int $userId, int $afterId): array {
                return $afterId >= 50 ? [] : [$this->makeMessage(40), $this->makeMessage(50)];
            },
        );
        $this->digestService->expects(self::once())->method('digestBatch')
            ->willReturn(['scanned' => 2, 'created' => 0, 'proposals' => [], 'failed' => false, 'failureReason' => null]);

        $this->runner->runForOtherChats($user, 9);
        $this->runner->runForOtherChats($user, 9);

        self::assertSame(50, $cursor);
    }

    public function testUserWithoutStoredCursorStartsAfterTheGlobalStartPoint(): void
    {
        $stored = [
            '0|'.MessageDigestConfig::KEY_START_AFTER_ID => '9000',
            '8|'.MessageDigestConfig::KEY_CURSOR => '9500',
        ];
        $config = new MessageDigestConfig($this->configRepository($stored));

        self::assertSame(9000, $config->getCursor(7));
        self::assertSame(9500, $config->getCursor(8));

        $config->advanceCursor(7, 8000);
        self::assertArrayNotHasKey('7|'.MessageDigestConfig::KEY_CURSOR, $stored);
        $config->advanceCursor(7, 9010);
        self::assertSame(9010, $config->getCursor(7));
    }

    public function testFreshInstallWithoutStartPointStartsAtZero(): void
    {
        $stored = [];
        $config = new MessageDigestConfig($this->configRepository($stored));

        self::assertSame(0, $config->getCursor(7));
    }

    public function testCursorAdvanceNeverDecreases(): void
    {
        $stored = ['7|'.MessageDigestConfig::KEY_CURSOR => '50'];
        $config = new MessageDigestConfig($this->configRepository($stored));

        $config->advanceCursor(7, 40);
        self::assertSame(50, $config->getCursor(7));
        $config->advanceCursor(7, 50);
        self::assertSame(50, $config->getCursor(7));
        $config->advanceCursor(7, 80);
        self::assertSame(80, $config->getCursor(7));

        $config->setCursorFailures(7, 100, 2);
        self::assertSame('100:2', $stored['7|'.MessageDigestConfig::KEY_CURSOR_FAILURES]);
        self::assertSame(['start' => 100, 'count' => 2], $config->getCursorFailures(7));
        $config->clearCursorFailures(7);
        self::assertNull($config->getCursorFailures(7));
    }

    public function testBudgetStopSkipsTheModelCallAndLeavesTheCursor(): void
    {
        $this->budgetAllowed = false;
        $this->config->method('isEnabled')->willReturn(true);
        $this->messageRepository->method('findDistinctUserIds')->willReturn([7]);
        $this->userRepository->method('find')->willReturn($this->makeUser(7));
        $this->config->method('getCursor')->willReturn(40);
        $this->messageRepository->method('findDigestCandidates')->willReturn([$this->makeMessage(50)]);
        $this->digestService->expects(self::never())->method('digestBatch');
        $this->config->expects(self::never())->method('advanceCursor');
        $this->config->expects(self::never())->method('setCursor');

        $summary = $this->runner->run();

        self::assertSame(1, $summary['skipped_budget']);
        self::assertSame(0, $summary['batches']);
        self::assertSame(0, $summary['failed_batches']);
        self::assertFalse($summary['aborted']);
        self::assertSame([], $this->cursorFailures);
    }

    /**
     * @return array{start: int, count: int}|null
     */
    private function cursorFailureFor(int $userId): ?array
    {
        return $this->cursorFailures[$userId] ?? null;
    }

    /**
     * @param array<string, string> $stored
     */
    private function configRepository(array &$stored): ConfigRepository
    {
        $entity = $this->createStub(Config::class);
        $repository = $this->createMock(ConfigRepository::class);
        $repository->method('getValue')->willReturnCallback(
            static function (int $ownerId, string $group, string $setting) use (&$stored): ?string {
                return $stored[$ownerId.'|'.$setting] ?? null;
            },
        );
        $repository->method('setValue')->willReturnCallback(
            static function (int $ownerId, string $group, string $setting, string $value) use (&$stored, $entity): Config {
                $stored[$ownerId.'|'.$setting] = $value;

                return $entity;
            },
        );
        $repository->method('deleteValue')->willReturnCallback(
            static function (int $ownerId, string $group, string $setting) use (&$stored): bool {
                $key = $ownerId.'|'.$setting;
                if (!isset($stored[$key])) {
                    return false;
                }
                unset($stored[$key]);

                return true;
            },
        );

        return $repository;
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
