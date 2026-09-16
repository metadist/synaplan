<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20260820120000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Executes the xAI speech-retirement migration's real SQL against the test
 * database.
 *
 * This is the NO-SUCCESSOR variant of the retirement policy: xAI serves no
 * replacement for grok-stt/grok-tts, so the bindings are deleted rather than
 * repointed. Deleting the wrong row would silently take a working speech model
 * away from an install, so the guards are asserted here, not just described.
 *
 * Everything runs inside a transaction that is rolled back, so the fixture rows
 * never survive the test.
 */
final class Version20260820120000Test extends KernelTestCase
{
    private const RETIRED_STT_BID = 321;
    private const RETIRED_STT_PROVIDER_ID = 'grok-stt';

    /** Any live model of the same capability, used as the "untouched" control. */
    private const UNRELATED_BID = 322;

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

    public function testItDeactivatesTheRetiredRowAndDropsItsBinding(): void
    {
        $this->givenModelRow(self::RETIRED_STT_BID, self::RETIRED_STT_PROVIDER_ID);
        $ownerId = $this->anyUserId();
        $this->givenDefaultModelBinding($ownerId, 'SOUND2TEXT', self::RETIRED_STT_BID);

        $this->runMigration();

        self::assertSame(0, $this->fetchModelActive(self::RETIRED_STT_BID));
        self::assertSame(0, $this->fetchModelSelectable(self::RETIRED_STT_BID));
        self::assertNull(
            $this->fetchDefaultModelBinding($ownerId, 'SOUND2TEXT'),
            'the binding must be gone so resolution falls back to a live model'
        );
    }

    /**
     * Migrations run again on every container start of every node in the
     * cluster, and a partially applied one has to be safe to repeat.
     */
    public function testItIsIdempotent(): void
    {
        $this->givenModelRow(self::RETIRED_STT_BID, self::RETIRED_STT_PROVIDER_ID);
        $ownerId = $this->anyUserId();
        $this->givenDefaultModelBinding($ownerId, 'SOUND2TEXT', self::RETIRED_STT_BID);

        $this->runMigration();
        $this->runMigration();

        self::assertSame(0, $this->fetchModelActive(self::RETIRED_STT_BID));
        self::assertNull($this->fetchDefaultModelBinding($ownerId, 'SOUND2TEXT'));
    }

    /**
     * The BPROVID guard: a BID an operator repurposed for a different upstream
     * model is not the retired model and must keep working.
     */
    public function testItLeavesARepurposedRowActive(): void
    {
        $this->givenModelRow(self::RETIRED_STT_BID, 'some-other-upstream-model');

        $this->runMigration();

        self::assertSame(1, $this->fetchModelActive(self::RETIRED_STT_BID));
    }

    public function testItLeavesBindingsForOtherModelsAlone(): void
    {
        $this->givenModelRow(self::RETIRED_STT_BID, self::RETIRED_STT_PROVIDER_ID);
        $this->givenModelRow(self::UNRELATED_BID, 'whisper-large-v3');
        $ownerId = $this->anyUserId();
        $this->givenDefaultModelBinding($ownerId, 'SOUND2TEXT', self::UNRELATED_BID);

        $this->runMigration();

        self::assertSame(
            (string) self::UNRELATED_BID,
            $this->fetchDefaultModelBinding($ownerId, 'SOUND2TEXT'),
            'a binding pointing at a live model must survive'
        );
        self::assertSame(1, $this->fetchModelActive(self::UNRELATED_BID));
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
        require_once dirname(__DIR__, 2).'/migrations/Version20260820120000.php';

        return new Version20260820120000($this->connection, new NullLogger());
    }

    private function givenModelRow(int $bid, string $providerId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BACTIVE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BSHOWWHENFREE, BJSON)
                VALUES (:id, 'xAI', 'migration fixture', 'sound2text', 1, 1, :providerId, 0.10, 'perhour', 0, '-', 9, 1, 0, 0, '{}')
                ON DUPLICATE KEY UPDATE BACTIVE = 1, BSELECTABLE = 1, BPROVID = VALUES(BPROVID)
                SQL,
            ['id' => $bid, 'providerId' => $providerId]
        );
    }

    private function anyUserId(): int
    {
        $userId = $this->connection->fetchOne('SELECT BID FROM BUSER ORDER BY BID ASC LIMIT 1');
        self::assertNotFalse($userId, 'test database has no user to attach fixtures to');

        return (int) $userId;
    }

    private function givenDefaultModelBinding(int $ownerId, string $setting, int $modelId): void
    {
        $this->connection->executeStatement(
            "DELETE FROM BCONFIG WHERE BOWNERID = :owner AND BGROUP = 'DEFAULTMODEL' AND BSETTING = :setting",
            ['owner' => $ownerId, 'setting' => $setting]
        );
        $this->connection->executeStatement(
            "INSERT INTO BCONFIG (BOWNERID, BGROUP, BSETTING, BVALUE) VALUES (:owner, 'DEFAULTMODEL', :setting, :value)",
            ['owner' => $ownerId, 'setting' => $setting, 'value' => (string) $modelId]
        );
    }

    private function fetchDefaultModelBinding(int $ownerId, string $setting): ?string
    {
        $value = $this->connection->fetchOne(
            "SELECT BVALUE FROM BCONFIG WHERE BOWNERID = :owner AND BGROUP = 'DEFAULTMODEL' AND BSETTING = :setting",
            ['owner' => $ownerId, 'setting' => $setting]
        );

        return false === $value ? null : (string) $value;
    }

    private function fetchModelActive(int $modelId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT BACTIVE FROM BMODELS WHERE BID = :id',
            ['id' => $modelId]
        );
    }

    private function fetchModelSelectable(int $modelId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT BSELECTABLE FROM BMODELS WHERE BID = :id',
            ['id' => $modelId]
        );
    }
}
