<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Model\CatalogPriceOwnership;
use App\Model\ModelCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261115140000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Executes the #2957 price correction against the test database. Each case
 * seeds the row the way it existed before the correction, inside a rolled-back
 * transaction, then applies the edit an operator might have made.
 */
final class Version20261115140000Test extends KernelTestCase
{
    private const CHAT_BID = 387;

    private const VISION_BID = 388;

    private const SCALEWAY_BID = 426;

    /** @var array<int, string> */
    private const PREVIOUS_DESCRIPTIONS = [
        self::CHAT_BID => 'Mistral Large 4 - open-weight multimodal flagship with reasoning and tool use. 512K context. OpenAI-compatible chat endpoint. Public preview, launch pricing.',
        self::VISION_BID => 'Mistral Large 4 vision - describe images and extract text via the chat endpoint. Same model as the chat row. Public preview, launch pricing.',
    ];

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
        $this->givenPreviousMistralRow(self::CHAT_BID);
        $this->givenPreviousMistralRow(self::VISION_BID);

        $this->runMigration();

        foreach ([self::CHAT_BID, self::VISION_BID] as $bid) {
            $row = $this->fetchRow($bid);
            self::assertEqualsWithDelta(0.70, (float) $row['BPRICEIN'], 1e-9);
            self::assertEqualsWithDelta(2.10, (float) $row['BPRICEOUT'], 1e-9);
            $json = $this->decodeJson($row);
            self::assertEqualsWithDelta(0.07, (float) $json['cache_read_price_per_1M'], 1e-9);

            $catalogRow = $this->catalogRow($bid);
            self::assertSame(ModelCatalog::fingerprint($catalogRow), $json['__catalog_fingerprint'] ?? null);
            unset($json['__catalog_fingerprint']);
            self::assertSame($catalogRow['json'], $json);
        }
    }

    public function testANonPriceAdminEditKeepsTheWholeRow(): void
    {
        $this->givenPreviousMistralRow(self::CHAT_BID);
        $this->connection->executeStatement("UPDATE BMODELS SET BNAME = 'Our Large 4' WHERE BID = :id", ['id' => self::CHAT_BID]);

        $this->runMigration();

        $row = $this->fetchRow(self::CHAT_BID);
        self::assertSame('Our Large 4', $row['BNAME']);
        self::assertEqualsWithDelta(0.68, (float) $row['BPRICEIN'], 1e-9);
        self::assertEqualsWithDelta(0.068, (float) $this->decodeJson($row)['cache_read_price_per_1M'], 1e-9);
    }

    public function testAnOperatorInputPriceIsKept(): void
    {
        $this->givenPreviousMistralRow(self::CHAT_BID);
        $this->connection->executeStatement('UPDATE BMODELS SET BPRICEIN = 1.25 WHERE BID = :id', ['id' => self::CHAT_BID]);

        $this->runMigration();

        self::assertEqualsWithDelta(1.25, (float) $this->fetchRow(self::CHAT_BID)['BPRICEIN'], 1e-9);
    }

    public function testALegacyRowThatMatchesThePreviousSnapshotIsCorrected(): void
    {
        $this->givenPreviousMistralRow(self::CHAT_BID);
        $this->connection->executeStatement(
            "UPDATE BMODELS SET BJSON = JSON_REMOVE(BJSON, '$.__catalog_fingerprint') WHERE BID = :id",
            ['id' => self::CHAT_BID],
        );

        $this->runMigration();

        self::assertEqualsWithDelta(0.70, (float) $this->fetchRow(self::CHAT_BID)['BPRICEIN'], 1e-9);
    }

    public function testVisibilityFlagsSurviveTheCorrection(): void
    {
        $this->givenPreviousMistralRow(self::CHAT_BID);
        $this->connection->executeStatement(
            'UPDATE BMODELS SET BSELECTABLE = 0, BACTIVE = 0 WHERE BID = :id',
            ['id' => self::CHAT_BID],
        );

        $this->runMigration();

        $row = $this->fetchRow(self::CHAT_BID);
        self::assertSame(0, (int) $row['BSELECTABLE']);
        self::assertSame(0, (int) $row['BACTIVE']);
        self::assertEqualsWithDelta(0.70, (float) $row['BPRICEIN'], 1e-9);
    }

    public function testAChangeAfterTheReadIsNotOverwritten(): void
    {
        $this->givenPreviousMistralRow(self::CHAT_BID);
        $migration = $this->loadMigration();
        $migration->up(new Schema());
        $this->connection->executeStatement(
            "UPDATE BMODELS SET BJSON = JSON_SET(BJSON, '$.description', 'edited after the read') WHERE BID = :id",
            ['id' => self::CHAT_BID],
        );

        $this->executeQueued($migration);

        $json = $this->decodeJson($this->fetchRow(self::CHAT_BID));
        self::assertSame('edited after the read', $json['description']);
        self::assertEqualsWithDelta(0.68, (float) $this->fetchRow(self::CHAT_BID)['BPRICEIN'], 1e-9);
    }

    public function testItIsIdempotent(): void
    {
        $this->givenPreviousMistralRow(self::CHAT_BID);

        $this->runMigration();
        $first = $this->fetchRow(self::CHAT_BID);
        $this->runMigration();

        self::assertSame($first, $this->fetchRow(self::CHAT_BID));
    }

    public function testASyncedEuroPriceIsRestoredToTheCatalogDollarRate(): void
    {
        $this->givenSyncedEuroScalewayRow();

        $this->runMigration();

        $row = $this->fetchRow(self::SCALEWAY_BID);
        self::assertEqualsWithDelta(0.4466, (float) $row['BPRICEIN'], 1e-9);
        self::assertEqualsWithDelta(0.8932, (float) $row['BPRICEOUT'], 1e-9);
        $json = $this->decodeJson($row);
        self::assertEqualsWithDelta(0.0893, (float) $json['cache_read_price_per_1M'], 1e-9);
        self::assertArrayNotHasKey('__price_owner', $json);
        $catalogRow = $this->catalogRow(self::SCALEWAY_BID);
        self::assertSame(ModelCatalog::fingerprint($catalogRow), $json['__catalog_fingerprint'] ?? null);
    }

    public function testAnAdminScalewayPriceIsKept(): void
    {
        $row = $this->catalogRow(self::SCALEWAY_BID);
        $row['priceIn'] = 0.50;
        ModelCatalog::upsert($this->connection, $row);

        $this->runMigration();

        self::assertEqualsWithDelta(0.50, (float) $this->fetchRow(self::SCALEWAY_BID)['BPRICEIN'], 1e-9);
    }

    public function testARenamedSyncedScalewayRowIsKept(): void
    {
        $this->givenSyncedEuroScalewayRow();
        $this->connection->executeStatement(
            "UPDATE BMODELS SET BNAME = 'Our Flash' WHERE BID = :id",
            ['id' => self::SCALEWAY_BID],
        );

        $this->runMigration();

        $row = $this->fetchRow(self::SCALEWAY_BID);
        self::assertSame('Our Flash', $row['BNAME']);
        self::assertEqualsWithDelta(0.40, (float) $row['BPRICEIN'], 1e-9);
    }

    private function givenPreviousMistralRow(int $bid): void
    {
        $previous = $this->catalogRow($bid);
        $previous['priceIn'] = 0.68;
        $previous['priceOut'] = 2.09;
        $previous['json']['cache_read_price_per_1M'] = 0.068;
        $previous['json']['description'] = self::PREVIOUS_DESCRIPTIONS[$bid];
        ModelCatalog::upsert($this->connection, $previous);
    }

    private function givenSyncedEuroScalewayRow(): void
    {
        $catalog = $this->catalogRow(self::SCALEWAY_BID);
        $live = $catalog;
        $live['priceIn'] = 0.40;
        $live['priceOut'] = 0.80;
        $live['json']['cache_read_price_per_1M'] = 0.08;
        ModelCatalog::upsert($this->connection, CatalogPriceOwnership::stamp($live, $catalog));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRow(int $bid): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT BNAME, BPRICEIN, BPRICEOUT, BSELECTABLE, BACTIVE, BJSON FROM BMODELS WHERE BID = :id',
            ['id' => $bid],
        );
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decodeJson(array $row): array
    {
        $decoded = json_decode((string) $row['BJSON'], true);
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
        $this->executeQueued($migration);
    }

    private function executeQueued(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement(
                $query->getStatement(),
                $query->getParameters(),
                $query->getTypes()
            );
        }
    }

    private function loadMigration(): AbstractMigration
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261115140000.php';

        return new Version20261115140000($this->connection, new NullLogger());
    }
}
