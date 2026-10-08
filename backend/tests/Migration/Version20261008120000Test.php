<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Model\ModelCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261008120000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Executes the Claude Sonnet 5.5 cache-read correction against the test
 * database. Each case seeds BIDs 379/380 the way ModelSeeder did before the
 * correction (previous catalog snapshot plus its fingerprint) inside a
 * rolled-back transaction, then applies the edit an operator might have made.
 */
final class Version20261008120000Test extends KernelTestCase
{
    private const CHAT_BID = 379;
    private const VISION_BID = 380;

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

    public function testItAuthorsTheRateWithTheFingerprintTheSeederExpects(): void
    {
        $this->givenPreviousCatalogRow(self::CHAT_BID);
        $this->givenPreviousCatalogRow(self::VISION_BID);

        $this->runMigration();

        foreach ([self::CHAT_BID, self::VISION_BID] as $bid) {
            $json = $this->fetchJson($bid);
            self::assertEqualsWithDelta(0.10, (float) $json['cache_read_price_per_1M'], 1e-9);

            $catalogRow = $this->catalogRow($bid);
            self::assertSame(ModelCatalog::fingerprint($catalogRow), $json['__catalog_fingerprint'] ?? null);
            unset($json['__catalog_fingerprint']);
            self::assertSame($catalogRow['json'], $json, 'The snapshot must equal the catalog row.');
        }
    }

    public function testANonPriceAdminEditKeepsTheWholeRow(): void
    {
        $this->givenPreviousCatalogRow(self::CHAT_BID);
        $this->connection->executeStatement("UPDATE BMODELS SET BNAME = 'Our Sonnet' WHERE BID = :id", ['id' => self::CHAT_BID]);

        $this->runMigration();

        self::assertSame('Our Sonnet', $this->connection->fetchOne('SELECT BNAME FROM BMODELS WHERE BID = :id', ['id' => self::CHAT_BID]));
        self::assertArrayNotHasKey('cache_read_price_per_1M', $this->fetchJson(self::CHAT_BID));
    }

    public function testAnOperatorInputPriceIsKept(): void
    {
        $this->givenPreviousCatalogRow(self::CHAT_BID);
        $this->connection->executeStatement('UPDATE BMODELS SET BPRICEIN = 3 WHERE BID = :id', ['id' => self::CHAT_BID]);

        $this->runMigration();

        self::assertEqualsWithDelta(3.0, (float) $this->connection->fetchOne('SELECT BPRICEIN FROM BMODELS WHERE BID = :id', ['id' => self::CHAT_BID]), 1e-9);
        self::assertArrayNotHasKey('cache_read_price_per_1M', $this->fetchJson(self::CHAT_BID));
    }

    public function testAnAuthoredCacheRateIsKept(): void
    {
        $this->givenPreviousCatalogRow(self::CHAT_BID);
        $this->connection->executeStatement(
            "UPDATE BMODELS SET BJSON = JSON_SET(BJSON, '$.cache_read_price_per_1M', 0.15) WHERE BID = :id",
            ['id' => self::CHAT_BID],
        );

        $this->runMigration();

        self::assertEqualsWithDelta(0.15, (float) $this->fetchJson(self::CHAT_BID)['cache_read_price_per_1M'], 1e-9);
    }

    public function testALegacyRowThatMatchesThePreviousSnapshotIsCorrected(): void
    {
        $this->givenPreviousCatalogRow(self::CHAT_BID);
        $this->connection->executeStatement(
            "UPDATE BMODELS SET BJSON = JSON_REMOVE(BJSON, '$.__catalog_fingerprint') WHERE BID = :id",
            ['id' => self::CHAT_BID],
        );

        $this->runMigration();

        self::assertEqualsWithDelta(0.10, (float) $this->fetchJson(self::CHAT_BID)['cache_read_price_per_1M'], 1e-9);
    }

    public function testItIsIdempotent(): void
    {
        $this->givenPreviousCatalogRow(self::CHAT_BID);

        $this->runMigration();
        $first = $this->fetchJson(self::CHAT_BID);
        $this->runMigration();

        self::assertSame($first, $this->fetchJson(self::CHAT_BID));
    }

    /**
     * The row as ModelSeeder wrote it before the correction: the current
     * catalog row without the cache-read key, plus its fingerprint.
     */
    private function givenPreviousCatalogRow(int $bid): void
    {
        $previous = $this->catalogRow($bid);
        unset($previous['json']['cache_read_price_per_1M']);
        ModelCatalog::upsert($this->connection, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJson(int $bid): array
    {
        $raw = $this->connection->fetchOne('SELECT BJSON FROM BMODELS WHERE BID = :id', ['id' => $bid]);
        $decoded = json_decode((string) $raw, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogRow(int $bid): array
    {
        foreach (ModelCatalog::all() as $row) {
            if ($bid === $row['id']) {
                return $row;
            }
        }
        self::fail('BID '.$bid.' is not in ModelCatalog');
    }

    private function runMigration(): void
    {
        $migration = $this->loadMigration();
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement(
                $query->getStatement(),
                $query->getParameters(),
                $query->getTypes()
            );
        }
    }

    /**
     * migrations/ is outside the composer autoload map — Doctrine discovers
     * these files by path at runtime, so the test has to load it itself.
     */
    private function loadMigration(): AbstractMigration
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261008120000.php';

        return new Version20261008120000($this->connection, new NullLogger());
    }
}
