<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20260819080000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Executes the retirement migration's real SQL against the test database.
 *
 * DEFAULTMODEL is the weakest of the three places a model BID is stored: the
 * chat pipeline reads a widget's `aiModelId` and a prompt's `aiModel` first.
 * Repointing BCONFIG alone therefore left the two higher-priority bindings
 * aimed at a model Groq had already switched off, which is what made the site
 * fail on the very first message.
 *
 * Everything runs inside a transaction that is rolled back, so the fixture
 * rows never survive the test.
 */
final class Version20260819080000Test extends KernelTestCase
{
    private const RETIRED_CHAT_BID = 9;
    private const SUCCESSOR_CHAT_BID = 324;

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

    public function testItRepointsEveryPlaceAStaleModelIdCanHide(): void
    {
        $this->givenRetiredAndSuccessorModelsExist();

        $ownerId = $this->anyUserId();
        $this->givenDefaultModelBinding($ownerId, 'CHAT', self::RETIRED_CHAT_BID);
        $promptId = $this->givenPromptWithModelOverride($ownerId, self::RETIRED_CHAT_BID);
        $widgetId = $this->givenWidgetWithModelOverride($ownerId, self::RETIRED_CHAT_BID);

        $this->runMigration();

        self::assertSame(
            (string) self::SUCCESSOR_CHAT_BID,
            $this->fetchDefaultModelBinding($ownerId, 'CHAT'),
            'DEFAULTMODEL binding was not repointed'
        );
        self::assertSame(
            (string) self::SUCCESSOR_CHAT_BID,
            $this->fetchPromptModelOverride($promptId),
            'BPROMPTMETA aiModel override was not repointed'
        );
        self::assertSame(
            self::SUCCESSOR_CHAT_BID,
            $this->fetchWidgetModelOverride($widgetId),
            'BWIDGETS aiModelId override was not repointed'
        );
    }

    /**
     * Migrations run again on every container start of every node in the
     * cluster, and a partially applied one has to be safe to repeat.
     */
    public function testItIsIdempotent(): void
    {
        $this->givenRetiredAndSuccessorModelsExist();

        $ownerId = $this->anyUserId();
        $widgetId = $this->givenWidgetWithModelOverride($ownerId, self::RETIRED_CHAT_BID);

        $this->runMigration();
        $this->runMigration();

        self::assertSame(self::SUCCESSOR_CHAT_BID, $this->fetchWidgetModelOverride($widgetId));
        self::assertSame(0, $this->fetchModelActive(self::RETIRED_CHAT_BID));
    }

    /**
     * A widget bound to an unrelated model must come out untouched — including
     * the rest of its config, which JSON_SET would blank if the WHERE clause
     * ever let a malformed row through.
     */
    public function testItLeavesUnrelatedWidgetConfigsAlone(): void
    {
        $this->givenRetiredAndSuccessorModelsExist();

        $ownerId = $this->anyUserId();
        $widgetId = $this->givenWidgetWithModelOverride($ownerId, self::SUCCESSOR_CHAT_BID);

        $this->runMigration();

        $config = json_decode($this->fetchWidgetConfig($widgetId), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(self::SUCCESSOR_CHAT_BID, $config['aiModelId']);
        self::assertSame('#ff0000', $config['primaryColor'], 'unrelated config keys must survive');
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
        require_once dirname(__DIR__, 2).'/migrations/Version20260819080000.php';

        return new Version20260819080000($this->connection, new NullLogger());
    }

    private function givenRetiredAndSuccessorModelsExist(): void
    {
        // The migration inserts the successor itself; the retired row is the
        // one an existing install already carries.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BMODELS (BID, BSERVICE, BNAME, BTAG, BSELECTABLE, BACTIVE, BPROVID, BPRICEIN, BINUNIT, BPRICEOUT, BOUTUNIT, BQUALITY, BRATING, BISDEFAULT, BSHOWWHENFREE, BJSON)
                VALUES (:id, 'Groq', 'Llama 3.3 70b versatile', 'chat', 1, 1, 'llama-3.3-70b-versatile', 0.59, 'per1M', 0.79, 'per1M', 9, 1, 0, 0, '{}')
                ON DUPLICATE KEY UPDATE BACTIVE = 1, BSELECTABLE = 1, BPROVID = 'llama-3.3-70b-versatile'
                SQL,
            ['id' => self::RETIRED_CHAT_BID]
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

    private function givenPromptWithModelOverride(int $ownerId, int $modelId): int
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BPROMPTS (BOWNERID, BLANG, BTOPIC, BSHORTDESC, BPROMPT)
                VALUES (:owner, 'en', 'tools:migration_fixture', 'fixture', 'fixture')
                SQL,
            ['owner' => $ownerId]
        );
        $promptId = (int) $this->connection->lastInsertId();

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BPROMPTMETA (BPROMPTID, BMETAKEY, BMETAVALUE, BCREATED)
                VALUES (:prompt, 'aiModel', :value, :created)
                SQL,
            ['prompt' => $promptId, 'value' => (string) $modelId, 'created' => time()]
        );

        return $promptId;
    }

    private function givenWidgetWithModelOverride(int $ownerId, int $modelId): string
    {
        $widgetId = 'wdg_migration_fixture_'.bin2hex(random_bytes(6));
        $config = json_encode(
            ['primaryColor' => '#ff0000', 'aiModelId' => $modelId],
            \JSON_THROW_ON_ERROR
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO BWIDGETS (BOWNERID, BWIDGETID, BTASKPROMPT, BNAME, BSTATUS, BCONFIG, BALLOWED_DOMAINS, BCREATED, BUPDATED)
                VALUES (:owner, :widgetId, 'general', 'fixture', 'active', :config, '[]', :now, :now)
                SQL,
            ['owner' => $ownerId, 'widgetId' => $widgetId, 'config' => $config, 'now' => time()]
        );

        return $widgetId;
    }

    private function fetchDefaultModelBinding(int $ownerId, string $setting): string
    {
        return (string) $this->connection->fetchOne(
            "SELECT BVALUE FROM BCONFIG WHERE BOWNERID = :owner AND BGROUP = 'DEFAULTMODEL' AND BSETTING = :setting",
            ['owner' => $ownerId, 'setting' => $setting]
        );
    }

    private function fetchPromptModelOverride(int $promptId): string
    {
        return (string) $this->connection->fetchOne(
            "SELECT BMETAVALUE FROM BPROMPTMETA WHERE BPROMPTID = :prompt AND BMETAKEY = 'aiModel'",
            ['prompt' => $promptId]
        );
    }

    private function fetchWidgetModelOverride(string $widgetId): int
    {
        $config = json_decode($this->fetchWidgetConfig($widgetId), true, 512, \JSON_THROW_ON_ERROR);

        return (int) $config['aiModelId'];
    }

    private function fetchWidgetConfig(string $widgetId): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT BCONFIG FROM BWIDGETS WHERE BWIDGETID = :widgetId',
            ['widgetId' => $widgetId]
        );
    }

    private function fetchModelActive(int $modelId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT BACTIVE FROM BMODELS WHERE BID = :id',
            ['id' => $modelId]
        );
    }
}
