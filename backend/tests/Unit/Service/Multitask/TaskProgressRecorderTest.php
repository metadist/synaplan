<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask;

use App\Service\Multitask\TaskPlanStore;
use App\Service\Multitask\TaskProgressRecorder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TaskProgressRecorderTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    public function testEveryEventReachesTheClientUnchanged(): void
    {
        $seen = [];
        $recorder = new TaskProgressRecorder($this->store(), 42, static function (array $event) use (&$seen): void {
            $seen[] = $event;
        });
        $events = [
            ['status' => 'task_chunk', 'metadata' => ['node_id' => 'n1', 'chunk' => 'Hel']],
            ['status' => 'planning', 'message' => 'Planning'],
            ['status' => 'task_update', 'metadata' => ['node_id' => 'n1', 'state' => 'done']],
        ];

        foreach ($events as $event) {
            $recorder($event);
        }

        self::assertSame($events, $seen);
    }

    public function testATerminalUpdateStoresTheStreamedText(): void
    {
        $recorder = new TaskProgressRecorder($this->store(), 42, static function (): void {});

        $recorder(['status' => 'task_chunk', 'metadata' => ['node_id' => 'n1', 'chunk' => 'Hel']]);
        $recorder(['status' => 'task_chunk', 'metadata' => ['node_id' => 'n1', 'chunk' => 'lo']]);
        $recorder(['status' => 'task_update', 'metadata' => ['node_id' => 'n1', 'state' => 'running']]);
        self::assertSame('pending', $this->rows['n1']['BSTATUS']);

        $recorder(['status' => 'task_update', 'metadata' => ['node_id' => 'n1', 'state' => 'done']]);

        $card = $this->reloaded('n1');
        self::assertSame('done', $card['state']);
        self::assertSame('Hello', $card['text']);
    }

    public function testAReloadMidReadShowsTheCountAndWhereFrom(): void
    {
        $recorder = new TaskProgressRecorder($this->store(), 42, static function (): void {});
        $reading = ['outcome' => 'reading', 'label' => 'Nextcloud', 'folder' => 'Invoices', 'read' => 12, 'total' => 52];

        $recorder(['status' => 'task_progress', 'metadata' => ['node_id' => 'n1', 'percent' => 40]]);
        self::assertSame('pending', $this->rows['n1']['BSTATUS']);

        $recorder(['status' => 'task_progress', 'metadata' => ['node_id' => 'n1', 'folder_read' => $reading]]);

        $card = $this->reloaded('n1');
        self::assertSame('running', $card['state']);
        self::assertSame($reading, $card['folder_read']);
    }

    public function testTheSettledReadReplacesTheLiveCount(): void
    {
        $recorder = new TaskProgressRecorder($this->store(), 42, static function (): void {});
        $recorder(['status' => 'task_progress', 'metadata' => ['node_id' => 'n1', 'folder_read' => ['outcome' => 'reading', 'read' => 1, 'total' => 2]]]);

        $recorder(['status' => 'task_update', 'metadata' => [
            'node_id' => 'n1',
            'state' => 'done',
            'text' => 'Read 2 files from Nextcloud › Invoices: a.pdf, b.pdf.',
            'folder_read' => ['outcome' => 'read', 'read' => 2, 'total' => 2],
        ]]);

        $card = $this->reloaded('n1');
        self::assertSame('done', $card['state']);
        self::assertSame(['outcome' => 'read', 'read' => 2, 'total' => 2], $card['folder_read']);
    }

    private function store(): TaskPlanStore
    {
        $this->rows = ['n1' => ['BNODEID' => 'n1', 'BCAPABILITY' => 'folder_fetch', 'BSTATUS' => 'pending', 'BRESULTREF' => null, 'BERROR' => null]];
        $connection = $this->createStub(Connection::class);
        $connection->method('update')->willReturnCallback(function (string $table, array $data, array $where): int {
            $node = $where['BNODEID'];
            $this->rows[$node] = array_merge($this->rows[$node], $data);

            return 1;
        });
        $connection->method('fetchAllAssociative')->willReturnCallback(fn (): array => array_values($this->rows));

        return new TaskPlanStore($connection, new NullLogger());
    }

    /**
     * @return array<string, mixed>
     */
    private function reloaded(string $nodeId): array
    {
        foreach ((new TaskPlanStore($this->reader(), new NullLogger()))->loadCards(42) as $card) {
            if ($card['nodeId'] === $nodeId) {
                return $card;
            }
        }
        self::fail('The card for '.$nodeId.' was not stored.');
    }

    private function reader(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn(array_values($this->rows));

        return $connection;
    }
}
