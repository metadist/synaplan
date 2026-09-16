<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Model\ModelCatalog;
use App\Seed\ModelRetirementSeeder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Drives ModelRetirementSeeder against a mocked Connection.
 *
 * The two properties that matter operationally are asserted directly, because
 * both were failure modes of the hand-written migrations this replaces (#1515):
 *
 *   - a re-run writes nothing (the seeder runs on every container start);
 *   - a BID an operator repurposed is never touched, so a retirement can't mark
 *     a live model dead.
 */
final class ModelRetirementSeederTest extends TestCase
{
    public function testARetiredRowIsDeactivatedAndStampedWithDateAndSuccessor(): void
    {
        $bid = self::aRetiredBidWithSuccessor();
        $record = ModelCatalog::retirement($bid);
        self::assertNotNull($record);

        $captured = [];
        $connection = $this->connection([$this->liveRow($bid, $record['providerId'])], $captured);

        $result = (new ModelRetirementSeeder($connection, new NullLogger()))->seed();

        self::assertSame(1, $result->updated);
        self::assertCount(1, $captured);
        self::assertSame($record['retiredOn'], $captured[0]['retiredOn']);
        self::assertSame(ModelCatalog::successorBid($bid), $captured[0]['successorBid']);
        self::assertSame($bid, $captured[0]['bid']);
        self::assertStringContainsString('BACTIVE = 0', $captured[0]['sql']);
        self::assertStringContainsString('BSELECTABLE = 0', $captured[0]['sql']);
        self::assertStringContainsString('BISDEFAULT = 0', $captured[0]['sql']);
    }

    /**
     * "No replacement" is a recorded decision, so it must reach the column as
     * NULL rather than being dropped from the write.
     */
    public function testARetirementWithoutASuccessorStoresNull(): void
    {
        $bid = self::aRetiredBidWithoutSuccessor();
        $record = ModelCatalog::retirement($bid);
        self::assertNotNull($record);

        $captured = [];
        $connection = $this->connection([$this->liveRow($bid, $record['providerId'])], $captured);

        (new ModelRetirementSeeder($connection, new NullLogger()))->seed();

        self::assertCount(1, $captured);
        self::assertNull($captured[0]['successorBid']);
    }

    public function testASecondRunWritesNothing(): void
    {
        $bid = self::aRetiredBidWithSuccessor();
        $record = ModelCatalog::retirement($bid);
        self::assertNotNull($record);

        $captured = [];
        $connection = $this->connection([[
            'BID' => $bid,
            'BPROVID' => $record['providerId'],
            'BRETIREDON' => $record['retiredOn'],
            'BSUCCESSORID' => ModelCatalog::successorBid($bid),
            'BACTIVE' => 0,
            'BSELECTABLE' => 0,
            'BISDEFAULT' => 0,
        ]], $captured);

        $result = (new ModelRetirementSeeder($connection, new NullLogger()))->seed();

        self::assertSame(0, $result->updated);
        self::assertSame([], $captured);
        self::assertSame(count(ModelCatalog::retirements()), $result->skipped);
    }

    /**
     * The in-PHP guard compares against a SELECT that has already returned.
     * `app:seed` runs on every container start against a shared Galera schema,
     * so the write itself must carry the guard too — otherwise a concurrent
     * change between SELECT and UPDATE can retire whatever the row became.
     */
    public function testTheWriteItselfIsGuardedOnTheProviderIdNotJustTheBid(): void
    {
        $bid = self::aRetiredBidWithSuccessor();
        $record = ModelCatalog::retirement($bid);
        self::assertNotNull($record);

        $captured = [];
        $connection = $this->connection([$this->liveRow($bid, $record['providerId'])], $captured);

        (new ModelRetirementSeeder($connection, new NullLogger()))->seed();

        self::assertCount(1, $captured);
        self::assertStringContainsString('BPROVID = :providerId', $captured[0]['sql']);
        self::assertSame($record['providerId'], $captured[0]['providerId']);
    }

    /**
     * An operator who reused the BID for a different model owns that row now.
     */
    public function testARepurposedBidIsLeftAlone(): void
    {
        $bid = self::aRetiredBidWithSuccessor();

        $captured = [];
        $connection = $this->connection([$this->liveRow($bid, 'something-the-operator-put-here')], $captured);

        $result = (new ModelRetirementSeeder($connection, new NullLogger()))->seed();

        self::assertSame(0, $result->updated);
        self::assertSame([], $captured);
    }

    /**
     * Retiring a model an install never had is not an insert.
     */
    public function testMissingRowsAreSkippedNotInserted(): void
    {
        $captured = [];
        $connection = $this->connection([], $captured);

        $result = (new ModelRetirementSeeder($connection, new NullLogger()))->seed();

        self::assertSame(0, $result->inserted);
        self::assertSame(0, $result->updated);
        self::assertSame(count(ModelCatalog::retirements()), $result->skipped);
        self::assertSame([], $captured);
    }

    /**
     * A row switched off by hand but never stamped still needs the stamp — that
     * is what later tells "dead upstream" from "an operator chose this".
     */
    public function testAnAlreadyInactiveButUnstampedRowIsStillStamped(): void
    {
        $bid = self::aRetiredBidWithSuccessor();
        $record = ModelCatalog::retirement($bid);
        self::assertNotNull($record);

        $captured = [];
        $connection = $this->connection([[
            'BID' => $bid,
            'BPROVID' => $record['providerId'],
            'BRETIREDON' => null,
            'BSUCCESSORID' => null,
            'BACTIVE' => 0,
            'BSELECTABLE' => 0,
            'BISDEFAULT' => 0,
        ]], $captured);

        $result = (new ModelRetirementSeeder($connection, new NullLogger()))->seed();

        self::assertSame(1, $result->updated);
        self::assertSame($record['retiredOn'], $captured[0]['retiredOn']);
    }

    public function testEveryRetiredModelPresentInTheDatabaseGetsWritten(): void
    {
        $rows = [];
        foreach (ModelCatalog::retirements() as $bid => $record) {
            $rows[] = $this->liveRow($bid, $record['providerId']);
        }

        $captured = [];
        $connection = $this->connection($rows, $captured);

        $result = (new ModelRetirementSeeder($connection, new NullLogger()))->seed();

        self::assertSame(count(ModelCatalog::retirements()), $result->updated);
        self::assertCount(count(ModelCatalog::retirements()), $captured);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $captured captured writes, by reference
     */
    private function connection(array $rows, array &$captured): Connection
    {
        // A stub, not a mock: the writes are asserted through $captured, which
        // says what was written and not merely that something was.
        $mock = $this->createStub(Connection::class);

        // @phpstan-ignore-next-line method.notFound
        $mock->method('fetchAllAssociative')->willReturn($rows);

        // @phpstan-ignore-next-line method.notFound
        $mock->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$captured): int {
                $captured[] = [
                    'sql' => $sql,
                    'retiredOn' => $params['retiredOn'] ?? null,
                    'successorBid' => $params['successorBid'] ?? null,
                    'bid' => $params['bid'] ?? null,
                    'providerId' => $params['providerId'] ?? null,
                ];

                return 1;
            });

        return $mock;
    }

    /**
     * @return array<string, mixed>
     */
    private function liveRow(int $bid, string $providerId): array
    {
        return [
            'BID' => $bid,
            'BPROVID' => $providerId,
            'BRETIREDON' => null,
            'BSUCCESSORID' => null,
            'BACTIVE' => 1,
            'BSELECTABLE' => 1,
            'BISDEFAULT' => 0,
        ];
    }

    /**
     * Picked from the registry rather than hardcoded, so these tests keep
     * testing behaviour instead of pinning whichever model happened to be
     * retired when they were written.
     */
    private static function aRetiredBidWithSuccessor(): int
    {
        foreach (array_keys(ModelCatalog::retirements()) as $bid) {
            if (null !== ModelCatalog::successorBid($bid)) {
                return $bid;
            }
        }

        self::fail('No retirement with a successor to test against.');
    }

    private static function aRetiredBidWithoutSuccessor(): int
    {
        foreach (ModelCatalog::retirements() as $bid => $record) {
            if (null === $record['successor']) {
                return $bid;
            }
        }

        self::fail('No retirement without a successor to test against.');
    }
}
