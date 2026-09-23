<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20260923180000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Executes the Grok 4.7 / Muse Spark catalog insert against the test database.
 *
 * The migration writes inside up() (no deferred getSql() queue). A second run
 * must not overwrite operator flags on a row that already exists.
 */
final class Version20260923180000Test extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItInsertsTheFourNewRowsAsActive(): void
    {
        $this->deleteNewRows();

        $this->runUp();

        $rows = $this->fetchRows();
        self::assertSame(['grok-4.7', 'grok-4.7', 'muse-spark-1.3', 'muse-spark-1.3'], array_column($rows, 'BPROVID'));
        foreach ($rows as $row) {
            self::assertSame(1, (int) $row['BACTIVE']);
            self::assertSame(1, (int) $row['BSELECTABLE']);
        }
    }

    public function testASecondRunKeepsAnOperatorFlag(): void
    {
        $this->deleteNewRows();
        $this->runUp();

        $this->connection->executeStatement(
            'UPDATE BMODELS SET BSELECTABLE = 0 WHERE BID = 367',
        );

        $this->runUp();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT BSELECTABLE FROM BMODELS WHERE BID = 367'));
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM BMODELS WHERE BID IN (367, 368, 369, 370)'));
    }

    private function runUp(): void
    {
        $this->loadMigration()->up(new Schema());
    }

    private function loadMigration(): AbstractMigration
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20260923180000.php';

        return new Version20260923180000($this->connection, new NullLogger());
    }

    private function deleteNewRows(): void
    {
        $this->connection->executeStatement('DELETE FROM BMODELS WHERE BID IN (367, 368, 369, 370)');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRows(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT BID, BPROVID, BACTIVE, BSELECTABLE FROM BMODELS WHERE BID IN (367, 368, 369, 370) ORDER BY BID ASC',
        );
    }
}
