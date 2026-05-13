<?php

namespace App\Tests\Service;

use App\DTO\CostResult;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\SubscriptionRepository;
use App\Service\BillingService;
use App\Service\CostCalculationService;
use App\Service\RateLimitService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for `RateLimitService::recordFileAnalysisOnce` — the dedup helper
 * introduced to fix issue #887 (FILE_ANALYSIS double-counted on RAG retry,
 * and counted on chat upload before any analysis happened).
 *
 * Behaviour pinned by these tests:
 *
 *  1. First call for a new (user, file_id) pair writes ONE BUSELOG row.
 *  2. Second call for the same pair is a no-op (no INSERT).
 *  3. Different file_ids for the same user are independent.
 *  4. file_id = 0 (or negative) bypasses the dedup and falls through to
 *     `recordUsage()` so legacy callers without a file id keep working.
 */
class RateLimitServiceFileAnalysisOnceTest extends TestCase
{
    private RateLimitService $service;
    private Connection $connection;
    private EntityManagerInterface $em;

    /** @var array<int, true> Track which file ids the fake DB has rows for. */
    private array $existingFileIds = [];

    /** @var int Number of INSERTs into BUSELOG this test has observed. */
    private int $insertCount = 0;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getConnection')->willReturn($this->connection);

        $costCalculationService = $this->createMock(CostCalculationService::class);
        $costCalculationService->method('getPricingMode')->willReturn('per_token');
        $costCalculationService->method('calculateCost')->willReturn(
            new CostResult(
                totalCost: '0.000000',
                inputCost: '0.000000',
                outputCost: '0.000000',
                cacheSavings: '0.000000',
                priceSnapshot: [],
                billedInputTokens: 0,
            )
        );

        $this->existingFileIds = [];
        $this->insertCount = 0;

        // The happy path is now a single statement:
        //   INSERT INTO BUSELOG (...) SELECT ... WHERE NOT EXISTS (...)
        // Mirror that contract with a fake DBAL connection that returns
        // 1 affected row when the (user, file) pair is novel and 0 when
        // it has already been seen — exactly what MariaDB does for the
        // real INSERT...SELECT...WHERE NOT EXISTS pattern.
        //
        // The fileId <= 0 fallback still goes through the legacy
        // `recordUsage()` INSERT (no WHERE NOT EXISTS clause). We count
        // those too, since the test contract ("a row was written") cares
        // about insert events, not the SQL shape.
        $this->connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []): int {
                if (!str_contains($sql, 'INSERT INTO BUSELOG')) {
                    return 1;
                }

                if (str_contains($sql, 'WHERE NOT EXISTS')) {
                    $fileId = (int) ($params['file_id'] ?? 0);
                    if (isset($this->existingFileIds[$fileId])) {
                        // Row exists — INSERT...SELECT...WHERE NOT EXISTS
                        // yields zero affected rows on MariaDB, signalling
                        // the dedup.
                        return 0;
                    }
                    ++$this->insertCount;
                    $this->existingFileIds[$fileId] = true;

                    return 1;
                }

                // Legacy `recordUsage()` INSERT (fileId <= 0 fallback).
                ++$this->insertCount;
                $metadata = json_decode((string) ($params['metadata'] ?? '{}'), true);
                if (is_array($metadata) && isset($metadata['file_id'])) {
                    $this->existingFileIds[(int) $metadata['file_id']] = true;
                }

                return 1;
            }
        );

        $this->service = new RateLimitService(
            $this->createMock(ConfigRepository::class),
            $this->em,
            $this->createMock(LoggerInterface::class),
            new BillingService('sk_test_valid_key', 'price_1RealProId'),
            $costCalculationService,
            $this->createMock(SubscriptionRepository::class),
        );
    }

    private function user(int $id): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getRateLimitLevel')->willReturn('PRO');

        return $user;
    }

    public function testFirstCallForFileWritesOneRow(): void
    {
        $wrote = $this->service->recordFileAnalysisOnce($this->user(7), 42);

        $this->assertTrue($wrote, 'First call for a new (user, file) pair must write a row.');
        $this->assertSame(1, $this->insertCount);
    }

    public function testSecondCallForSameFileIsNoop(): void
    {
        // Issue #887 RAG double-count: processSingleUpload + a later
        // /process retry both convert into recordFileAnalysisOnce calls
        // for the same file. The second one MUST NOT write a second row.
        $this->service->recordFileAnalysisOnce($this->user(7), 42);
        $this->insertCount = 1; // baseline after the first call

        $wrote = $this->service->recordFileAnalysisOnce($this->user(7), 42);

        $this->assertFalse($wrote, 'Second call for the same (user, file) pair must be a no-op.');
        $this->assertSame(1, $this->insertCount, 'No additional INSERT must happen.');
    }

    public function testDifferentFileIdsAreIndependent(): void
    {
        $this->service->recordFileAnalysisOnce($this->user(7), 42);
        $this->service->recordFileAnalysisOnce($this->user(7), 43);
        $this->service->recordFileAnalysisOnce($this->user(7), 44);

        $this->assertSame(3, $this->insertCount, 'Three distinct files must produce three distinct rows.');
    }

    public function testZeroFileIdFallsThroughToLegacyRecordUsage(): void
    {
        // Defensive fallback: callers that don't have a file id yet (e.g.
        // an early failure path) must not be silently dropped — we still
        // want the BUSELOG row, just without the dedup key.
        $wrote = $this->service->recordFileAnalysisOnce($this->user(7), 0, [
            'filename' => 'orphan.pdf',
            'source' => 'WEB',
        ]);

        $this->assertTrue($wrote);
        $this->assertSame(1, $this->insertCount);
    }

    public function testNegativeFileIdAlsoFallsThrough(): void
    {
        $wrote = $this->service->recordFileAnalysisOnce($this->user(7), -1, [
            'source' => 'WEB',
        ]);

        $this->assertTrue($wrote);
        $this->assertSame(1, $this->insertCount);
    }

    public function testFileIdIsForcedIntoMetadataEvenIfCallerOmitsIt(): void
    {
        $captured = [];
        $this->connection = $this->createMock(Connection::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getConnection')->willReturn($this->connection);

        $this->connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []) use (&$captured): int {
                if (
                    str_contains($sql, 'INSERT INTO BUSELOG')
                    && str_contains($sql, 'WHERE NOT EXISTS')
                ) {
                    $captured[] = [
                        'metadata' => json_decode((string) ($params['metadata'] ?? '{}'), true),
                        'file_id_param' => $params['file_id'] ?? null,
                    ];
                }

                return 1;
            }
        );

        $costCalculationService = $this->createMock(CostCalculationService::class);
        $costCalculationService->method('getPricingMode')->willReturn('per_token');
        $costCalculationService->method('calculateCost')->willReturn(
            new CostResult(
                totalCost: '0.000000',
                inputCost: '0.000000',
                outputCost: '0.000000',
                cacheSavings: '0.000000',
                priceSnapshot: [],
                billedInputTokens: 0,
            )
        );

        $service = new RateLimitService(
            $this->createMock(ConfigRepository::class),
            $this->em,
            $this->createMock(LoggerInterface::class),
            new BillingService('sk_test_valid_key', 'price_1RealProId'),
            $costCalculationService,
            $this->createMock(SubscriptionRepository::class),
        );

        // Caller passes metadata WITHOUT file_id — the helper must inject it
        // both into the BMETADATA JSON we INSERT and the WHERE NOT EXISTS
        // file_id parameter, so the dedup contract holds end-to-end.
        $service->recordFileAnalysisOnce($this->user(7), 99, ['source' => 'WEB']);

        $this->assertCount(1, $captured);
        $this->assertSame(99, $captured[0]['metadata']['file_id'] ?? null);
        $this->assertSame('WEB', $captured[0]['metadata']['source'] ?? null);
        $this->assertSame(99, $captured[0]['file_id_param']);
    }
}
