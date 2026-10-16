<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Scheduler;

use App\Service\Scheduler\SchedulerSlotStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use PHPUnit\Framework\TestCase;

final class SchedulerSlotStoreTest extends TestCase
{
    public function testUpdateWinsWhenOneRowChanges(): void
    {
        $calls = [];
        $store = new SchedulerSlotStore($this->connection(
            $calls,
            static fn (): int => 1,
            static function (): never {
                throw new \RuntimeException('update must not query');
            },
        ));

        self::assertTrue($store->claim(SchedulerSlotStore::SETTING_HOURLY, true, '1600000000', 1700000000));
        self::assertCount(1, $calls);
        self::assertStringContainsString('UPDATE BCONFIG SET BVALUE = :now', $calls[0]['sql']);
        self::assertStringContainsString('AND BVALUE = :old', $calls[0]['sql']);
        self::assertStringContainsString("BGROUP = '".SchedulerSlotStore::GROUP."'", $calls[0]['sql']);
        self::assertStringContainsString('BOWNERID = '.SchedulerSlotStore::OWNER_ID, $calls[0]['sql']);
        self::assertSame(SchedulerSlotStore::SETTING_HOURLY, $calls[0]['params']['setting']);
        self::assertSame('1700000000', $calls[0]['params']['now']);
        self::assertSame('1600000000', $calls[0]['params']['old']);
    }

    public function testUpdateLosesWhenNoRowChanges(): void
    {
        $calls = [];
        $store = new SchedulerSlotStore($this->connection(
            $calls,
            static fn (): int => 0,
            static function (): never {
                throw new \RuntimeException('update must not query');
            },
        ));

        self::assertFalse($store->claim(SchedulerSlotStore::SETTING_DAILY, true, '1', 2));
        self::assertCount(1, $calls);
        self::assertStringContainsString('UPDATE BCONFIG', $calls[0]['sql']);
    }

    public function testInsertWinsWhenTheRowIsAbsent(): void
    {
        $calls = [];
        $store = new SchedulerSlotStore($this->connection(
            $calls,
            static fn (): int => 1,
            static function (): never {
                throw new \RuntimeException('insert must not query');
            },
        ));

        self::assertTrue($store->claim(SchedulerSlotStore::SETTING_HEALTH, false, null, 1700000000));
        self::assertCount(1, $calls);
        self::assertStringContainsString('INSERT IGNORE INTO BCONFIG', $calls[0]['sql']);
        self::assertStringNotContainsString('UPDATE BCONFIG', $calls[0]['sql']);
        self::assertSame(SchedulerSlotStore::SETTING_HEALTH, $calls[0]['params']['setting']);
        self::assertSame('1700000000', $calls[0]['params']['now']);
        self::assertArrayNotHasKey('old', $calls[0]['params']);
    }

    public function testInsertLosesWhenAnotherNodeCreatedTheRow(): void
    {
        $calls = [];
        $store = new SchedulerSlotStore($this->connection(
            $calls,
            static fn (): int => 0,
            static function (): never {
                throw new \RuntimeException('insert must not query');
            },
        ));

        self::assertFalse($store->claim(SchedulerSlotStore::SETTING_HEALTH, false, null, 1700000000));
    }

    public function testRetryableExceptionIsALostClaim(): void
    {
        $calls = [];
        $store = new SchedulerSlotStore($this->connection(
            $calls,
            static function (): never {
                throw self::galeraConflict();
            },
            static function (): never {
                throw new \RuntimeException('conflict must not query');
            },
        ));

        self::assertFalse($store->claim(SchedulerSlotStore::SETTING_HOURLY, true, '1', 2));
        self::assertFalse($store->claim(SchedulerSlotStore::SETTING_HOURLY, false, null, 2));
        self::assertCount(2, $calls);
    }

    public function testOtherDatabaseErrorsPropagate(): void
    {
        $calls = [];
        $store = new SchedulerSlotStore($this->connection(
            $calls,
            static function (): never {
                throw new \RuntimeException('server gone');
            },
            static function (): never {
                throw new \RuntimeException('unused');
            },
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('server gone');
        $store->claim(SchedulerSlotStore::SETTING_DAILY, true, '1', 2);
    }

    public function testReadMissingCorruptAndTimestampValues(): void
    {
        $calls = [];
        $values = [false, '1700000000', 'soon', ''];
        $store = new SchedulerSlotStore($this->connection(
            $calls,
            static function (): never {
                throw new \RuntimeException('read must not write');
            },
            static function () use (&$values): mixed {
                $next = array_shift($values);

                return $next;
            },
        ));

        $missing = $store->read(SchedulerSlotStore::SETTING_DAILY);
        self::assertFalse($missing->exists);
        self::assertNull($missing->lastStart);
        self::assertNull($missing->rawValue);

        $timestamp = $store->read(SchedulerSlotStore::SETTING_DAILY);
        self::assertTrue($timestamp->exists);
        self::assertSame(1700000000, $timestamp->lastStart);
        self::assertSame('1700000000', $timestamp->rawValue);

        $corrupt = $store->read(SchedulerSlotStore::SETTING_HOURLY);
        self::assertTrue($corrupt->exists);
        self::assertNull($corrupt->lastStart);
        self::assertSame('soon', $corrupt->rawValue);

        $empty = $store->read(SchedulerSlotStore::SETTING_HEALTH);
        self::assertTrue($empty->exists);
        self::assertNull($empty->lastStart);
        self::assertSame('', $empty->rawValue);

        self::assertSame(SchedulerSlotStore::SETTING_HEALTH, $calls[3]['params']['setting']);
        self::assertStringContainsString('SELECT BVALUE FROM BCONFIG', $calls[0]['sql']);
        self::assertStringContainsString("BGROUP = '".SchedulerSlotStore::GROUP."'", $calls[0]['sql']);
    }

    private static function galeraConflict(): \Throwable
    {
        return new class('Galera certification conflict') extends \RuntimeException implements RetryableException {
        };
    }

    /**
     * @param list<array{sql: string, params: array<string, mixed>}> $calls
     * @param \Closure(): int                                        $onStatement
     * @param \Closure(): mixed                                      $onQuery
     */
    private function connection(array &$calls, \Closure $onStatement, \Closure $onQuery): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('prepare')->willReturnCallback(function (string $sql) use (&$calls, $onStatement, $onQuery): Statement {
            $params = [];
            $statement = $this->createStub(Statement::class);
            $statement->method('bindValue')->willReturnCallback(static function (string|int $name, mixed $value) use (&$params): void {
                $params[(string) $name] = $value;
            });
            $statement->method('executeStatement')->willReturnCallback(function () use (&$calls, &$params, $sql, $onStatement): int {
                $calls[] = ['sql' => $sql, 'params' => $params];

                return $onStatement();
            });
            $statement->method('executeQuery')->willReturnCallback(function () use (&$calls, &$params, $sql, $onQuery): Result {
                $calls[] = ['sql' => $sql, 'params' => $params];
                $result = $this->createStub(Result::class);
                $result->method('fetchOne')->willReturn($onQuery());

                return $result;
            });

            return $statement;
        });

        return $connection;
    }
}
