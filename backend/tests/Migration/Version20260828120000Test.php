<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20260828120000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Executes the sort-model repoint against the test database.
 *
 * The two things worth asserting are the ones a comment cannot enforce: that
 * per-user bindings are covered (they win over the global row, so missing them
 * would leave the affected accounts on the old sorter), and that the guard
 * really holds — this migration overwrites operator data, so it must be inert
 * whenever the target model is not the model we think it is.
 *
 * Everything runs inside a transaction that is rolled back, and each case
 * builds the exact population it needs: the PHPUnit database may or may not
 * carry fixtures, and this test must not depend on which.
 */
final class Version20260828120000Test extends KernelTestCase
{
    private const SORTER_BID = 76;
    private const SORTER_PROVID = 'openai/gpt-oss-120b';

    /** Any other chat model — stands in for "whatever this install had". */
    private const OTHER_BID = 249;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        $this->givenNoSortBindings();
        $this->givenSorterModel(active: true);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItRepointsTheGlobalBinding(): void
    {
        $this->givenSortBinding(ownerId: 0, modelId: self::OTHER_BID);

        $this->runMigration();

        self::assertSame((string) self::SORTER_BID, $this->fetchSortBinding(0));
    }

    /**
     * The expensive miss: a per-user row wins over the global default, so an
     * account that once picked another sorter would keep it forever.
     */
    public function testItRepointsPerUserBindings(): void
    {
        $this->givenSortBinding(ownerId: 0, modelId: self::OTHER_BID);
        $this->givenSortBinding(ownerId: 7, modelId: self::OTHER_BID);
        $this->givenSortBinding(ownerId: 12, modelId: self::OTHER_BID);

        $this->runMigration();

        self::assertSame((string) self::SORTER_BID, $this->fetchSortBinding(7));
        self::assertSame((string) self::SORTER_BID, $this->fetchSortBinding(12));
    }

    /** Only the sorter moves — CHAT and the other capabilities are not ours. */
    public function testItLeavesOtherCapabilitiesAlone(): void
    {
        $this->givenSortBinding(ownerId: 0, modelId: self::OTHER_BID);
        $this->givenBinding(ownerId: 0, setting: 'CHAT', modelId: self::OTHER_BID);

        $this->runMigration();

        self::assertSame((string) self::OTHER_BID, $this->fetchBinding(0, 'CHAT'));
    }

    /**
     * An install that deactivated the Groq row has no Groq account. Binding it
     * there anyway would trade a badly-sorting install for a broken one.
     */
    public function testItSkipsInstallsWhereTheSorterIsDeactivated(): void
    {
        $this->givenSorterModel(active: false);
        $this->givenSortBinding(ownerId: 0, modelId: self::OTHER_BID);

        $this->runMigration();

        self::assertSame((string) self::OTHER_BID, $this->fetchSortBinding(0));
    }

    /** BID 76 repurposed for a different model must not be bound as the sorter. */
    public function testItSkipsInstallsWhereTheRowWasRepurposed(): void
    {
        $this->connection->executeStatement(
            'UPDATE BMODELS SET BPROVID = :providerId WHERE BID = :id',
            ['providerId' => 'some/other-model', 'id' => self::SORTER_BID]
        );
        $this->givenSortBinding(ownerId: 0, modelId: self::OTHER_BID);

        $this->runMigration();

        self::assertSame((string) self::OTHER_BID, $this->fetchSortBinding(0));
    }

    public function testItIsIdempotent(): void
    {
        $this->givenSortBinding(ownerId: 0, modelId: self::OTHER_BID);

        $this->runMigration();
        $this->runMigration();

        self::assertSame((string) self::SORTER_BID, $this->fetchSortBinding(0));
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
        require_once dirname(__DIR__, 2).'/migrations/Version20260828120000.php';

        return new Version20260828120000($this->connection, new NullLogger());
    }

    /**
     * Force BID 76 into the exact shape the guard looks for, whether or not the
     * harness seeded the catalog. Rolled back with the surrounding transaction.
     */
    private function givenSorterModel(bool $active): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BACTIVE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BSHOWWHENFREE, BJSON)
                VALUES (:id, 'Groq', 'gpt-oss-120b', 'chat', 1, :active, :providerId, 0.15, 'per1M', 0.60, 'per1M', 10, 4, 0, 0, '{}')
                ON DUPLICATE KEY UPDATE
                    BSERVICE = VALUES(BSERVICE), BTAG = VALUES(BTAG),
                    BPROVID = VALUES(BPROVID), BACTIVE = VALUES(BACTIVE)
            SQL,
            ['id' => self::SORTER_BID, 'active' => $active ? 1 : 0, 'providerId' => self::SORTER_PROVID]
        );
    }

    private function givenNoSortBindings(): void
    {
        $this->connection->executeStatement(
            "DELETE FROM BCONFIG WHERE BGROUP = 'DEFAULTMODEL' AND BSETTING = 'SORT'"
        );
    }

    private function givenSortBinding(int $ownerId, int $modelId): void
    {
        $this->givenBinding($ownerId, 'SORT', $modelId);
    }

    /** Upsert: BCONFIG is uniquely indexed on (BOWNERID, BGROUP, BSETTING). */
    private function givenBinding(int $ownerId, string $setting, int $modelId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE)
                VALUES (:owner, 'DEFAULTMODEL', :setting, :value)
                ON DUPLICATE KEY UPDATE BVALUE = VALUES(BVALUE)
            SQL,
            ['owner' => $ownerId, 'setting' => $setting, 'value' => (string) $modelId]
        );
    }

    private function fetchSortBinding(int $ownerId): ?string
    {
        return $this->fetchBinding($ownerId, 'SORT');
    }

    private function fetchBinding(int $ownerId, string $setting): ?string
    {
        $value = $this->connection->fetchOne(
            "SELECT BVALUE FROM BCONFIG WHERE BOWNERID = :owner AND BGROUP = 'DEFAULTMODEL' AND BSETTING = :setting",
            ['owner' => $ownerId, 'setting' => $setting]
        );

        return false === $value ? null : (string) $value;
    }
}
