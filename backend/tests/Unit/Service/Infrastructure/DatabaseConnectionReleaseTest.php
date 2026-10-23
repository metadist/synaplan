<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Infrastructure;

use App\Service\Infrastructure\DatabaseConnectionRelease;
use App\Tests\Support\StubConnectionRegistry;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class DatabaseConnectionReleaseTest extends TestCase
{
    public function testAnOpenConnectionOutsideATransactionIsClosed(): void
    {
        $open = $this->connection(connected: true, inTransaction: false, expectClose: true);

        $release = new DatabaseConnectionRelease(new StubConnectionRegistry(['default' => $open]), new NullLogger());

        self::assertSame(1, $release->release('chat stream'));
    }

    public function testAConnectionInsideATransactionStaysOpen(): void
    {
        $inTransaction = $this->connection(connected: true, inTransaction: true, expectClose: false);

        $release = new DatabaseConnectionRelease(new StubConnectionRegistry(['default' => $inTransaction]), new NullLogger());

        self::assertSame(0, $release->release('chat stream'));
    }

    public function testAClosedConnectionIsLeftAlone(): void
    {
        $closed = $this->connection(connected: false, inTransaction: false, expectClose: false);

        $release = new DatabaseConnectionRelease(new StubConnectionRegistry(['read' => $closed]), new NullLogger());

        self::assertSame(0, $release->release('chat stream'));
    }

    public function testEveryOpenConnectionOfTheRegistryIsClosed(): void
    {
        $registry = new StubConnectionRegistry([
            'default' => $this->connection(connected: true, inTransaction: false, expectClose: true),
            'read' => $this->connection(connected: true, inTransaction: false, expectClose: true),
            'busy' => $this->connection(connected: true, inTransaction: true, expectClose: false),
        ]);

        self::assertSame(2, (new DatabaseConnectionRelease($registry, new NullLogger()))->release('streamed response ended'));
    }

    public function testTheReleaseIsLoggedWithItsReasonOnlyWhenSomethingWasClosed(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug')
            ->with('Database connections released', ['reason' => 'chat stream', 'closed' => 1]);

        $release = new DatabaseConnectionRelease(
            new StubConnectionRegistry(['default' => $this->connection(connected: true, inTransaction: false, expectClose: true)]),
            $logger,
        );
        $release->release('chat stream');

        $quiet = $this->createMock(LoggerInterface::class);
        $quiet->expects(self::never())->method('debug');
        (new DatabaseConnectionRelease(new StubConnectionRegistry(), $quiet))->release('chat stream');
    }

    private function connection(bool $connected, bool $inTransaction, bool $expectClose): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn($connected);
        $connection->method('isTransactionActive')->willReturn($inTransaction);
        $connection->expects($expectClose ? self::once() : self::never())->method('close');

        return $connection;
    }
}
