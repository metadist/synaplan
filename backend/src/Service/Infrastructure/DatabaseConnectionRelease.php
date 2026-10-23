<?php

declare(strict_types=1);

namespace App\Service\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ConnectionRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gives this PHP worker's database connections back while it waits on
 * something that is not the database: an AI provider's stream, or the next
 * request after a stream has ended.
 *
 * A FrankenPHP worker keeps its Doctrine connections between requests, so a
 * server holds one connection per worker that ever served a request (two when
 * the read connection was used) on the shared database. Only an open
 * connection outside a transaction is closed; Doctrine reconnects on the next
 * query, so nothing that runs afterwards has to know.
 */
final readonly class DatabaseConnectionRelease
{
    public function __construct(
        #[Autowire(service: 'doctrine')]
        private ConnectionRegistry $doctrine,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $reason why the worker waits, for the debug log
     *
     * @return int how many connections were closed
     */
    public function release(string $reason): int
    {
        $closed = 0;
        foreach ($this->doctrine->getConnections() as $connection) {
            if (!$connection instanceof Connection || !$connection->isConnected() || $connection->isTransactionActive()) {
                continue;
            }
            $connection->close();
            ++$closed;
        }

        if ($closed > 0) {
            $this->logger->debug('Database connections released', ['reason' => $reason, 'closed' => $closed]);
        }

        return $closed;
    }
}
