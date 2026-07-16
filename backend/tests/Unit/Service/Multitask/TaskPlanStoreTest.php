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

    public function testPersistsJobKeyWhenProvided(): void
    {
        $plan = TaskPlan::fromArray([
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'video_generation'],
                ['id' => 'n2', 'capability' => 'chat', 'depends_on' => ['n1']],
            ],
        ]);

        $captured = [];
        $this->connection->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured[] = $data;

                return 1;
            });

        $written = $this->store->persistWithStatuses(
            555,
            $plan,
            76,
            ['n1' => 'running', 'n2' => 'pending'],
            'pending',
            ['n1' => 'job-key-xyz'],
        );

        self::assertSame(2, $written);
        self::assertSame('job-key-xyz', $captured[0]['BJOBKEY']);
        self::assertArrayNotHasKey('BJOBKEY', $captured[1]);
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

    public function testUpdateNodeStatusTargetsSingleRow(): void
    {
        $captured = [];
        $this->connection->expects(self::once())
            ->method('update')
            ->willReturnCallback(function (string $table, array $data, array $criteria) use (&$captured): int {
                $captured = ['table' => $table, 'data' => $data, 'criteria' => $criteria];

                return 1;
            });

        $this->store->updateNodeStatus(42, 'n2', 'done');

        self::assertSame('BMESSAGE_TASKS', $captured['table']);
        self::assertSame(['BSTATUS' => 'done'], $captured['data']);
        self::assertSame(['BMESSAGEID' => 42, 'BNODEID' => 'n2'], $captured['criteria']);
    }

    public function testUpdateNodeStatusPersistsCardBodyFields(): void
    {
        $captured = [];
        $this->connection->expects(self::once())
            ->method('update')
            ->willReturnCallback(function (string $table, array $data, array $criteria) use (&$captured): int {
                $captured = ['table' => $table, 'data' => $data, 'criteria' => $criteria];

                return 1;
            });

        $this->store->updateNodeStatus(42, 'n2', 'done', [
            'text' => 'Docker explanation',
            'url' => '/uploads/a.mp3',
            'error' => 'boom',
        ]);

        self::assertSame('BMESSAGE_TASKS', $captured['table']);
        self::assertSame('done', $captured['data']['BSTATUS']);
        self::assertSame('boom', $captured['data']['BERROR']);
        $ref = json_decode((string) $captured['data']['BRESULTREF'], true);
        self::assertIsArray($ref);
        self::assertSame('Docker explanation', $ref['text']);
        self::assertSame('/uploads/a.mp3', $ref['url']);
    }

    public function testUpdateNodeStatusIgnoresEmptyNodeId(): void
    {
        $this->connection->expects(self::never())->method('update');

        $this->store->updateNodeStatus(42, '', 'done');
    }

    public function testUpdateNodeStatusSwallowsFailures(): void
    {
        $this->connection->method('update')->willThrowException(new \RuntimeException('db down'));

        // Best-effort — a live-progress miss must never break the turn.
        $this->store->updateNodeStatus(42, 'n1', 'failed');

        $this->expectNotToPerformAssertions();
    }

    public function testLoadCardsMapsRowsAndExcludesHiddenAssemblerNode(): void
    {
        $this->connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql, array $params): array {
                self::assertStringContainsString('BMESSAGE_TASKS', $sql);
                self::assertStringContainsString('BRESULTREF', $sql);
                self::assertSame([777], $params);

                return [
                    [
                        'BNODEID' => 'n1',
                        'BCAPABILITY' => 'image_generation',
                        'BSTATUS' => 'done',
                        'BRESULTREF' => '{"url":"/uploads/pic.png","type":"image"}',
                        'BERROR' => null,
                    ],
                    [
                        'BNODEID' => 'n2',
                        'BCAPABILITY' => 'chat',
                        'BSTATUS' => 'done',
                        'BRESULTREF' => '{"text":"Hello from n2"}',
                        'BERROR' => null,
                    ],
                    // compose_reply is the hidden assembler — never a user card.
                    [
                        'BNODEID' => 'n3',
                        'BCAPABILITY' => 'compose_reply',
                        'BSTATUS' => 'pending',
                        'BRESULTREF' => null,
                        'BERROR' => null,
                    ],
                ];
            });

        $cards = $this->store->loadCards(777);

        self::assertCount(2, $cards);
        self::assertSame('n1', $cards[0]['nodeId']);
        self::assertSame('image', $cards[0]['kind']);
        self::assertSame('done', $cards[0]['state']);
        self::assertSame('/uploads/pic.png', $cards[0]['url']);
        self::assertSame('image', $cards[0]['type']);
        self::assertSame('n2', $cards[1]['nodeId']);
        self::assertSame('Hello from n2', $cards[1]['text']);
    }

    public function testPersistWithStatusesWritesResultPayload(): void
    {
        $plan = TaskPlan::fromArray([
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n1',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'chat'],
            ],
        ]);

        $captured = [];
        $this->connection->method('insert')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured[] = $data;

                return 1;
            });

        $this->store->persistWithStatuses(
            555,
            $plan,
            76,
            ['n1' => 'done'],
            'pending',
            [],
            ['n1' => ['text' => 'Settled answer', 'error' => null]],
        );

        self::assertCount(1, $captured);
        $ref = json_decode((string) $captured[0]['BRESULTREF'], true);
        self::assertSame('Settled answer', $ref['text']);
        self::assertArrayNotHasKey('BERROR', $captured[0]);
    }

    public function testLoadCardsReturnsEmptyOnFailure(): void
    {
        $this->connection->method('fetchAllAssociative')->willThrowException(new \RuntimeException('db down'));

        self::assertSame([], $this->store->loadCards(1));
    }
}
