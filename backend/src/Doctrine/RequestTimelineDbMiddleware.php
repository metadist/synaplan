<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Observability\RequestTimelineContext;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;

/**
 * Doctrine DBAL middleware that measures query count and duration (`db_ms`,
 * `db_queries`) into {@see RequestTimelineContext} (`04_capacity_and_kubernetes.md`
 * §3, S1 AU13). Never inspects SQL text or bound parameters.
 */
#[AsMiddleware]
final readonly class RequestTimelineDbMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestTimelineContext $timeline,
    ) {
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        $timeline = $this->timeline;

        return new class($driver, $timeline) extends AbstractDriverMiddleware {
            public function __construct(
                DriverInterface $driver,
                private readonly RequestTimelineContext $timeline,
            ) {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): ConnectionInterface
            {
                $conn = parent::connect($params);
                $timeline = $this->timeline;

                return new class($conn, $timeline) extends AbstractConnectionMiddleware {
                    public function __construct(
                        ConnectionInterface $connection,
                        private readonly RequestTimelineContext $timeline,
                    ) {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): StatementInterface
                    {
                        $stmt = parent::prepare($sql);
                        $timeline = $this->timeline;

                        return new class($stmt, $timeline) extends AbstractStatementMiddleware {
                            public function __construct(
                                StatementInterface $statement,
                                private readonly RequestTimelineContext $timeline,
                            ) {
                                parent::__construct($statement);
                            }

                            public function execute(): ResultInterface
                            {
                                $start = hrtime(true);
                                try {
                                    return parent::execute();
                                } finally {
                                    $this->timeline->recordDbQuery((hrtime(true) - $start) / 1_000_000.0);
                                }
                            }
                        };
                    }

                    public function query(string $sql): ResultInterface
                    {
                        $start = hrtime(true);
                        try {
                            return parent::query($sql);
                        } finally {
                            $this->timeline->recordDbQuery((hrtime(true) - $start) / 1_000_000.0);
                        }
                    }

                    public function exec(string $sql): int|string
                    {
                        $start = hrtime(true);
                        try {
                            return parent::exec($sql);
                        } finally {
                            $this->timeline->recordDbQuery((hrtime(true) - $start) / 1_000_000.0);
                        }
                    }
                };
            }
        };
    }
}
