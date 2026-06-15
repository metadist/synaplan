<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask;

use App\Service\Multitask\Plan\TaskPlan;
use App\Service\Multitask\TaskPlanStore;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TaskPlanStoreTest extends TestCase
{
    private Connection&MockObject $connection;
    private TaskPlanStore $store;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        // Mirror DBAL semantics: transactional() invokes the closure with the
        // connection and returns its result; an exception inside propagates.
        $this->connection->method('transactional')
            ->willReturnCallback(fn (callable $fn): mixed => $fn($this->connection));
        $this->store = new TaskPlanStore($this->connection, $this->createMock(LoggerInterface::class));
    }

    public function testPersistsOneRowPerNode(): void
    {
        $plan = TaskPlan::fromArray([
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'extract_text'],
                ['id' => 'n2', 'capability' => 'summarize', 'depends_on' => ['n1']],
            ],
        ]);

        $captured = [];
        $this->connection->expects(self::exactly(2))
            ->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                self::assertSame('BMESSAGE_TASKS', $table);
                $captured[] = $data;

                return 1;
            });

        $written = $this->store->persist(555, $plan, 76);

        self::assertSame(2, $written);
        self::assertSame(555, $captured[0]['BMESSAGEID']);
        self::assertSame('n1', $captured[0]['BNODEID']);
        self::assertSame('extract_text', $captured[0]['BCAPABILITY']);
        self::assertSame('pending', $captured[0]['BSTATUS']);
        self::assertSame(76, $captured[0]['BMODELID']);
        self::assertSame('["n1"]', $captured[1]['BDEPENDSON']);
    }

    public function testReplacesExistingRowsForMessage(): void
    {
        $plan = TaskPlan::singleChatPlan('en');

        $calls = [];
        $this->connection->expects(self::once())
            ->method('delete')
            ->willReturnCallback(function (string $table, array $criteria) use (&$calls): int {
                self::assertSame('BMESSAGE_TASKS', $table);
                self::assertSame(['BMESSAGEID' => 99], $criteria);
                $calls[] = 'delete';

                return 1;
            });
        $this->connection->method('insert')
            ->willReturnCallback(function () use (&$calls): int {
                $calls[] = 'insert';

                return 1;
            });

        $this->store->persist(99, $plan);

        // Replace semantics: old rows go first, then the fresh plan rows.
        self::assertSame('delete', $calls[0]);
        self::assertContains('insert', $calls);
    }

    public function testSwallowsInsertFailures(): void
    {
        $plan = TaskPlan::singleChatPlan('en');
        $this->connection->method('insert')->willThrowException(new \RuntimeException('db down'));

        // Must not throw — persistence is best-effort.
        $written = $this->store->persist(1, $plan);

        self::assertSame(0, $written);
    }
}
