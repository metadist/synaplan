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
 * database. Each case forces BIDs 379/380 into the state it needs inside a
 * rolled-back transaction, so the test does not depend on the seeded catalog.
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
        $this->givenRow(self::CHAT_BID, 'chat', 2.0, '{"description":"old"}');
        $this->givenRow(self::VISION_BID, 'pic2text', 2.0, '{"description":"old"}');

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

    public function testAnOperatorInputPriceIsKept(): void
    {
        $this->givenRow(self::CHAT_BID, 'chat', 3.0, '{"description":"operator"}');

        $this->runMigration();

        self::assertSame(['description' => 'operator'], $this->fetchJson(self::CHAT_BID));
    }

    public function testAnAuthoredCacheRateIsKept(): void
    {
        $this->givenRow(self::CHAT_BID, 'chat', 2.0, '{"cache_read_price_per_1M":0.15}');

        $this->runMigration();

        self::assertSame(['cache_read_price_per_1M' => 0.15], $this->fetchJson(self::CHAT_BID));
    }

    public function testItIsIdempotent(): void
    {
        $this->givenRow(self::CHAT_BID, 'chat', 2.0, '{}');

        $this->runMigration();
        $first = $this->fetchJson(self::CHAT_BID);
        $this->runMigration();

        self::assertSame($first, $this->fetchJson(self::CHAT_BID));
    }

    private function givenRow(int $bid, string $tag, float $priceIn, string $json): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BACTIVE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BSHOWWHENFREE, BJSON)
                VALUES (:id, 'Anthropic', 'Claude Sonnet 5.5', :tag, 1, 1, 'claude-sonnet-5-5', :priceIn, 'per1M', 10, 'per1M', 10, 1, 0, 0, :json)
                ON DUPLICATE KEY UPDATE
                    BSERVICE = VALUES(BSERVICE), BTAG = VALUES(BTAG), BPROVID = VALUES(BPROVID),
                    BPRICEIN = VALUES(BPRICEIN), BPRICEOUT = VALUES(BPRICEOUT), BJSON = VALUES(BJSON)
            SQL,
            ['id' => $bid, 'tag' => $tag, 'priceIn' => $priceIn, 'json' => $json]
        );
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
